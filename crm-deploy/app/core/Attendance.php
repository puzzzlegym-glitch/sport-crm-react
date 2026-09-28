<?php
/**
 * Attendance.php — Спільна логіка відвідувань і нарахувань тренерам
 *
 * Виокремлено з visits_api.php, щоб та сама логіка (запис відвідування,
 * персональне нарахування тренеру) була доступна й іншим API-файлам —
 * зокрема group_sessions_api.php для групових занять.
 *
 * Використання:
 *   Attendance::recordVisit($pdo, $clubId, $clientId, $invoiceId, $trainerId, $trainerName, 'manual', $sess);
 *   Attendance::createTrainerEarning($pdo, $clubId, $visitId, $invoiceId, $trainerId);
 *   Attendance::createGroupSessionEarning($pdo, $clubId, $sessionId);
 *   Attendance::findActiveInvoice($pdo, $clubId, $clientId, 'group');
 */

class Attendance
{
    /**
     * Записує відвідування в `visits`. Recalc::invoiceVisitsUsed() рахує
     * client_invoices.visits_used наново (COUNT(*)) одразу після запису —
     * потрібно синхронно, до перевірки вичерпання ліміту нижче.
     */
    public static function recordVisit(
        PDO     $pdo,
        int     $clubId,
        int     $clientId,
        ?int    $invoiceId,
        ?int    $trainerId,
        ?string $trainerName,
        string  $method,
        array   $sess,
        ?string $notes = null,
        ?string $visitedAt = null
    ): int {
        $pdo->prepare("
            INSERT INTO visits
                (club_id, client_id, invoice_id,
                 method, admin_id, admin_name,
                 trainer_id, trainer_name,
                 notes, created_by, visited_at)
            VALUES (?,?,?, ?,?,?, ?,?, ?,?, COALESCE(?, NOW()))
        ")->execute([
            $clubId, $clientId, $invoiceId,
            $method, $sess['user_id'], $sess['full_name'] ?? null,
            $trainerId, $trainerName,
            $notes, $sess['user_id'], $visitedAt,
        ]);

        $visitId = (int)$pdo->lastInsertId();

        if ($invoiceId) {
            Recalc::invoiceVisitsUsed($pdo, $invoiceId);

            $pdo->prepare("
                UPDATE client_invoices
                SET status = 'expired'
                WHERE id = ?
                  AND visits_total IS NOT NULL
                  AND visits_used >= visits_total
            ")->execute([$invoiceId]);
            Recalc::unlockInvoiceTrainerEarnings($pdo, $invoiceId);
        }

        return $visitId;
    }

    /**
     * Нараховує тренеру за персональне відвідування. Джерело правила —
     * ВИКЛЮЧНО профіль тренера (club_trainers.personal_earn_*). Оплата
     * абонемента на це жодним чином не впливає.
     */
    public static function createTrainerEarning(
        PDO  $pdo,
        int  $clubId,
        int  $visitId,
        ?int $invoiceId,
        ?int $trainerId
    ): void {
        if (!$invoiceId || !$trainerId) return;

        $invStmt = $pdo->prepare("
            SELECT ci.price, ci.visits_total, ci.visits_used, ci.end_date,
                   t.has_trainer, t.earn_release_trigger
            FROM client_invoices ci
            LEFT JOIN tariffs t ON t.id = ci.tariff_id
            WHERE ci.id = ? AND ci.club_id = ? LIMIT 1
        ");
        $invStmt->execute([$invoiceId, $clubId]);
        $inv = $invStmt->fetch();
        if (!$inv || !$inv['has_trainer']) return; // тариф не позначено "з тренером"
        if (!$inv['visits_total']) return; // безлімітний по відвідуваннях — нема бази для нарахування

        $baseAmount = round((float)$inv['price'] / (int)$inv['visits_total'], 2);

        $trStmt = $pdo->prepare("
            SELECT personal_earn_type, personal_earn_value,
                   personal_tier_threshold, personal_tier_value
            FROM club_trainers WHERE id = ? AND club_id = ? LIMIT 1
        ");
        $trStmt->execute([$trainerId, $clubId]);
        $tr = $trStmt->fetch();
        if (!$tr) return;

        $earnType = $tr['personal_earn_type'] === 'fixed' ? 'personal_fixed' : 'personal_percent';
        $value    = (float)$tr['personal_earn_value'];

        // Тир: якщо у тренера більше personal_tier_threshold активних персональних
        // клієнтів — застосовується підвищена ставка personal_tier_value.
        if ((int)$tr['personal_tier_threshold'] > 0) {
            $cntStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT client_id) FROM client_invoices
                WHERE trainer_id = ? AND club_id = ? AND status = 'active'
            ");
            $cntStmt->execute([$trainerId, $clubId]);
            $activeClients = (int)$cntStmt->fetchColumn();
            if ($activeClients > (int)$tr['personal_tier_threshold']) {
                $value = (float)$tr['personal_tier_value'];
            }
        }

        $amount = $earnType === 'personal_percent'
            ? round($baseAmount * $value / 100, 2)
            : round($value, 2);
        if ($amount <= 0) return;

        $releaseTrigger = $inv['earn_release_trigger'] ?: 'on_each_visit';
        $completedNow = ($releaseTrigger === 'on_visits_done' && (int)$inv['visits_used'] >= (int)$inv['visits_total'])
            || ($releaseTrigger === 'on_end_date' && $inv['end_date'] < date('Y-m-d'));
        $immediate = in_array($releaseTrigger, ['on_each_visit', 'on_sale'], true) || $completedNow;

        try {
            $pdo->prepare("
                INSERT INTO trainer_earnings
                    (club_id, trainer_id, source, source_id, invoice_id,
                     earn_type, amount, available_amount, paid_amount,
                     release_trigger, available_at, status)
                VALUES (?,?,'visit',?,?, ?,?,?,0, ?,?,?)
            ")->execute([
                $clubId, $trainerId, $visitId, $invoiceId,
                $earnType, $amount, $immediate ? $amount : 0,
                $releaseTrigger, $immediate ? date('Y-m-d H:i:s') : null,
                $immediate ? 'available' : 'locked',
            ]);
        } catch (PDOException $e) {
            // Дублікат (source, source_id) — нарахування вже існує. Ігноруємо,
            // щоб не подвоїти заробіток.
            if ($e->getCode() !== '23000') throw $e;
        }
    }

    /**
     * Нараховує тренеру за проведене групове заняття — один раз при
     * завершенні заняття (group_sessions.status -> 'completed').
     * Джерело правила — club_trainers.group_earn_rate/group_earn_bonus_per_client/
     * group_bonus_threshold. Бонус за учасника застосовується до ВСІХ
     * присутніх, щойно їх кількість досягає порогу (не маржинально) —
     * узгоджено з формулюванням у TrainersPage.jsx ("від N осіб" / "з першого").
     */
    public static function createGroupSessionEarning(PDO $pdo, int $clubId, int $sessionId): void
    {
        $sStmt = $pdo->prepare("SELECT trainer_id FROM group_sessions WHERE id=? AND club_id=? LIMIT 1");
        $sStmt->execute([$sessionId, $clubId]);
        $trainerId = (int)($sStmt->fetchColumn() ?: 0);
        if (!$trainerId) return;

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM group_session_clients WHERE session_id=? AND status='attended'");
        $cntStmt->execute([$sessionId]);
        $attended = (int)$cntStmt->fetchColumn();

        $trStmt = $pdo->prepare("
            SELECT group_earn_rate, group_earn_bonus_per_client, group_bonus_threshold
            FROM club_trainers WHERE id=? AND club_id=? LIMIT 1
        ");
        $trStmt->execute([$trainerId, $clubId]);
        $tr = $trStmt->fetch();
        if (!$tr) return;

        $rate      = (float)$tr['group_earn_rate'];
        $bonus     = (float)$tr['group_earn_bonus_per_client'];
        $threshold = (int)$tr['group_bonus_threshold'];

        $amount = $rate + ($attended >= $threshold && $bonus > 0 ? $bonus * $attended : 0);
        $amount = round($amount, 2);
        if ($amount <= 0) return;

        try {
            $pdo->prepare("
                INSERT INTO trainer_earnings
                    (club_id, trainer_id, source, source_id, invoice_id,
                     earn_type, amount, available_amount, paid_amount,
                     release_trigger, available_at, status)
                VALUES (?,?,'group_session',?,NULL, 'group_session',?,?,0, 'on_session_complete',?,'available')
            ")->execute([
                $clubId, $trainerId, $sessionId,
                $amount, $amount,
                date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            // Дублікат (source, source_id) — заняття вже завершували раніше.
            if ($e->getCode() !== '23000') throw $e;
        }
    }

    /**
     * Які абонементи (tariffs.coverage) можуть покрити відвідування певного виду —
     * у порядку пріоритету. Абонемент без тарифу (перенесений зі старої CRM) = 'all'.
     *   gym      — прохід у зал сканером / ручна відмітка без тренера:
     *              спершу зал/універсальний; персональний лише як запасний варіант
     *              (клієнт лише з персональним приходить саме на персональне)
     *   group    — групове заняття: групові/універсальний; персональний — НІКОЛИ
     *   personal — відмітка з тренером: персональний, потім універсальний/зал
     */
    public const SERVICE_COVERAGE = [
        'gym'      => [['gym', 'all'], ['personal']],
        'group'    => [['group', 'all']],
        'personal' => [['personal'], ['all', 'gym']],
    ];

    public const SERVICE_LABELS = [
        'gym' => 'зал', 'group' => 'групові заняття', 'personal' => 'персональні тренування',
    ];

    /**
     * Активний абонемент клієнта (діє сьогодні), що покриває вид послуги $service.
     * Серед придатних — спершу вищий пріоритет покриття, потім той, що закінчується
     * раніше (щоб заняття не згоріли). Повертає також coverage і is_fallback
     * (true — узято запасний варіант, напр. персональний при проході сканером).
     */
    public static function findActiveInvoice(PDO $pdo, int $clubId, int $clientId, string $service = 'gym'): ?array
    {
        $tiers = self::SERVICE_COVERAGE[$service] ?? self::SERVICE_COVERAGE['gym'];
        $stmt = $pdo->prepare("
            SELECT ci.id, ci.tariff_name, ci.end_date, ci.trainer_id, ci.trainer_name,
                   ci.visits_total, ci.visits_used,
                   DATEDIFF(ci.end_date, CURDATE()) AS days_left,
                   COALESCE(t.coverage, 'all') AS coverage
            FROM client_invoices ci
            LEFT JOIN tariffs t ON t.id = ci.tariff_id
            WHERE ci.client_id = ? AND ci.club_id = ? AND ci.status = 'active'
              AND ci.start_date <= CURDATE() AND ci.end_date >= CURDATE()
            ORDER BY ci.end_date ASC, ci.id ASC
        ");
        $stmt->execute([$clientId, $clubId]);
        $rows = $stmt->fetchAll();
        foreach ($tiers as $i => $allowed) {
            foreach ($rows as $r) {
                if (in_array($r['coverage'], $allowed, true)) {
                    $r['is_fallback'] = $i > 0;
                    return $r;
                }
            }
        }
        return null;
    }

    /** Чи є в клієнта активний абонемент, що НЕ покриває цю послугу (для підказки адміну). */
    public static function hasOtherActiveInvoice(PDO $pdo, int $clubId, int $clientId): bool
    {
        $st = $pdo->prepare("
            SELECT 1 FROM client_invoices
            WHERE client_id = ? AND club_id = ? AND status = 'active'
              AND start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1
        ");
        $st->execute([$clientId, $clubId]);
        return (bool)$st->fetchColumn();
    }
}

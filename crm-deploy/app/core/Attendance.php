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
 *   Attendance::findActiveInvoice($pdo, $clubId, $clientId);
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
            SELECT ci.price, ci.paid_amount, ci.visits_total, ci.visits_used, ci.end_date,
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
                  AND start_date <= CURDATE() AND end_date >= CURDATE()
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
        // Доступно лише коли абонемент повністю оплачений (інакше — 'locked', розблокує
        // Recalc::unlockInvoiceTrainerEarnings після оплати)
        $invoicePaid = (float)$inv['paid_amount'] >= (float)$inv['price'] - 0.01;
        $immediate = $invoicePaid && (in_array($releaseTrigger, ['on_each_visit', 'on_sale'], true) || $completedNow);

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
     * Нарахування тренеру за групове заняття (ставка + бонус за учасника).
     * Рахуються ЛИШЕ присутні, у кого заняття списане (visit з абонементом) і абонемент
     * ПОВНІСТЮ ОПЛАЧЕНИЙ. Немає жодного такого — нарахування немає.
     * Ідемпотентно: викликається при завершенні заняття і повторно після оплати
     * абонемента когось із присутніх (Recalc::unlockInvoiceTrainerEarnings) —
     * сума оновлюється, але не менше вже виплаченого.
     */
    public static function recalcGroupSessionEarning(PDO $pdo, int $clubId, int $sessionId): void
    {
        $sStmt = $pdo->prepare("SELECT trainer_id, status, kind FROM group_sessions WHERE id=? AND club_id=? LIMIT 1");
        $sStmt->execute([$sessionId, $clubId]);
        $s = $sStmt->fetch();
        if (!$s || $s['status'] !== 'completed' || ($s['kind'] ?? 'group') === 'personal') return;
        $trainerId = (int)$s['trainer_id'];

        $cntStmt = $pdo->prepare("
            SELECT COUNT(*) FROM group_session_clients gsc
            JOIN client_invoices ci ON ci.id = gsc.invoice_id
            WHERE gsc.session_id=? AND gsc.status='attended' AND gsc.visit_id IS NOT NULL
              AND ci.paid_amount >= ci.price - 0.01
        ");
        $cntStmt->execute([$sessionId]);
        $counted = (int)$cntStmt->fetchColumn();

        $amount = 0.0;
        if ($counted > 0) {
            $trStmt = $pdo->prepare("SELECT group_earn_rate, group_earn_bonus_per_client, group_bonus_threshold FROM club_trainers WHERE id=? AND club_id=? LIMIT 1");
            $trStmt->execute([$trainerId, $clubId]);
            $tr = $trStmt->fetch();
            if (!$tr) return;
            $bonus = (float)$tr['group_earn_bonus_per_client'];
            $amount = round((float)$tr['group_earn_rate']
                + ($counted >= (int)$tr['group_bonus_threshold'] && $bonus > 0 ? $bonus * $counted : 0), 2);
        }

        $ex = $pdo->prepare("SELECT id, paid_amount FROM trainer_earnings WHERE source='group_session' AND source_id=? LIMIT 1");
        $ex->execute([$sessionId]);
        $row = $ex->fetch();

        if (!$row) {
            if ($amount <= 0) return;
            $pdo->prepare("
                INSERT INTO trainer_earnings
                    (club_id, trainer_id, source, source_id, invoice_id,
                     earn_type, amount, available_amount, paid_amount,
                     release_trigger, available_at, status)
                VALUES (?,?,'group_session',?,NULL, 'group_session',?,?,0, 'on_session_complete',?,'available')
            ")->execute([$clubId, $trainerId, $sessionId, $amount, $amount, date('Y-m-d H:i:s')]);
            return;
        }

        $paid = (float)$row['paid_amount'];
        if ($amount <= 0 && $paid <= 0) {
            $pdo->prepare("DELETE FROM trainer_earnings WHERE id=?")->execute([$row['id']]);
            return;
        }
        $amount = max($amount, $paid);
        $pdo->prepare("
            UPDATE trainer_earnings SET amount=?, available_amount=?,
                status = CASE WHEN ? >= ? THEN 'paid' WHEN ? > 0 THEN 'partial' ELSE 'available' END,
                updated_at = NOW()
            WHERE id=?
        ")->execute([$amount, $amount, $paid, $amount, $paid, $row['id']]);
    }

    /** Сумісність зі старими викликами. */
    public static function createGroupSessionEarning(PDO $pdo, int $clubId, int $sessionId): void
    {
        self::recalcGroupSessionEarning($pdo, $clubId, $sessionId);
    }

    /**
     * Шукає активний абонемент клієнта (діє на сьогодні). Той самий запит,
     * що раніше дублювався в 'scan' і 'check_in' visits_api.php.
     */
    public static function findActiveInvoice(PDO $pdo, int $clubId, int $clientId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT ci.id, ci.tariff_name, ci.trainer_id, ci.trainer_name,
                   ci.visits_total, ci.visits_used
            FROM client_invoices ci
            WHERE ci.client_id = ? AND ci.club_id = ? AND ci.status = 'active'
              AND ci.start_date <= CURDATE() AND ci.end_date >= CURDATE()
              AND " . self::PAID_ENOUGH_SQL . "
            ORDER BY ci.end_date ASC LIMIT 1
        ");
        $stmt->execute([$clientId, $clubId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Умова "абонемент оплачено достатньо, щоб він діяв" (для групових абонементів —
     * мінімальна оплата учасника; у звичайних min_paid_to_activate = NULL).
     * Використовувати з аліасом таблиці ci.
     */
    public const PAID_ENOUGH_SQL = "(ci.min_paid_to_activate IS NULL OR ci.paid_amount >= ci.min_paid_to_activate)";
}

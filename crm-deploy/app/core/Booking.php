<?php
/**
 * Booking.php — єдине ядро запису на тренування (групові й персональні).
 * Використовується з CRM (group_sessions_api.php, booking_api.php) і має
 * використовуватись Telegram-ботом / застосунком — правила всюди однакові.
 *
 * Поняття:
 *   заняття  = group_sessions (kind='group' | 'personal', зал room_id, тип class_type_id)
 *   запис    = group_session_clients (booked / waitlist / attended / no_show / canceled)
 *
 * $actor: 'staff' — адміністратор/менеджер у CRM; 'client' — самозапис (бот/застосунок).
 * Клієнт підпадає під усі правила (вікно запису, абонемент, тариф, дедлайн скасування).
 * Персонал — лише під місткість, накладки та дедлайн скасування (власник може обійти дедлайн).
 */
class Booking
{
    public const DEFAULTS = [
        'book_ahead_days'         => 14,
        'book_close_minutes'      => 0,
        'cancel_deadline_minutes' => 180,
        'waitlist_enabled'        => 1,
        'require_invoice'         => 1,
        'personal_slot_step_min'  => 60,
        'generate_weeks'          => 4,
        'reminder_1_hours'        => 24,
        'reminder_2_hours'        => 2,
    ];

    /** Кінець заняття: end_time або start_time + 60 хв (старі заняття без end_time). */
    private const END_SQL = "COALESCE(gs.end_time, ADDTIME(gs.start_time, '01:00:00'))";

    public static function settings(PDO $pdo, int $clubId): array
    {
        $st = $pdo->prepare("SELECT * FROM club_booking_settings WHERE club_id=?");
        $st->execute([$clubId]);
        $row = $st->fetch() ?: [];
        $out = [];
        foreach (self::DEFAULTS as $k => $v) $out[$k] = isset($row[$k]) ? (int)$row[$k] : $v;
        return $out;
    }

    public static function saveSettings(PDO $pdo, int $clubId, array $in): array
    {
        $s = self::settings($pdo, $clubId);
        foreach (self::DEFAULTS as $k => $_) {
            if (array_key_exists($k, $in)) $s[$k] = max(0, (int)$in[$k]);
        }
        $s['book_ahead_days']        = max(1, min(365, $s['book_ahead_days']));
        $s['personal_slot_step_min'] = max(5, min(240, $s['personal_slot_step_min']));
        $s['generate_weeks']         = max(1, min(26, $s['generate_weeks']));
        $s['reminder_1_hours']       = min(168, $s['reminder_1_hours']);
        $s['reminder_2_hours']       = min(168, $s['reminder_2_hours']);
        $s['waitlist_enabled']       = $s['waitlist_enabled'] ? 1 : 0;
        $s['require_invoice']        = $s['require_invoice'] ? 1 : 0;

        $cols = array_keys(self::DEFAULTS);
        $pdo->prepare("
            INSERT INTO club_booking_settings (club_id, " . implode(',', $cols) . ")
            VALUES (?" . str_repeat(',?', count($cols)) . ")
            ON DUPLICATE KEY UPDATE " . implode(',', array_map(fn($c) => "$c=VALUES($c)", $cols))
        )->execute(array_merge([$clubId], array_map(fn($c) => $s[$c], $cols)));
        return $s;
    }

    public static function addMinutes(string $time, int $minutes): string
    {
        return date('H:i:s', strtotime("2000-01-01 {$time}") + $minutes * 60);
    }

    public static function sessionStartTs(array $session): int
    {
        return strtotime($session['session_date'] . ' ' . $session['start_time']);
    }

    /**
     * Перевірка накладок: той самий тренер або той самий зал уже зайняті
     * в цей час. Повертає текст помилки або null.
     */
    public static function findConflict(
        PDO $pdo, int $clubId, string $date, string $start, string $end,
        ?int $trainerId, ?int $roomId, ?int $excludeSessionId = null
    ): ?string {
        $st = $pdo->prepare("
            SELECT gs.id, gs.name, gs.start_time, gs.trainer_id, gs.room_id, r.name AS room_name
            FROM group_sessions gs
            LEFT JOIN club_rooms r ON r.id = gs.room_id
            WHERE gs.club_id = ? AND gs.session_date = ? AND gs.status IN ('scheduled','completed')
              AND gs.id <> ?
              AND gs.start_time < ? AND " . self::END_SQL . " > ?
              AND (gs.trainer_id = ? OR (gs.room_id IS NOT NULL AND gs.room_id = ?))
            LIMIT 1
        ");
        $st->execute([$clubId, $date, (int)$excludeSessionId, $end, $start, (int)$trainerId, (int)$roomId]);
        $c = $st->fetch();
        if (!$c) return null;
        $t = substr($c['start_time'], 0, 5);
        if ($roomId && (int)$c['room_id'] === $roomId) {
            return "Зал «{$c['room_name']}» зайнятий: «{$c['name']}» о {$t}";
        }
        return "Тренер зайнятий: «{$c['name']}» о {$t}";
    }

    /**
     * Абонемент клієнта, що діє на дату заняття, оплачений достатньо (групові
     * абонементи), має вільні відвідування з урахуванням уже записаних
     * майбутніх занять і (якщо задано) підходить за тарифом для цього типу заняття.
     */
    public static function findInvoiceForSession(PDO $pdo, int $clubId, int $clientId, array $session): ?array
    {
        $allowed = self::allowedTariffIds($pdo, (int)($session['class_type_id'] ?? 0));
        $st = $pdo->prepare("
            SELECT ci.id, ci.tariff_id, ci.tariff_name, ci.visits_total, ci.visits_used,
                   (SELECT COUNT(*) FROM group_session_clients b
                    JOIN group_sessions bs ON bs.id = b.session_id
                    WHERE b.invoice_id = ci.id AND b.status = 'booked'
                      AND bs.status = 'scheduled' AND bs.id <> ?) AS booked_ahead
            FROM client_invoices ci
            WHERE ci.client_id = ? AND ci.club_id = ? AND ci.status = 'active'
              AND ci.start_date <= ? AND ci.end_date >= ?
              AND " . Attendance::PAID_ENOUGH_SQL . "
            ORDER BY ci.end_date ASC
        ");
        $d = $session['session_date'];
        $st->execute([(int)($session['id'] ?? 0), $clientId, $clubId, $d, $d]);
        foreach ($st->fetchAll() as $inv) {
            if ($allowed && !in_array((int)$inv['tariff_id'], $allowed, true)) continue;
            if ($inv['visits_total'] !== null
                && (int)$inv['visits_used'] + (int)$inv['booked_ahead'] >= (int)$inv['visits_total']) continue;
            return $inv;
        }
        return null;
    }

    public static function allowedTariffIds(PDO $pdo, int $classTypeId): array
    {
        if (!$classTypeId) return [];
        $st = $pdo->prepare("SELECT tariff_id FROM class_type_tariffs WHERE class_type_id=?");
        $st->execute([$classTypeId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function loadSession(PDO $pdo, int $clubId, int $sessionId): array
    {
        $st = $pdo->prepare("SELECT * FROM group_sessions WHERE id=? AND club_id=? LIMIT 1");
        $st->execute([$sessionId, $clubId]);
        $s = $st->fetch();
        if (!$s) throw new BookingException('Заняття не знайдено');
        return $s;
    }

    /**
     * Записати клієнта на заняття. Якщо місць немає і лист очікування
     * увімкнено — ставить у лист очікування.
     * Повертає ['roster_id', 'status' => 'booked'|'waitlist', 'invoice_id'].
     */
    public static function book(
        PDO $pdo, int $clubId, int $sessionId, int $clientId,
        string $actor, string $source, int $userId
    ): array {
        $session = self::loadSession($pdo, $clubId, $sessionId);
        $cfg = self::settings($pdo, $clubId);
        if ($session['status'] !== 'scheduled') throw new BookingException('Можна записуватись лише на заплановане заняття');

        $startTs = self::sessionStartTs($session);
        if ($actor === 'client') {
            if ($startTs - $cfg['book_close_minutes'] * 60 <= time())
                throw new BookingException('Запис на це заняття вже закрито');
            if ($session['session_date'] > date('Y-m-d', strtotime("+{$cfg['book_ahead_days']} days")))
                throw new BookingException("Запис відкривається за {$cfg['book_ahead_days']} дн. до заняття");
        }

        $cs = $pdo->prepare("SELECT id, full_name, status FROM clients WHERE id=? AND club_id=? LIMIT 1");
        $cs->execute([$clientId, $clubId]);
        $client = $cs->fetch();
        if (!$client) throw new BookingException('Клієнта не знайдено');
        if ($client['status'] === 'blocked') throw new BookingException('Клієнт заблокований');

        $invoice = self::findInvoiceForSession($pdo, $clubId, $clientId, $session);
        if (!$invoice && ($actor === 'client' && $cfg['require_invoice'])) {
            throw new BookingException('Немає діючого абонемента, який дає право на це заняття (або вичерпано відвідування)');
        }

        $ex = $pdo->prepare("SELECT id, status FROM group_session_clients WHERE session_id=? AND client_id=? LIMIT 1");
        $ex->execute([$sessionId, $clientId]);
        $existing = $ex->fetch();
        if ($existing && in_array($existing['status'], ['booked', 'attended'], true))
            throw new BookingException('Клієнт уже записаний на це заняття');
        if ($existing && $existing['status'] === 'waitlist')
            throw new BookingException('Клієнт уже в листі очікування');

        // Клієнт не може бути в двох місцях одночасно
        $busy = $pdo->prepare("
            SELECT gs.name FROM group_session_clients gsc
            JOIN group_sessions gs ON gs.id = gsc.session_id
            WHERE gsc.client_id = ? AND gsc.status IN ('booked','waitlist')
              AND gs.club_id = ? AND gs.status = 'scheduled' AND gs.id <> ?
              AND gs.session_date = ? AND gs.start_time < ? AND " . self::END_SQL . " > ?
            LIMIT 1
        ");
        $end = $session['end_time'] ?: self::addMinutes($session['start_time'], 60);
        $busy->execute([$clientId, $clubId, $sessionId, $session['session_date'], $end, $session['start_time']]);
        if ($other = $busy->fetchColumn()) throw new BookingException("Клієнт уже записаний на «{$other}» у цей час");

        $status = 'booked';
        if ($session['capacity']) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM group_session_clients WHERE session_id=? AND status IN ('booked','attended')");
            $cnt->execute([$sessionId]);
            if ((int)$cnt->fetchColumn() >= (int)$session['capacity']) {
                if (!$cfg['waitlist_enabled'] || $session['kind'] === 'personal')
                    throw new BookingException('Місць немає');
                $status = 'waitlist';
            }
        }

        $invoiceId = $invoice['id'] ?? null;
        if ($existing) {
            $pdo->prepare("
                UPDATE group_session_clients
                SET status=?, source=?, invoice_id=?, visit_id=NULL, checked_in_at=NULL,
                    canceled_at=NULL, canceled_by=NULL, created_at=NOW(), created_by=?
                WHERE id=?
            ")->execute([$status, $source, $invoiceId, $userId, $existing['id']]);
            $rosterId = (int)$existing['id'];
        } else {
            $pdo->prepare("
                INSERT INTO group_session_clients (session_id, client_id, invoice_id, status, source, created_by)
                VALUES (?,?,?,?,?,?)
            ")->execute([$sessionId, $clientId, $invoiceId, $status, $source, $userId]);
            $rosterId = (int)$pdo->lastInsertId();
        }
        return ['roster_id' => $rosterId, 'status' => $status, 'invoice_id' => $invoiceId];
    }

    /**
     * Скасувати запис. Після дедлайну (cancel_deadline_minutes до початку)
     * скасування заборонене — і клієнту, і персоналу; обійти може лише власник
     * ($ownerOverride). Запис у листі очікування можна прибрати будь-коли.
     * Звільнене місце автоматично отримує перший з листа очікування.
     */
    public static function cancel(PDO $pdo, int $clubId, int $rosterId, string $actor, bool $ownerOverride = false): array
    {
        $st = $pdo->prepare("
            SELECT gsc.*, gs.session_date, gs.start_time, gs.kind, gs.status AS session_status, gs.name
            FROM group_session_clients gsc
            JOIN group_sessions gs ON gs.id = gsc.session_id
            WHERE gsc.id=? AND gs.club_id=? LIMIT 1
        ");
        $st->execute([$rosterId, $clubId]);
        $r = $st->fetch();
        if (!$r) throw new BookingException('Запис не знайдено');
        if (!in_array($r['status'], ['booked', 'waitlist'], true)) throw new BookingException('Цей запис уже не активний');

        if ($r['status'] === 'booked' && !$ownerOverride) {
            $cfg = self::settings($pdo, $clubId);
            $deadline = self::sessionStartTs($r) - $cfg['cancel_deadline_minutes'] * 60;
            if (time() > $deadline) {
                $h = self::humanMinutes($cfg['cancel_deadline_minutes']);
                throw new BookingException("Скасувати запис можна не пізніше ніж за {$h} до початку");
            }
        }

        $pdo->prepare("UPDATE group_session_clients SET status='canceled', canceled_at=NOW(), canceled_by=? WHERE id=?")
            ->execute([$actor, $rosterId]);

        $promoted = null;
        if ($r['kind'] === 'personal') {
            // Персональне тренування без клієнта не має сенсу — звільняємо час тренера
            $pdo->prepare("UPDATE group_sessions SET status='canceled' WHERE id=? AND status='scheduled'")
                ->execute([$r['session_id']]);
        } elseif ($r['status'] === 'booked') {
            $promoted = self::promoteWaitlist($pdo, $clubId, (int)$r['session_id']);
        }
        return ['promoted_client_id' => $promoted];
    }

    /** Перший із листа очікування отримує звільнене місце (+ сповіщення в Telegram). */
    public static function promoteWaitlist(PDO $pdo, int $clubId, int $sessionId): ?int
    {
        $session = self::loadSession($pdo, $clubId, $sessionId);
        if ($session['status'] !== 'scheduled' || self::sessionStartTs($session) <= time()) return null;
        if ($session['capacity']) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM group_session_clients WHERE session_id=? AND status IN ('booked','attended')");
            $cnt->execute([$sessionId]);
            if ((int)$cnt->fetchColumn() >= (int)$session['capacity']) return null;
        }
        $st = $pdo->prepare("
            SELECT gsc.id, gsc.client_id, c.telegram_id
            FROM group_session_clients gsc JOIN clients c ON c.id = gsc.client_id
            WHERE gsc.session_id=? AND gsc.status='waitlist'
            ORDER BY gsc.created_at ASC, gsc.id ASC LIMIT 1
        ");
        $st->execute([$sessionId]);
        $w = $st->fetch();
        if (!$w) return null;

        $invoice = self::findInvoiceForSession($pdo, $clubId, (int)$w['client_id'], $session);
        $pdo->prepare("UPDATE group_session_clients SET status='booked', invoice_id=? WHERE id=?")
            ->execute([$invoice['id'] ?? null, $w['id']]);

        if (!empty($w['telegram_id']) && class_exists('Telegram')) {
            try {
                $when = date('d.m', strtotime($session['session_date'])) . ' о ' . substr($session['start_time'], 0, 5);
                Telegram::sendMessage((string)$w['telegram_id'], "✅ Звільнилось місце! Вас записано на «{$session['name']}» {$when}.");
            } catch (Throwable $e) {
                error_log('[Booking] waitlist notify: ' . $e->getMessage());
            }
        }
        return (int)$w['client_id'];
    }

    public static function humanMinutes(int $m): string
    {
        if ($m % 1440 === 0 && $m >= 1440) return ($m / 1440) . ' дн.';
        if ($m % 60 === 0 && $m >= 60) return ($m / 60) . ' год';
        return $m . ' хв';
    }

    /**
     * Вільні години тренера на дату для персонального тренування тривалістю $durationMin.
     * Джерело — trainer_availability (робочі години по днях тижня) мінус уже
     * заплановані заняття тренера і зайнятість залу.
     */
    public static function availableSlots(PDO $pdo, int $clubId, int $trainerId, string $date, int $durationMin): array
    {
        $cfg = self::settings($pdo, $clubId);
        $step = $cfg['personal_slot_step_min'];
        $weekday = (int)date('N', strtotime($date));

        $av = $pdo->prepare("
            SELECT start_time, end_time, room_id FROM trainer_availability
            WHERE club_id=? AND trainer_id=? AND weekday=? ORDER BY start_time
        ");
        $av->execute([$clubId, $trainerId, $weekday]);
        $slots = [];
        foreach ($av->fetchAll() as $w) {
            $t   = strtotime("{$date} {$w['start_time']}");
            $lim = strtotime("{$date} {$w['end_time']}");
            for (; $t + $durationMin * 60 <= $lim; $t += $step * 60) {
                if ($t <= time()) continue;
                $start = date('H:i:s', $t);
                $end   = date('H:i:s', $t + $durationMin * 60);
                $roomId = $w['room_id'] ? (int)$w['room_id'] : null;
                if (self::findConflict($pdo, $clubId, $date, $start, $end, $trainerId, $roomId)) continue;
                $slots[] = ['start' => substr($start, 0, 5), 'end' => substr($end, 0, 5), 'room_id' => $roomId];
            }
        }
        return $slots;
    }

    /**
     * Запис на персональне тренування: створює заняття kind='personal' на 1 місце
     * і записує клієнта. Клієнт може обрати лише вільну годину з графіка тренера;
     * персонал — будь-який час без накладок.
     */
    public static function bookPersonal(
        PDO $pdo, int $clubId, int $classTypeId, int $trainerId, string $date, string $start,
        int $clientId, string $actor, string $source, int $userId, ?int $roomId = null
    ): array {
        $ts = $pdo->prepare("SELECT * FROM class_types WHERE id=? AND club_id=? AND kind='personal' AND is_active=1");
        $ts->execute([$classTypeId, $clubId]);
        $type = $ts->fetch();
        if (!$type) throw new BookingException('Тип персонального тренування не знайдено');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}/', $start))
            throw new BookingException('Невірна дата або час');

        $start = substr($start, 0, 5) . ':00';
        $dur   = (int)$type['duration_min'];
        $end   = self::addMinutes($start, $dur);

        // Клієнт може обрати лише вільну годину з графіка; персонал — будь-який час,
        // але якщо час збігається з вільною годиною — зал береться з графіка тренера.
        $match = array_values(array_filter(self::availableSlots($pdo, $clubId, $trainerId, $date, $dur),
            fn($s) => $s['start'] === substr($start, 0, 5)));
        if ($actor === 'client' && !$match) throw new BookingException('Цей час уже недоступний');
        if ($match && !$roomId) $roomId = $match[0]['room_id'];
        $roomId = $roomId ?: ($type['default_room_id'] ? (int)$type['default_room_id'] : null);
        if ($c = self::findConflict($pdo, $clubId, $date, $start, $end, $trainerId, $roomId))
            throw new BookingException($c);

        $pdo->prepare("
            INSERT INTO group_sessions
                (club_id, kind, class_type_id, trainer_id, room_id, name, session_date, start_time, end_time, capacity, created_by)
            VALUES (?, 'personal', ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ")->execute([$clubId, $classTypeId, $trainerId, $roomId, $type['name'], $date, $start, $end, $userId]);
        $sessionId = (int)$pdo->lastInsertId();

        return ['session_id' => $sessionId] + self::book($pdo, $clubId, $sessionId, $clientId, $actor, $source, $userId);
    }

    /**
     * Будує заняття з шаблонів розкладу до $untilDate (ідемпотентно: пара
     * template_id + дата унікальна). Заняття з накладкою (зал/тренер зайняті)
     * пропускаються. Повертає ['created' => N, 'conflicts' => [...]].
     */
    public static function generate(PDO $pdo, int $clubId, ?string $untilDate = null, ?int $templateId = null): array
    {
        $cfg = self::settings($pdo, $clubId);
        $untilDate = $untilDate ?: date('Y-m-d', strtotime('+' . ($cfg['generate_weeks'] * 7) . ' days'));

        $sql = "
            SELECT st.*, ct.name AS type_name, ct.duration_min AS type_duration, ct.capacity AS type_capacity,
                   ct.default_room_id
            FROM schedule_templates st
            JOIN class_types ct ON ct.id = st.class_type_id
            WHERE st.club_id = ? AND st.is_active = 1 AND ct.is_active = 1
        ";
        $params = [$clubId];
        if ($templateId) { $sql .= ' AND st.id = ?'; $params[] = $templateId; }
        $st = $pdo->prepare($sql);
        $st->execute($params);

        $exists = $pdo->prepare("SELECT 1 FROM group_sessions WHERE template_id=? AND session_date=? LIMIT 1");
        $ins = $pdo->prepare("
            INSERT INTO group_sessions
                (club_id, kind, class_type_id, trainer_id, room_id, template_id, name,
                 session_date, start_time, end_time, capacity, created_by)
            VALUES (?, 'group', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $created = 0;
        $conflicts = [];
        foreach ($st->fetchAll() as $t) {
            $from = max(date('Y-m-d'), $t['valid_from']);
            $to   = $t['valid_to'] ? min($untilDate, $t['valid_to']) : $untilDate;
            $dur  = (int)($t['duration_min'] ?: $t['type_duration']);
            $cap  = $t['capacity'] !== null ? (int)$t['capacity'] : ($t['type_capacity'] !== null ? (int)$t['type_capacity'] : null);
            $room = $t['room_id'] ? (int)$t['room_id'] : ($t['default_room_id'] ? (int)$t['default_room_id'] : null);
            $end  = self::addMinutes($t['start_time'], $dur);

            for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
                $date = date('Y-m-d', $d);
                if ((int)date('N', $d) !== (int)$t['weekday']) continue;
                $exists->execute([$t['id'], $date]);
                if ($exists->fetchColumn()) continue;
                if ($date === date('Y-m-d') && strtotime("{$date} {$t['start_time']}") <= time()) continue;
                if ($c = self::findConflict($pdo, $clubId, $date, $t['start_time'], $end, (int)$t['trainer_id'], $room)) {
                    $conflicts[] = "{$t['type_name']} " . date('d.m', $d) . ": {$c}";
                    continue;
                }
                $ins->execute([
                    $clubId, $t['class_type_id'], $t['trainer_id'], $room, $t['id'], $t['type_name'],
                    $date, $t['start_time'], $end, $cap, $t['created_by'],
                ]);
                $created++;
            }
        }
        return ['created' => $created, 'conflicts' => $conflicts];
    }

    /**
     * Видаляє майбутні заняття шаблону, на які ще ніхто не записаний (перед
     * перебудовою після зміни/вимкнення шаблону). Заняття з записами лишаються.
     */
    public static function dropFutureEmpty(PDO $pdo, int $clubId, int $templateId): int
    {
        $st = $pdo->prepare("
            DELETE gs FROM group_sessions gs
            WHERE gs.club_id=? AND gs.template_id=? AND gs.status='scheduled'
              AND gs.session_date >= CURDATE()
              AND NOT EXISTS (SELECT 1 FROM group_session_clients gsc
                              WHERE gsc.session_id = gs.id AND gsc.status <> 'canceled')
        ");
        $st->execute([$clubId, $templateId]);
        return $st->rowCount();
    }
}

/** Порушення правила запису — показується користувачу як є. */
class BookingException extends RuntimeException {}

<?php
/**
 * BookingBot.php — самозапис на тренування через Telegram-бота клієнта.
 *
 * Уся логіка правил — Booking.php (актор 'client', джерело 'telegram'):
 * вікно запису, абонемент/тариф, місця, лист очікування, накладки, дедлайн скасування.
 * Тут — лише екрани бота (inline-кнопки) і нагадування.
 *
 * callback_data (≤ 64 байт), {c} — clients.id (визначає клуб; перевіряється,
 * що цей клієнт прив'язаний саме до цього chat_id):
 *   k:{c}                      — вибір дня розкладу (початок)
 *   w:{c}:{offset}             — тиждень розкладу (0, 7, 14 … днів від сьогодні)
 *   d:{c}:{Ymd}                — заняття дня
 *   s:{c}:{sessionId}          — картка заняття
 *   b:{c}:{sessionId}          — записатися / стати в чергу
 *   x:{c}:{rosterId}           — скасувати запис / вийти з черги
 *   m:{c}                      — мої записи
 *   p:{c}                      — персональні: вибір виду
 *   pt:{c}:{typeId}            — персональні: вибір тренера
 *   pr:{c}:{typeId}:{trId}     — персональні: вибір дня
 *   pd:{c}:{typeId}:{trId}:{Ymd}        — вільні години
 *   pb:{c}:{typeId}:{trId}:{Ymd}:{Hi}   — записатися на годину
 */
class BookingBot
{
    public const BTN_SCHEDULE = '📅 Розклад';
    public const BTN_MY       = '🗓 Мої записи';

    private const DOW = ['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд'];

    private static function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }

    private static function dayLabel(string $date): string
    {
        return self::DOW[(int)date('N', strtotime($date))] . ' ' . date('d.m', strtotime($date));
    }

    /** Показати екран: редагуємо повідомлення з кнопками, якщо це натискання, інакше — нове. */
    private static function show(string $chatId, ?int $messageId, string $text, array $rows): void
    {
        $markup = ['inline_keyboard' => $rows];
        if ($messageId) {
            $res = Telegram::call('editMessageText', [
                'chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text,
                'parse_mode' => 'HTML', 'reply_markup' => $markup,
            ]);
            if (!empty($res['ok']) || str_contains((string)($res['description'] ?? ''), 'not modified')) return;
        }
        Telegram::sendMessage($chatId, $text, ['reply_markup' => $markup]);
    }

    /** Усі прив'язані до чату клієнти (по одному на клуб). */
    public static function linkedClients(PDO $pdo, string $chatId): array
    {
        $st = $pdo->prepare("
            SELECT c.id, c.club_id, c.full_name, cl.name AS club_name
            FROM clients c JOIN sys_clubs cl ON cl.id = c.club_id
            WHERE c.telegram_id = ? AND c.status <> 'blocked'
            ORDER BY cl.name
        ");
        $st->execute([$chatId]);
        return $st->fetchAll();
    }

    /** Клієнт з callback_data — лише якщо він справді прив'язаний до цього чату. */
    private static function client(PDO $pdo, string $chatId, int $clientId): ?array
    {
        $st = $pdo->prepare("
            SELECT c.id, c.club_id, c.full_name, cl.name AS club_name
            FROM clients c JOIN sys_clubs cl ON cl.id = c.club_id
            WHERE c.id = ? AND c.telegram_id = ? AND c.status <> 'blocked' LIMIT 1
        ");
        $st->execute([$clientId, $chatId]);
        return $st->fetch() ?: null;
    }

    // ═════ Точки входу з вебхука ═════════════════════════════════

    /** Текстова кнопка «Розклад» / «Мої записи». */
    public static function onMenu(PDO $pdo, string $chatId, string $what): void
    {
        $clients = self::linkedClients($pdo, $chatId);
        if (!$clients) {
            Telegram::sendMessage($chatId, "Ви ще не прив'язані до жодного клубу. Натисніть /start і поділіться номером телефону.");
            return;
        }
        if (count($clients) > 1) {
            $rows = array_map(fn($c) => [['text' => $c['club_name'], 'callback_data' => ($what === 'my' ? 'm:' : 'k:') . $c['id']]], $clients);
            self::show($chatId, null, 'Оберіть клуб:', $rows);
            return;
        }
        $what === 'my'
            ? self::myBookings($pdo, $chatId, null, $clients[0])
            : self::weekScreen($pdo, $chatId, null, $clients[0], 0);
    }

    /** Натискання inline-кнопки. */
    public static function onCallback(PDO $pdo, array $cq): void
    {
        $chatId    = (string)($cq['message']['chat']['id'] ?? '');
        $messageId = (int)($cq['message']['message_id'] ?? 0) ?: null;
        $data      = (string)($cq['data'] ?? '');
        $toast     = '';

        try {
            $p = explode(':', $data);
            $client = isset($p[1]) ? self::client($pdo, $chatId, (int)$p[1]) : null;
            if (!$client) {
                $toast = 'Дія недоступна';
            } else {
                switch ($p[0]) {
                    case 'k':  self::weekScreen($pdo, $chatId, $messageId, $client, 0); break;
                    case 'w':  self::weekScreen($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0)); break;
                    case 'd':  self::dayScreen($pdo, $chatId, $messageId, $client, self::ymd($p[2] ?? '')); break;
                    case 's':  self::sessionScreen($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0)); break;
                    case 'b':  $toast = self::doBook($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0)); break;
                    case 'x':  $toast = self::doCancel($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0)); break;
                    case 'm':  self::myBookings($pdo, $chatId, $messageId, $client); break;
                    case 'p':  self::personalTypes($pdo, $chatId, $messageId, $client); break;
                    case 'pt': self::personalTrainers($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0)); break;
                    case 'pr': self::personalDays($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0), (int)($p[3] ?? 0)); break;
                    case 'pd': self::personalSlots($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0), (int)($p[3] ?? 0), self::ymd($p[4] ?? '')); break;
                    case 'pb': $toast = self::doBookPersonal($pdo, $chatId, $messageId, $client, (int)($p[2] ?? 0), (int)($p[3] ?? 0), self::ymd($p[4] ?? ''), (string)($p[5] ?? '')); break;
                    default:   $toast = 'Невідома дія';
                }
            }
        } catch (Throwable $e) {
            error_log('[BookingBot] ' . $e->getMessage());
            $toast = 'Помилка. Спробуйте ще раз.';
        }

        Telegram::call('answerCallbackQuery', array_filter([
            'callback_query_id' => $cq['id'] ?? '',
            'text'              => $toast ?: null,
            'show_alert'        => mb_strlen($toast) > 40,
        ], fn($v) => $v !== null));
    }

    private static function ymd(string $s): string
    {
        return preg_match('/^\d{8}$/', $s) ? substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2) : date('Y-m-d');
    }

    // ═════ Групові заняття ═══════════════════════════════════════

    private static function weekScreen(PDO $pdo, string $chatId, ?int $mid, array $c, int $offset): void
    {
        $cfg   = Booking::settings($pdo, (int)$c['club_id']);
        $ahead = max(1, $cfg['book_ahead_days']);
        $offset = max(0, min($offset, $ahead - 1));
        $from  = date('Y-m-d', strtotime("+{$offset} days"));
        $last  = min($offset + 6, $ahead);
        $to    = date('Y-m-d', strtotime("+{$last} days"));

        $cnt = $pdo->prepare("
            SELECT session_date, COUNT(*) FROM group_sessions
            WHERE club_id=? AND kind='group' AND status='scheduled' AND session_date BETWEEN ? AND ?
              AND TIMESTAMP(session_date, start_time) > NOW()
            GROUP BY session_date
        ");
        $cnt->execute([$c['club_id'], $from, $to]);
        $counts = $cnt->fetchAll(PDO::FETCH_KEY_PAIR);

        $btns = [];
        for ($i = $offset; $i <= $last; $i++) {
            $d = date('Y-m-d', strtotime("+{$i} days"));
            $n = (int)($counts[$d] ?? 0);
            $btns[] = ['text' => self::dayLabel($d) . ($n ? " ({$n})" : ' —'), 'callback_data' => "d:{$c['id']}:" . str_replace('-', '', $d)];
        }
        $rows = array_chunk($btns, 3);
        $nav = [];
        if ($offset > 0) $nav[] = ['text' => '‹ Раніше', 'callback_data' => "w:{$c['id']}:" . max(0, $offset - 7)];
        if ($last < $ahead) $nav[] = ['text' => 'Далі ›', 'callback_data' => "w:{$c['id']}:" . ($offset + 7)];
        if ($nav) $rows[] = $nav;
        if (self::hasPersonal($pdo, (int)$c['club_id'])) $rows[] = [['text' => '🏋️ Персональне тренування', 'callback_data' => "p:{$c['id']}"]];
        $rows[] = [['text' => '🗓 Мої записи', 'callback_data' => "m:{$c['id']}"]];

        self::show($chatId, $mid, "📅 <b>Розклад — " . self::h($c['club_name']) . "</b>\nОберіть день (у дужках — кількість занять):", $rows);
    }

    private static function dayScreen(PDO $pdo, string $chatId, ?int $mid, array $c, string $date): void
    {
        $st = $pdo->prepare("
            SELECT gs.id, gs.name, gs.start_time, gs.capacity, u.full_name AS trainer,
                   (SELECT COUNT(*) FROM group_session_clients x WHERE x.session_id=gs.id AND x.status IN ('booked','attended')) AS taken,
                   (SELECT status FROM group_session_clients y WHERE y.session_id=gs.id AND y.client_id=? LIMIT 1) AS my_status
            FROM group_sessions gs
            JOIN club_trainers ct ON ct.id = gs.trainer_id
            JOIN sys_users u ON u.id = ct.user_id
            WHERE gs.club_id=? AND gs.kind='group' AND gs.status='scheduled' AND gs.session_date=?
              AND TIMESTAMP(gs.session_date, gs.start_time) > NOW()
            ORDER BY gs.start_time
        ");
        $st->execute([$c['id'], $c['club_id'], $date]);
        $rows = [];
        foreach ($st->fetchAll() as $s) {
            $mark = match ($s['my_status']) {
                'booked'   => '✅ ',
                'waitlist' => '⏳ ',
                default    => ($s['capacity'] && (int)$s['taken'] >= (int)$s['capacity'] ? '🔒 ' : ''),
            };
            $places = $s['capacity'] ? " · {$s['taken']}/{$s['capacity']}" : '';
            $rows[] = [['text' => $mark . substr($s['start_time'], 0, 5) . ' ' . $s['name'] . ' · ' . $s['trainer'] . $places, 'callback_data' => "s:{$c['id']}:{$s['id']}"]];
        }
        $text = "📅 <b>" . self::dayLabel($date) . "</b>\n" . ($rows ? 'Оберіть заняття:' : 'На цей день групових занять немає.');
        $rows[] = [['text' => '« До днів', 'callback_data' => "k:{$c['id']}"]];
        self::show($chatId, $mid, $text, $rows);
    }

    private static function sessionScreen(PDO $pdo, string $chatId, ?int $mid, array $c, int $sessionId, string $note = ''): void
    {
        $st = $pdo->prepare("
            SELECT gs.*, u.full_name AS trainer, r.name AS room, t.description,
                   (SELECT COUNT(*) FROM group_session_clients x WHERE x.session_id=gs.id AND x.status IN ('booked','attended')) AS taken,
                   (SELECT COUNT(*) FROM group_session_clients x WHERE x.session_id=gs.id AND x.status='waitlist') AS queue
            FROM group_sessions gs
            JOIN club_trainers ct ON ct.id = gs.trainer_id
            JOIN sys_users u ON u.id = ct.user_id
            LEFT JOIN club_rooms r ON r.id = gs.room_id
            LEFT JOIN class_types t ON t.id = gs.class_type_id
            WHERE gs.id=? AND gs.club_id=? LIMIT 1
        ");
        $st->execute([$sessionId, $c['club_id']]);
        $s = $st->fetch();
        if (!$s) { self::show($chatId, $mid, 'Заняття не знайдено.', [[['text' => '« До днів', 'callback_data' => "k:{$c['id']}"]]]); return; }

        $my = $pdo->prepare("SELECT id, status FROM group_session_clients WHERE session_id=? AND client_id=? LIMIT 1");
        $my->execute([$sessionId, $c['id']]);
        $mine = $my->fetch();
        $cfg  = Booking::settings($pdo, (int)$c['club_id']);
        $deadlineTs = Booking::sessionStartTs($s) - $cfg['cancel_deadline_minutes'] * 60;

        $lines = [
            "<b>" . self::h($s['name']) . "</b>",
            "🗓 " . self::dayLabel($s['session_date']) . ' о ' . substr($s['start_time'], 0, 5) . ($s['end_time'] ? '–' . substr($s['end_time'], 0, 5) : ''),
            "👤 " . self::h($s['trainer']) . ($s['room'] ? " · 📍 " . self::h($s['room']) : ''),
            $s['capacity'] ? "Місць: {$s['taken']}/{$s['capacity']}" . ($s['queue'] ? " · у черзі {$s['queue']}" : '') : 'Місць: без обмеження',
        ];
        if ($s['description']) $lines[] = "\n" . self::h($s['description']);

        $rows = [];
        $active = $s['status'] === 'scheduled' && Booking::sessionStartTs($s) > time();
        if ($mine && $mine['status'] === 'booked') {
            $lines[] = "\n✅ <b>Ви записані.</b>";
            if ($active && time() <= $deadlineTs) {
                $lines[] = 'Скасувати можна до ' . date('d.m H:i', $deadlineTs) . '.';
                $rows[] = [['text' => '❌ Скасувати запис', 'callback_data' => "x:{$c['id']}:{$mine['id']}"]];
            } elseif ($active) {
                $lines[] = 'Скасування вже недоступне (менше ніж ' . Booking::humanMinutes($cfg['cancel_deadline_minutes']) . ' до початку).';
            }
        } elseif ($mine && $mine['status'] === 'waitlist') {
            $lines[] = "\n⏳ <b>Ви в листі очікування.</b> Якщо звільниться місце — запишемо автоматично й повідомимо.";
            $rows[] = [['text' => '❌ Вийти з черги', 'callback_data' => "x:{$c['id']}:{$mine['id']}"]];
        } elseif ($active) {
            $full = $s['capacity'] && (int)$s['taken'] >= (int)$s['capacity'];
            if ($full && !$cfg['waitlist_enabled']) {
                $lines[] = "\n🔒 Місць немає.";
            } else {
                $rows[] = [['text' => $full ? '⏳ Стати в чергу' : '✅ Записатися', 'callback_data' => "b:{$c['id']}:{$sessionId}"]];
                $lines[] = "\nСкасувати запис можна не пізніше ніж за " . Booking::humanMinutes($cfg['cancel_deadline_minutes']) . ' до початку.';
            }
        }
        if ($note) $lines[] = "\n" . $note;
        $rows[] = [['text' => '« До занять дня', 'callback_data' => "d:{$c['id']}:" . str_replace('-', '', $s['session_date'])]];
        self::show($chatId, $mid, implode("\n", $lines), $rows);
    }

    private static function doBook(PDO $pdo, string $chatId, ?int $mid, array $c, int $sessionId): string
    {
        try {
            $res = Booking::book($pdo, (int)$c['club_id'], $sessionId, (int)$c['id'], 'client', 'telegram', 0);
        } catch (BookingException $e) {
            self::sessionScreen($pdo, $chatId, $mid, $c, $sessionId, '⚠️ ' . self::h($e->getMessage()));
            return $e->getMessage();
        }
        $msg = $res['status'] === 'waitlist' ? 'Ви в листі очікування' : 'Вас записано ✅';
        self::sessionScreen($pdo, $chatId, $mid, $c, $sessionId);
        return $msg;
    }

    private static function doCancel(PDO $pdo, string $chatId, ?int $mid, array $c, int $rosterId): string
    {
        $st = $pdo->prepare("SELECT session_id, client_id FROM group_session_clients WHERE id=? LIMIT 1");
        $st->execute([$rosterId]);
        $r = $st->fetch();
        if (!$r || (int)$r['client_id'] !== (int)$c['id']) return 'Запис не знайдено';
        try {
            Booking::cancel($pdo, (int)$c['club_id'], $rosterId, 'client');
        } catch (BookingException $e) {
            return $e->getMessage();
        }
        self::myBookings($pdo, $chatId, $mid, $c, '❌ Запис скасовано.');
        return 'Запис скасовано';
    }

    // ═════ Мої записи ════════════════════════════════════════════

    private static function myBookings(PDO $pdo, string $chatId, ?int $mid, array $c, string $note = ''): void
    {
        $cfg = Booking::settings($pdo, (int)$c['club_id']);
        $st = $pdo->prepare("
            SELECT gsc.id, gsc.status, gs.id AS session_id, gs.name, gs.session_date, gs.start_time, gs.kind, u.full_name AS trainer
            FROM group_session_clients gsc
            JOIN group_sessions gs ON gs.id = gsc.session_id
            JOIN club_trainers ct ON ct.id = gs.trainer_id
            JOIN sys_users u ON u.id = ct.user_id
            WHERE gsc.client_id=? AND gsc.status IN ('booked','waitlist')
              AND gs.status='scheduled' AND TIMESTAMP(gs.session_date, gs.start_time) > NOW()
            ORDER BY gs.session_date, gs.start_time
            LIMIT 20
        ");
        $st->execute([$c['id']]);
        $list = $st->fetchAll();

        $lines = ["🗓 <b>Мої записи — " . self::h($c['club_name']) . "</b>"];
        if ($note) $lines[] = $note;
        $rows = [];
        if (!$list) $lines[] = 'Майбутніх записів немає.';
        foreach ($list as $b) {
            $when = self::dayLabel($b['session_date']) . ' ' . substr($b['start_time'], 0, 5);
            $lines[] = ($b['status'] === 'waitlist' ? '⏳ ' : '✅ ') . "{$when} — " . self::h($b['name']) . ' · ' . self::h($b['trainer'])
                . ($b['status'] === 'waitlist' ? ' (черга)' : '');
            $deadline = Booking::sessionStartTs($b) - $cfg['cancel_deadline_minutes'] * 60;
            if ($b['status'] === 'waitlist' || time() <= $deadline) {
                $rows[] = [['text' => "❌ {$when} {$b['name']}", 'callback_data' => "x:{$c['id']}:{$b['id']}"]];
            }
        }
        if ($list) $lines[] = "\nСкасувати можна не пізніше ніж за " . Booking::humanMinutes($cfg['cancel_deadline_minutes']) . ' до початку.';
        $rows[] = [['text' => '📅 Розклад', 'callback_data' => "k:{$c['id']}"]];
        self::show($chatId, $mid, implode("\n", $lines), $rows);
    }

    // ═════ Персональні тренування ════════════════════════════════

    private static function hasPersonal(PDO $pdo, int $clubId): bool
    {
        $st = $pdo->prepare("
            SELECT 1 FROM class_types t
            WHERE t.club_id=? AND t.kind='personal' AND t.is_active=1
              AND EXISTS (SELECT 1 FROM trainer_availability a WHERE a.club_id=t.club_id)
            LIMIT 1
        ");
        $st->execute([$clubId]);
        return (bool)$st->fetchColumn();
    }

    private static function personalTypes(PDO $pdo, string $chatId, ?int $mid, array $c): void
    {
        $st = $pdo->prepare("SELECT id, name, duration_min FROM class_types WHERE club_id=? AND kind='personal' AND is_active=1 ORDER BY sort_order, name");
        $st->execute([$c['club_id']]);
        $types = $st->fetchAll();
        if (count($types) === 1) { self::personalTrainers($pdo, $chatId, $mid, $c, (int)$types[0]['id']); return; }
        $rows = array_map(fn($t) => [['text' => "{$t['name']} · {$t['duration_min']} хв", 'callback_data' => "pt:{$c['id']}:{$t['id']}"]], $types);
        $rows[] = [['text' => '« Назад', 'callback_data' => "k:{$c['id']}"]];
        self::show($chatId, $mid, '🏋️ <b>Персональне тренування</b>' . "\nОберіть вид:", $rows);
    }

    private static function personalTrainers(PDO $pdo, string $chatId, ?int $mid, array $c, int $typeId): void
    {
        $st = $pdo->prepare("
            SELECT DISTINCT ct.id, u.full_name FROM trainer_availability a
            JOIN club_trainers ct ON ct.id = a.trainer_id AND ct.is_active = 1
            JOIN sys_users u ON u.id = ct.user_id
            WHERE a.club_id=? ORDER BY u.full_name
        ");
        $st->execute([$c['club_id']]);
        $rows = array_map(fn($t) => [['text' => $t['full_name'], 'callback_data' => "pr:{$c['id']}:{$typeId}:{$t['id']}"]], $st->fetchAll());
        $rows[] = [['text' => '« Назад', 'callback_data' => "k:{$c['id']}"]];
        self::show($chatId, $mid, '🏋️ <b>Персональне тренування</b>' . "\nОберіть тренера:", $rows);
    }

    private static function personalDays(PDO $pdo, string $chatId, ?int $mid, array $c, int $typeId, int $trainerId): void
    {
        $cfg = Booking::settings($pdo, (int)$c['club_id']);
        $st = $pdo->prepare("SELECT DISTINCT weekday FROM trainer_availability WHERE club_id=? AND trainer_id=?");
        $st->execute([$c['club_id'], $trainerId]);
        $wds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $btns = [];
        for ($i = 0; $i <= min($cfg['book_ahead_days'], 21); $i++) {
            $d = date('Y-m-d', strtotime("+{$i} days"));
            if (!in_array((int)date('N', strtotime($d)), $wds, true)) continue;
            $btns[] = ['text' => self::dayLabel($d), 'callback_data' => "pd:{$c['id']}:{$typeId}:{$trainerId}:" . str_replace('-', '', $d)];
        }
        $rows = array_chunk($btns, 3);
        $rows[] = [['text' => '« До тренерів', 'callback_data' => "pt:{$c['id']}:{$typeId}"]];
        self::show($chatId, $mid, $btns ? "🏋️ Оберіть день:" : 'У тренера немає робочих днів найближчим часом.', $rows);
    }

    private static function personalSlots(PDO $pdo, string $chatId, ?int $mid, array $c, int $typeId, int $trainerId, string $date, string $note = ''): void
    {
        $cfg = Booking::settings($pdo, (int)$c['club_id']);
        $t = $pdo->prepare("SELECT duration_min FROM class_types WHERE id=? AND club_id=? AND kind='personal'");
        $t->execute([$typeId, $c['club_id']]);
        $dur = (int)$t->fetchColumn();
        $slots = $dur ? Booking::availableSlots($pdo, (int)$c['club_id'], $trainerId, $date, $dur) : [];
        $minTs = time() + $cfg['book_close_minutes'] * 60;
        $slots = array_filter($slots, fn($s) => strtotime("{$date} {$s['start']}") > $minTs);

        $btns = array_map(fn($s) => ['text' => $s['start'], 'callback_data' => "pb:{$c['id']}:{$typeId}:{$trainerId}:" . str_replace('-', '', $date) . ':' . str_replace(':', '', $s['start'])], array_values($slots));
        $rows = array_chunk($btns, 4);
        $rows[] = [['text' => '« До днів', 'callback_data' => "pr:{$c['id']}:{$typeId}:{$trainerId}"]];
        $text = "🏋️ <b>" . self::dayLabel($date) . "</b>\n" . ($btns ? 'Оберіть час:' : 'Вільних годин немає.');
        if ($note) $text .= "\n\n" . $note;
        self::show($chatId, $mid, $text, $rows);
    }

    private static function doBookPersonal(PDO $pdo, string $chatId, ?int $mid, array $c, int $typeId, int $trainerId, string $date, string $hi): string
    {
        $start = strlen($hi) === 4 ? substr($hi, 0, 2) . ':' . substr($hi, 2, 2) : '';
        $pdo->beginTransaction();
        try {
            $res = Booking::bookPersonal($pdo, (int)$c['club_id'], $typeId, $trainerId, $date, $start, (int)$c['id'], 'client', 'telegram', 0);
            $pdo->commit();
        } catch (BookingException $e) {
            $pdo->rollBack();
            self::personalSlots($pdo, $chatId, $mid, $c, $typeId, $trainerId, $date, '⚠️ ' . self::h($e->getMessage()));
            return $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        self::myBookings($pdo, $chatId, $mid, $c, "✅ Вас записано на персональне тренування " . self::dayLabel($date) . " о {$start}.");
        return 'Вас записано ✅';
    }

    // ═════ Нагадування (cron кожні 10–15 хв) ═════════════════════

    /**
     * Нагадування за reminder_1_hours і reminder_2_hours до початку.
     * Не надсилається, якщо людина записалась уже всередині цього вікна
     * (щойно записалась — нагадувати нема сенсу). Повтори відсікає booking_reminders_log.
     */
    public static function sendReminders(PDO $pdo): int
    {
        $sent = 0;
        $clubs = $pdo->query("SELECT DISTINCT club_id FROM group_sessions WHERE status='scheduled' AND session_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 8 DAY")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($clubs as $clubId) {
            $cfg = Booking::settings($pdo, (int)$clubId);
            $h1 = $cfg['reminder_1_hours'];
            $h2 = $cfg['reminder_2_hours'];
            foreach (['r1' => $h1, 'r2' => $h2] as $kind => $hours) {
                if ($hours <= 0) continue;
                // Якщо вже настав час ближчого нагадування — дальнє не надсилаємо (одне замість двох)
                $other = $kind === 'r1' ? $h2 : $h1;
                $floor = ($other > 0 && $other < $hours) ? $other : 0;
                $st = $pdo->prepare("
                    SELECT gsc.id AS roster_id, gsc.client_id, c.telegram_id,
                           gs.name, gs.session_date, gs.start_time, u.full_name AS trainer, r.name AS room
                    FROM group_session_clients gsc
                    JOIN group_sessions gs ON gs.id = gsc.session_id
                    JOIN clients c ON c.id = gsc.client_id
                    JOIN club_trainers ct ON ct.id = gs.trainer_id
                    JOIN sys_users u ON u.id = ct.user_id
                    LEFT JOIN club_rooms r ON r.id = gs.room_id
                    WHERE gs.club_id = ? AND gs.status = 'scheduled' AND gsc.status = 'booked'
                      AND c.telegram_id IS NOT NULL
                      AND TIMESTAMP(gs.session_date, gs.start_time) > NOW() + INTERVAL ? HOUR
                      AND TIMESTAMP(gs.session_date, gs.start_time) <= NOW() + INTERVAL ? HOUR
                      AND gsc.created_at <= TIMESTAMP(gs.session_date, gs.start_time) - INTERVAL ? HOUR
                      AND NOT EXISTS (SELECT 1 FROM booking_reminders_log l WHERE l.roster_id = gsc.id AND l.kind = ?)
                ");
                $st->execute([$clubId, $floor, $hours, $hours, $kind]);
                foreach ($st->fetchAll() as $r) {
                    // Логуємо спробу незалежно від успіху (як cron_send_reminders) — без повторів
                    $pdo->prepare("INSERT IGNORE INTO booking_reminders_log (roster_id, kind) VALUES (?, ?)")->execute([$r['roster_id'], $kind]);

                    $startTs  = strtotime("{$r['session_date']} {$r['start_time']}");
                    $deadline = $startTs - $cfg['cancel_deadline_minutes'] * 60;
                    $when = date('d.m', $startTs) . ' о ' . date('H:i', $startTs);
                    $text = "⏰ Нагадування: <b>" . self::h($r['name']) . "</b> {$when}\n👤 " . self::h($r['trainer'])
                        . ($r['room'] ? ' · 📍 ' . self::h($r['room']) : '');
                    $opts = [];
                    if (time() <= $deadline) {
                        $text .= "\nНе зможете прийти — скасуйте до " . date('d.m H:i', $deadline) . '.';
                        $opts['reply_markup'] = ['inline_keyboard' => [[['text' => '❌ Скасувати запис', 'callback_data' => "x:{$r['client_id']}:{$r['roster_id']}"]]]];
                    }
                    $res = Telegram::sendMessage((string)$r['telegram_id'], $text, $opts);
                    if (!empty($res['ok'])) $sent++;
                }
            }
        }
        return $sent;
    }

    /** Повідомити записаних клієнтів, що клуб скасував заняття. */
    public static function notifySessionCanceled(PDO $pdo, int $sessionId, array $clientIds): void
    {
        if (!$clientIds) return;
        $s = $pdo->prepare("SELECT name, session_date, start_time FROM group_sessions WHERE id=?");
        $s->execute([$sessionId]);
        $sess = $s->fetch();
        if (!$sess) return;
        $in = implode(',', array_fill(0, count($clientIds), '?'));
        $c = $pdo->prepare("SELECT telegram_id FROM clients WHERE id IN ({$in}) AND telegram_id IS NOT NULL");
        $c->execute(array_values($clientIds));
        $when = date('d.m', strtotime($sess['session_date'])) . ' о ' . substr($sess['start_time'], 0, 5);
        foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $tg) {
            try {
                Telegram::sendMessage((string)$tg, "⚠️ Заняття «" . self::h($sess['name']) . "» {$when} скасовано клубом. Ваш запис анульовано, відвідування не списано.");
            } catch (Throwable $e) {
                error_log('[BookingBot] cancel notify: ' . $e->getMessage());
            }
        }
    }
}

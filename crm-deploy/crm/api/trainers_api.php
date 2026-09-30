<?php
/**
 * trainers_api.php — Модуль тренерів
 *
 * Дії (owner/manager):
 *   get_list          — список тренерів клубу
 *   get_one           — профіль тренера
 *   save              — створити/оновити профіль тренера
 *   toggle            — увімкнути/вимкнути тренера
 *   get_earnings      — нарахування тренера
 *   pay_earning       — виплатити нарахування (повністю або частково)
 *   get_rent          — оренда тренера
 *   save_rent         — додати запис оренди
 *   delete_rent       — видалити оренду
 *   get_summary       — зведення: нараховано / доступно / виплачено / оренда
 *
 * Дії (trainer — лише свої дані):
 *   my_profile        — свій профіль
 *   my_earnings       — свої нарахування
 *   my_summary        — своє зведення
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$sess   = Auth::requireAuth();
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';
$pdo    = Database::get();

$clubId  = (int)($input['club_id'] ?? $_GET['club_id'] ?? $sess['active_club_id'] ?? 0);
if (!$clubId) {
    Response::error('Не обрано клуб. Оберіть клуб у шапці та перезавантажте сторінку.', 400);
}
$userId  = (int)$sess['user_id'];
$level   = 0;

// Визначаємо рівень доступу
if ($clubId) {
    try {
        $access = Auth::requireClubAccess($sess, $clubId, 30);
        $level  = (int)($access['level'] ?? 30);
    } catch (Throwable $e) {
        // Якщо помилка при перевірці - логуємо, але не падаємо
        error_log('[trainers_api] Помилка доступу: ' . $e->getMessage());
        // Якщо користувач авторизований, даємо базовий рівень (level 30 = tренер)
        $level = 30;
    }
} else {
    // Якщо clubId не передано, беремо з сесії
    $clubId = (int)($sess['active_club_id'] ?? 0);
    if (!$clubId) {
        Response::error('Не обрано клуб. Оберіть клуб у шапці та перезавантажте сторінку.', 400);
    }
    // Повторимо перевірку доступу з отриманим clubId
    try {
        $access = Auth::requireClubAccess($sess, $clubId, 30);
        $level  = (int)($access['level'] ?? 30);
    } catch (Throwable $e) {
        error_log('[trainers_api] Помилка доступу (повтор): ' . $e->getMessage());
        $level = 30;
    }
}

$isManager = $level >= 50;

// Нарахування за завершені абонементи — доступні до виплати (див. Recalc)
Recalc::unlockExpiredTrainerEarnings($pdo, $clubId);

// Тренер має належати поточному клубу (інакше за id можна переглянути чужого)
function assertTrainerInClub(PDO $pdo, int $trainerId, int $clubId): void {
    $s = $pdo->prepare("SELECT 1 FROM club_trainers WHERE id=? AND club_id=? LIMIT 1");
    $s->execute([$trainerId, $clubId]);
    if (!$s->fetchColumn()) Response::error('Тренера не знайдено', 404);
}

// Допоміжна: знайти club_trainers.id поточного юзера
function myTrainerId(PDO $pdo, int $clubId, int $userId): int {
    $s = $pdo->prepare("SELECT id FROM club_trainers WHERE club_id=? AND user_id=? LIMIT 1");
    $s->execute([$clubId, $userId]);
    return (int)($s->fetchColumn() ?: 0);
}

try { switch ($action) {

// ════ ДІАГНОСТИКА: Показати поточний рівень доступу ════════
case 'debug_access':
    Response::ok([
        'user_id'       => $userId,
        'club_id'       => $clubId,
        'level'         => $level,
        'is_manager'    => $isManager,
        'session_data'  => [
            'active_club_id' => $sess['active_club_id'] ?? null,
            'global_level'   => $sess['global_level'] ?? null,
        ],
    ]);

// ════ СПИСОК ТРЕНЕРІВ ══════════════════════════════════════════
case 'get_list':
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();
    $stmt = $pdo->prepare("
        SELECT
            ct.id, ct.user_id, ct.specialization, ct.bio, ct.photo_url,
            ct.work_type, ct.is_active,
            ct.personal_earn_type, ct.personal_earn_value,
            ct.group_earn_rate, ct.group_earn_bonus_per_client, ct.group_bonus_threshold,
            u.full_name, u.email, u.phone, u.last_login_at,
            -- Зведення нарахувань (єдине джерело правди — trainer_earnings)
            COALESCE(SUM(te.amount),0)            AS total_earned,
            COALESCE(SUM(te.available_amount),0)  AS total_available,
            COALESCE(SUM(te.paid_amount),0)       AS total_paid,
            -- Оренда
            COALESCE((
                SELECT SUM(tr.amount) FROM trainer_rent tr
                WHERE tr.trainer_id=ct.id AND tr.status='pending'
            ),0) AS rent_pending
        FROM club_trainers ct
        JOIN sys_users u ON u.id = ct.user_id
        LEFT JOIN trainer_earnings te ON te.trainer_id = ct.id AND te.club_id = ?
        WHERE ct.club_id = ?
        GROUP BY ct.id
        ORDER BY u.full_name
    ");
    $stmt->execute([$clubId, $clubId]);
    Response::ok(['trainers' => $stmt->fetchAll()]);

// ════ ОДИН ТРЕНЕР ══════════════════════════════════════════════
case 'get_one':
    $trainerId = (int)($input['trainer_id'] ?? $_GET['trainer_id'] ?? 0);
    if (!$trainerId) Response::error('Вкажіть trainer_id');
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();

    $stmt = $pdo->prepare("
        SELECT ct.*, u.full_name, u.email, u.phone
        FROM club_trainers ct
        JOIN sys_users u ON u.id = ct.user_id
        WHERE ct.id = ? AND ct.club_id = ? LIMIT 1
    ");
    $stmt->execute([$trainerId, $clubId]);
    $trainer = $stmt->fetch();
    if (!$trainer) Response::error('Тренера не знайдено', 404);
    Response::ok(['trainer' => $trainer]);

// ════ ЗБЕРЕГТИ ПРОФІЛЬ (CREATE / UPDATE) ═══════════════════════
case 'save':
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();
    $trainerId = (int)($input['trainer_id'] ?? 0);

    // Якщо create — перевіряємо що user_id є тренером у клубі
    if (!$trainerId) {
        $targetUserId = (int)($input['user_id'] ?? 0);
        $ghostName    = trim($input['full_name'] ?? '');

        // Тренер без входу в систему: не всі тренери мають/потребують акаунт.
        // Створюємо мінімальний sys_users-профіль з непідбірним паролем і
        // синтетичним "no-login" email — без sys_user_clubs, тож він не
        // з'являється в "Команді", не займає платне місце і фізично не може
        // залогінитись (пароль — випадкові 32 байти, ніде не зберігаються).
        if (!$targetUserId && $ghostName !== '') {
            if (mb_strlen($ghostName) < 2) Response::error('Введіть ім\'я тренера');
            Billing::requireWriteAccess($clubId);

            $pdo->beginTransaction();
            try {
                $ghostEmail = sprintf('trainer.%s@no-login.local', bin2hex(random_bytes(8)));
                $pdo->prepare("
                    INSERT INTO sys_users (email, password_hash, full_name, phone, is_active)
                    VALUES (?, ?, ?, ?, 1)
                ")->execute([
                    $ghostEmail,
                    password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]),
                    htmlspecialchars($ghostName, ENT_QUOTES, 'UTF-8'),
                    trim($input['phone'] ?? '') ?: null,
                ]);
                $ghostUserId = (int)$pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO club_trainers
                        (club_id, user_id, specialization, bio, photo_url, work_type,
                         personal_earn_type, personal_earn_value,
                         personal_tier_threshold, personal_tier_value,
                         group_earn_rate, group_earn_bonus_per_client, group_bonus_threshold,
                         group_monthly_bonus_sessions, group_monthly_bonus_amount)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $clubId, $ghostUserId,
                    trim($input['specialization'] ?? '') ?: null,
                    trim($input['bio']            ?? '') ?: null,
                    trim($input['photo_url']      ?? '') ?: null,
                    in_array($input['work_type'] ?? '', ['employee','rent','both'])
                        ? $input['work_type'] : 'employee',
                    in_array($input['personal_earn_type'] ?? '', ['percent','fixed'])
                        ? $input['personal_earn_type'] : 'percent',
                    max(0, (float)($input['personal_earn_value']      ?? 50)),
                    max(0, (int)  ($input['personal_tier_threshold']  ?? 0)),
                    max(0, (float)($input['personal_tier_value']      ?? 0)),
                    max(0, (float)($input['group_earn_rate']          ?? 0)),
                    max(0, (float)($input['group_earn_bonus_per_client'] ?? 0)),
                    max(0, (int)  ($input['group_bonus_threshold']    ?? 0)),
                    max(0, (int)  ($input['group_monthly_bonus_sessions'] ?? 0)),
                    max(0, (float)($input['group_monthly_bonus_amount']   ?? 0)),
                ]);
                $newGhostId = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                Response::serverError($ex->getMessage());
            }
            Response::ok(['id' => $newGhostId], 'Тренера додано (без входу в систему)');
        }

        if (!$targetUserId) Response::error('Вкажіть тренера або введіть ім\'я');

        // Перевірка що юзер — активний член клубу з роллю trainer АБО owner
        // (власник може одночасно бути тренером — окремий профіль club_trainers,
        // незалежний від головної ролі членства)
        $chk = $pdo->prepare("
            SELECT 1 FROM sys_user_clubs uc
            JOIN sys_roles r ON r.id=uc.role_id
            WHERE uc.user_id=? AND uc.club_id=? AND r.slug IN ('trainer','owner') AND uc.is_active=1
            LIMIT 1
        ");
        $chk->execute([$targetUserId, $clubId]);
        if (!$chk->fetchColumn()) Response::error('Користувач не є учасником цього клубу');

        // Ліміт команди тут НЕ перевіряємо: user_id вже активний член клубу
        // (owner або trainer), профіль тренера — лише додаткова "роль/капелюх"
        // для вже порахованої людини, headcount не змінюється.
        Billing::requireWriteAccess($clubId);

        // Дублікат
        $dup = $pdo->prepare("SELECT id FROM club_trainers WHERE club_id=? AND user_id=? LIMIT 1");
        $dup->execute([$clubId, $targetUserId]);
        if ($dup->fetchColumn()) Response::error('Профіль тренера вже існує');

        $pdo->prepare("
            INSERT INTO club_trainers
                (club_id, user_id, specialization, bio, photo_url, work_type,
                 personal_earn_type, personal_earn_value,
                 personal_tier_threshold, personal_tier_value,
                 group_earn_rate, group_earn_bonus_per_client, group_bonus_threshold,
                 group_monthly_bonus_sessions, group_monthly_bonus_amount)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $clubId, $targetUserId,
            trim($input['specialization'] ?? '') ?: null,
            trim($input['bio']            ?? '') ?: null,
            trim($input['photo_url']      ?? '') ?: null,
            in_array($input['work_type'] ?? '', ['employee','rent','both'])
                ? $input['work_type'] : 'employee',
            in_array($input['personal_earn_type'] ?? '', ['percent','fixed'])
                ? $input['personal_earn_type'] : 'percent',
            max(0, (float)($input['personal_earn_value']      ?? 50)),
            max(0, (int)  ($input['personal_tier_threshold']  ?? 0)),
            max(0, (float)($input['personal_tier_value']      ?? 0)),
            max(0, (float)($input['group_earn_rate']          ?? 0)),
            max(0, (float)($input['group_earn_bonus_per_client'] ?? 0)),
            max(0, (int)  ($input['group_bonus_threshold']    ?? 0)),
            max(0, (int)  ($input['group_monthly_bonus_sessions'] ?? 0)),
            max(0, (float)($input['group_monthly_bonus_amount']   ?? 0)),
        ]);
        Response::ok(['id' => (int)$pdo->lastInsertId()], 'Профіль тренера створено');
    }

    // Update
    $chk = $pdo->prepare("SELECT id FROM club_trainers WHERE id=? AND club_id=? LIMIT 1");
    $chk->execute([$trainerId, $clubId]);
    if (!$chk->fetchColumn()) Response::error('Тренера не знайдено', 404);

    $pdo->prepare("
        UPDATE club_trainers SET
            specialization                = ?,
            bio                           = ?,
            photo_url                     = ?,
            work_type                     = ?,
            personal_earn_type            = ?,
            personal_earn_value           = ?,
            personal_tier_threshold       = ?,
            personal_tier_value           = ?,
            group_earn_rate               = ?,
            group_earn_bonus_per_client   = ?,
            group_bonus_threshold         = ?,
            group_monthly_bonus_sessions  = ?,
            group_monthly_bonus_amount    = ?
        WHERE id = ?
    ")->execute([
        trim($input['specialization'] ?? '') ?: null,
        trim($input['bio']            ?? '') ?: null,
        trim($input['photo_url']      ?? '') ?: null,
        in_array($input['work_type'] ?? '', ['employee','rent','both'])
            ? $input['work_type'] : 'employee',
        in_array($input['personal_earn_type'] ?? '', ['percent','fixed'])
            ? $input['personal_earn_type'] : 'percent',
        max(0, (float)($input['personal_earn_value']          ?? 50)),
        max(0, (int)  ($input['personal_tier_threshold']      ?? 0)),
        max(0, (float)($input['personal_tier_value']          ?? 0)),
        max(0, (float)($input['group_earn_rate']              ?? 0)),
        max(0, (float)($input['group_earn_bonus_per_client']  ?? 0)),
        max(0, (int)  ($input['group_bonus_threshold']        ?? 0)),
        max(0, (int)  ($input['group_monthly_bonus_sessions'] ?? 0)),
        max(0, (float)($input['group_monthly_bonus_amount']   ?? 0)),
        $trainerId,
    ]);
    Response::ok([], 'Збережено');

// ════ TOGGLE ACTIVE ════════════════════════════════════════════
case 'toggle':
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();
    $trainerId = (int)($input['trainer_id'] ?? 0);
    if (!$trainerId) Response::error('Вкажіть trainer_id');

    $cur = $pdo->prepare("SELECT is_active, user_id FROM club_trainers WHERE id=? AND club_id=? LIMIT 1");
    $cur->execute([$trainerId, $clubId]);
    $row = $cur->fetch();
    if (!$row) Response::error('Тренера не знайдено', 404);

    $new       = $row['is_active'] ? 0 : 1;
    $traineeId = (int)$row['user_id'];

    // Оновлюємо club_trainers
    $pdo->prepare("UPDATE club_trainers SET is_active=? WHERE id=?")
        ->execute([$new, $trainerId]);

    // Синхронізуємо sys_user_clubs
    $pdo->prepare("
        UPDATE sys_user_clubs SET is_active=?
        WHERE user_id=? AND club_id=?
    ")->execute([$new, $traineeId, $clubId]);

    Response::ok(['is_active' => $new], $new ? 'Тренера активовано' : 'Тренера призупинено');

// ════ НАРАХУВАННЯ ══════════════════════════════════════════════
case 'get_earnings':
    $trainerId = (int)($input['trainer_id'] ?? $_GET['trainer_id'] ?? 0);
    if (!Auth::can($sess, $clubId, 'trainers.manage') && $trainerId !== myTrainerId($pdo, $clubId, $userId)) {
        Response::forbidden();
    }
    if (!$trainerId) Response::error('Вкажіть trainer_id');
    assertTrainerInClub($pdo, $trainerId, $clubId);

    $status = trim($input['status'] ?? $_GET['status'] ?? '');
    $where  = ['te.trainer_id = ?'];
    $params = [$trainerId];
    if ($status) { $where[] = 'te.status = ?'; $params[] = $status; }

    $stmt = $pdo->prepare("
        SELECT
            te.id, te.source, te.source_id, te.invoice_id,
            te.earn_type, te.release_trigger,
            te.amount, te.available_amount, te.paid_amount,
            te.status, te.available_at, te.notes, te.created_at,
            -- Клієнт і абонемент
            ci.end_date, ci.visits_total, ci.visits_used,
            c.full_name AS client_name
        FROM trainer_earnings te
        LEFT JOIN client_invoices ci ON ci.id = te.invoice_id
        LEFT JOIN clients c ON c.id = ci.client_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY te.created_at DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    Response::ok(['earnings' => $stmt->fetchAll()]);

// ════ ВИПЛАТА НАРАХУВАННЯ ══════════════════════════════════════
case 'pay_earning':
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();
    $earnId        = (int)($input['earning_id'] ?? 0);
    $payAmount     = round((float)($input['amount'] ?? 0), 2);
    $paymentMethod = trim($input['payment_method'] ?? 'cash');
    if (!in_array($paymentMethod, ['cash', 'card'], true)) $paymentMethod = 'cash';
    if (!$earnId)    Response::error('Вкажіть earning_id');
    if ($payAmount <= 0) Response::error('Сума виплати має бути > 0');

    // Готівкова виплата — у відкриту зміну каси (інакше звіт зміни її не бачить)
    $shiftId = null;
    if ($paymentMethod === 'cash') {
        $sh = $pdo->prepare("SELECT id FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
        $sh->execute([$clubId]);
        $shiftId = (int)$sh->fetchColumn() ?: null;
    }

    $pdo->beginTransaction();
    try {
        // FOR UPDATE — два одночасні натискання "Виплатити" не проведуть виплату двічі;
        // ct.club_id — лише нарахування свого клубу.
        $row = $pdo->prepare("
            SELECT te.id, te.amount, te.available_amount, te.paid_amount, te.status,
                   te.trainer_id, u.full_name AS trainer_name
            FROM trainer_earnings te
            JOIN club_trainers ct ON ct.id = te.trainer_id
            JOIN sys_users u ON u.id = ct.user_id
            WHERE te.id=? AND ct.club_id=? LIMIT 1
            FOR UPDATE
        ");
        $row->execute([$earnId, $clubId]);
        $e = $row->fetch();
        $err = null;
        if (!$e)                           $err = 'Нарахування не знайдено';
        elseif ($e['status'] === 'locked') $err = 'Нарахування ще не доступне до виплати';
        elseif ($e['status'] === 'paid')   $err = 'Нарахування вже виплачено';
        else {
            $canPay = round($e['available_amount'] - $e['paid_amount'], 2);
            if ($payAmount > $canPay) $err = "Максимум до виплати: {$canPay} грн";
        }
        if ($err) { $pdo->rollBack(); Response::error($err); }

        $newPaid   = round($e['paid_amount'] + $payAmount, 2);
        $newStatus = $newPaid >= $e['amount'] ? 'paid' : 'partial';

        $pdo->prepare("
            UPDATE trainer_earnings SET paid_amount=?, status=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$newPaid, $newStatus, $earnId]);

        // Захищений системний запис витрати — впливає на касу (готівка) або
        // лише на облік у Фінансах (картка); редагувати/видаляти вручну не можна
        // (source != 'manual', див. finance_api.php).
        $pdo->prepare("
            INSERT INTO club_expenses
                (club_id, category, description, amount,
                 expense_date, payment_method, shift_id,
                 admin_id, admin_name, notes, source, source_id)
            VALUES (?, 'Зарплата тренера', ?, ?, CURDATE(), ?, ?, ?, ?, NULL, 'trainer_payout', ?)
        ")->execute([
            $clubId,
            "Виплата тренеру {$e['trainer_name']} (нарахування #{$earnId})",
            $payAmount, $paymentMethod, $shiftId,
            $sess['user_id'], $sess['full_name'] ?? null,
            $earnId,
        ]);
        Recalc::cashflowSyncExpense($pdo, (int)$pdo->lastInsertId());

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        Response::serverError($ex->getMessage());
    }

    Response::ok(['paid_amount' => $newPaid, 'status' => $newStatus], 'Виплату зафіксовано');

// ════ ОРЕНДА ═══════════════════════════════════════════════════
case 'get_rent':
    $trainerId = (int)($input['trainer_id'] ?? $_GET['trainer_id'] ?? 0);
    if (!Auth::can($sess, $clubId, 'trainers.manage') && $trainerId !== myTrainerId($pdo, $clubId, $userId)) {
        Response::forbidden();
    }
    if (!$trainerId) Response::error('Вкажіть trainer_id');
    assertTrainerInClub($pdo, $trainerId, $clubId);

    $stmt = $pdo->prepare("
        SELECT tr.*, u.full_name AS created_by_name
        FROM trainer_rent tr
        LEFT JOIN sys_users u ON u.id = tr.created_by
        WHERE tr.trainer_id = ?
        ORDER BY tr.period_start DESC
        LIMIT 100
    ");
    $stmt->execute([$trainerId]);
    Response::ok(['rent' => $stmt->fetchAll()]);

case 'save_rent':
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();
    $trainerId   = (int)($input['trainer_id'] ?? 0);
    $amount      = round((float)($input['amount'] ?? 0), 2);
    $periodStart = trim($input['period_start'] ?? '');
    $periodEnd   = trim($input['period_end']   ?? '');
    $rentType    = in_array($input['rent_type'] ?? '', ['manual','deduction'])
                    ? $input['rent_type'] : 'manual';

    if (!$trainerId || $amount <= 0 || !$periodStart || !$periodEnd) {
        Response::error('Заповніть усі обов\'язкові поля');
    }

    // Перевірка що тренер належить клубу
    $chk = $pdo->prepare("SELECT id FROM club_trainers WHERE id=? AND club_id=? LIMIT 1");
    $chk->execute([$trainerId, $clubId]);
    if (!$chk->fetchColumn()) Response::error('Тренера не знайдено', 404);

    $pdo->prepare("
        INSERT INTO trainer_rent
            (club_id, trainer_id, rent_type, amount, period_start, period_end, notes, created_by)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([
        $clubId, $trainerId, $rentType, $amount,
        $periodStart, $periodEnd,
        trim($input['notes'] ?? '') ?: null,
        $userId,
    ]);
    Response::ok(['id' => (int)$pdo->lastInsertId()], 'Оренду додано');

case 'delete_rent':
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();
    $rentId = (int)($input['rent_id'] ?? 0);
    if (!$rentId) Response::error('Вкажіть rent_id');

    // Перевірка що оренда належить клубу
    $chk = $pdo->prepare("
        SELECT tr.id FROM trainer_rent tr
        JOIN club_trainers ct ON ct.id=tr.trainer_id
        WHERE tr.id=? AND ct.club_id=? LIMIT 1
    ");
    $chk->execute([$rentId, $clubId]);
    if (!$chk->fetchColumn()) Response::error('Запис не знайдено', 404);

    $pdo->prepare("DELETE FROM trainer_rent WHERE id=?")->execute([$rentId]);
    Response::ok([], 'Видалено');

// ════ ЗВЕДЕННЯ ═════════════════════════════════════════════════
case 'get_summary':
    $trainerId = (int)($input['trainer_id'] ?? $_GET['trainer_id'] ?? 0);
    if (!Auth::can($sess, $clubId, 'trainers.manage') && $trainerId !== myTrainerId($pdo, $clubId, $userId)) {
        Response::forbidden();
    }
    if (!$trainerId) Response::error('Вкажіть trainer_id');
    assertTrainerInClub($pdo, $trainerId, $clubId);

    $e2 = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount),0)           AS total_earned,
            COALESCE(SUM(available_amount),0) AS total_available,
            COALESCE(SUM(paid_amount),0)      AS total_paid,
            SUM(CASE WHEN status='locked'    THEN 1 ELSE 0 END) AS cnt_locked,
            SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS cnt_available,
            SUM(CASE WHEN status='partial'   THEN 1 ELSE 0 END) AS cnt_partial
        FROM trainer_earnings WHERE trainer_id=?
    ");
    $e2->execute([$trainerId]);
    $earn = $e2->fetch();

    $r = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount),0) AS total_rent,
            SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) AS rent_pending
        FROM trainer_rent WHERE trainer_id=?
    ");
    $r->execute([$trainerId]);
    $rent = $r->fetch();

    $toPayOut = round(
        (float)$earn['total_available'] - (float)$earn['total_paid'] - (float)$rent['rent_pending'],
        2
    );

    Response::ok([
        'summary' => [
            'total_earned'    => (float)$earn['total_earned'],
            'total_available' => (float)$earn['total_available'],
            'total_paid'      => (float)$earn['total_paid'],
            'cnt_locked'      => (int)$earn['cnt_locked'],
            'cnt_available'   => (int)$earn['cnt_available'],
            'cnt_partial'     => (int)$earn['cnt_partial'],
            'rent_pending'    => (float)$rent['rent_pending'],
            'to_pay_out'      => max(0, $toPayOut),
        ],
    ]);

// ════ МОЇ ДАНІ (тренер) ════════════════════════════════════════
case 'my_profile':
    $tid = myTrainerId($pdo, $clubId, $userId);
    if (!$tid) Response::error('Профіль тренера не знайдено', 404);
    $stmt = $pdo->prepare("
        SELECT ct.*, u.full_name, u.email, u.phone
        FROM club_trainers ct JOIN sys_users u ON u.id=ct.user_id
        WHERE ct.id=? LIMIT 1
    ");
    $stmt->execute([$tid]);
    Response::ok(['trainer' => $stmt->fetch()]);

case 'my_earnings':
    $tid = myTrainerId($pdo, $clubId, $userId);
    if (!$tid) Response::error('Профіль тренера не знайдено', 404);
    $input['trainer_id'] = $tid;
    // повторно використовуємо get_earnings
    $stmt = $pdo->prepare("
        SELECT te.*, ci.end_date, ci.visits_total, ci.visits_used, c.full_name AS client_name
        FROM trainer_earnings te
        LEFT JOIN client_invoices ci ON ci.id=te.invoice_id
        LEFT JOIN clients c ON c.id=ci.client_id
        WHERE te.trainer_id=?
        ORDER BY te.created_at DESC LIMIT 200
    ");
    $stmt->execute([$tid]);
    Response::ok(['earnings' => $stmt->fetchAll()]);

case 'my_summary':
    $tid = myTrainerId($pdo, $clubId, $userId);
    if (!$tid) Response::error('Профіль тренера не знайдено', 404);
    $input['trainer_id'] = $tid;
    $action = 'get_summary';
    // fallthrough не працює — викликаємо логіку повторно
    $e2 = $pdo->prepare("
        SELECT
            COALESCE(SUM(amount),0)           AS total_earned,
            COALESCE(SUM(available_amount),0) AS total_available,
            COALESCE(SUM(paid_amount),0)      AS total_paid,
            SUM(CASE WHEN status='locked'    THEN 1 ELSE 0 END) AS cnt_locked,
            SUM(CASE WHEN status='available' THEN 1 ELSE 0 END) AS cnt_available,
            SUM(CASE WHEN status='partial'   THEN 1 ELSE 0 END) AS cnt_partial
        FROM trainer_earnings WHERE trainer_id=?
    ");
    $e2->execute([$tid]);
    $earn = $e2->fetch();
    $r = $pdo->prepare("
        SELECT COALESCE(SUM(amount),0) AS total_rent,
               SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) AS rent_pending
        FROM trainer_rent WHERE trainer_id=?
    ");
    $r->execute([$tid]);
    $rent = $r->fetch();
    $toPayOut = max(0, round(
        (float)$earn['total_available'] - (float)$earn['total_paid'] - (float)$rent['rent_pending'], 2
    ));
    Response::ok(['summary' => array_merge((array)$earn, (array)$rent, ['to_pay_out' => $toPayOut])]);

// ════ СПИСОК ЮЗЕРІВ-ТРЕНЕРІВ (для select при створенні профілю) ═
case 'get_trainer_users':
    if (!Auth::can($sess, $clubId, 'trainers.manage')) Response::forbidden();
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.email,
               (SELECT id FROM club_trainers WHERE club_id=? AND user_id=u.id LIMIT 1) AS trainer_profile_id
        FROM sys_user_clubs uc
        JOIN sys_users u ON u.id=uc.user_id
        JOIN sys_roles r ON r.id=uc.role_id
        WHERE uc.club_id=? AND r.slug IN ('trainer','owner') AND uc.is_active=1
        ORDER BY u.full_name
    ");
    $stmt->execute([$clubId, $clubId]);
    Response::ok(['users' => $stmt->fetchAll()]);

default:
    Response::error("Невідома дія: {$action}", 400);

}} catch (PDOException $e) {
    Response::serverError('DB: ' . $e->getMessage());
} catch (Throwable $e) {
    Response::serverError($e->getMessage());
}

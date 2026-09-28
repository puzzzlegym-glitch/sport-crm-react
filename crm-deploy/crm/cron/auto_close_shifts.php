<?php
/**
 * cron/auto_close_shifts.php — автоматичне закриття відкритої зміни каси
 * у час, який власник клубу задав у Налаштуваннях (sys_clubs.
 * cash_shift_auto_close_enabled / cash_shift_auto_close_time).
 *
 * CLI/cron-контекст — без Auth::requireAuth() (немає сесії користувача).
 * Порівняння часу — простий рядковий 'H:i:s'/'Y-m-d H:i:s' (локальний час
 * сервера), як і решта дат у застосунку (sys_clubs.timezone ніде в PHP не
 * використовується для конвертації — лише відображається в UI).
 * Запускати раз на 5-10 хв.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once __DIR__ . '/../classes/CashShiftService.php';

$pdo = Database::get();

$stmt = $pdo->query("
    SELECT id, owner_id, cash_shift_auto_close_time
    FROM sys_clubs
    WHERE is_active = 1
      AND cash_shift_auto_close_enabled = 1
      AND cash_shift_auto_close_time IS NOT NULL
");
$clubs = $stmt->fetchAll();

$today   = date('Y-m-d');
$nowTime = date('H:i:s');

$closed  = 0;
$skipped = 0;

foreach ($clubs as $club) {
    $closeTime = $club['cash_shift_auto_close_time'];
    if ($nowTime < $closeTime) continue; // ще не настав налаштований час

    $clubId = (int)$club['id'];
    $shift  = CashShiftService::getOpenShift($pdo, $clubId);
    if (!$shift) continue; // немає відкритої зміни — нічого закривати

    // Не чіпаємо зміну, яку відкрили вже ПІСЛЯ порогового часу сьогодні —
    // це свідома робота понад графік, а не забута зміна.
    $threshold = $today . ' ' . $closeTime;
    if ($shift['opened_at'] >= $threshold) { $skipped++; continue; }

    $balanceClose = CashShiftService::close(
        $pdo, $shift, (int)$club['owner_id'], 'Автоматичне закриття',
        'auto', 'Автоматично закрито за розкладом клубу о ' . $closeTime
    );
    $closed++;

    try {
        Telegram::notifyClubOwners(
            $clubId,
            "🕒 Зміну автоматично закрито за розкладом (" . $closeTime . "). Залишок у касі: " . number_format($balanceClose, 2) . " грн."
        );
    } catch (Throwable $e) {
        error_log('[AutoCloseShift] Telegram notify failed: ' . $e->getMessage());
    }
}

echo sprintf(
    "[%s] auto_close_shifts: %d клубів з увімкненим автозакриттям, %d закрито, %d пропущено (зміна відкрита після порогу)\n",
    date('Y-m-d H:i:s'), count($clubs), $closed, $skipped
);

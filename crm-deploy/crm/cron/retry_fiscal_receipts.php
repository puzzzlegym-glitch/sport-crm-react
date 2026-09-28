<?php
/**
 * cron/retry_fiscal_receipts.php — повторна відправка чеків Checkbox, що
 * впали з fiscal_status='failed' (ТЗ 4.3). Запускати раз на 15 хв.
 *
 * CLI/cron-контекст — без Auth::requireAuth() (немає сесії користувача).
 * Обмежено fiscal_attempts < MAX_ATTEMPTS, щоб не гамселити зовнішнє API
 * нескінченно при постійній помилці (напр. невірний ключ ліцензії).
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/../classes/CheckboxService.php';

const MAX_ATTEMPTS = 5;

$pdo = Database::get();

$stmt = $pdo->prepare('
    SELECT cp.id, cp.club_id, cp.amount, cp.payment_method, ci.tariff_name
    FROM club_payments cp
    LEFT JOIN client_invoices ci ON ci.id = cp.invoice_id
    JOIN club_prro_settings ps ON ps.club_id = cp.club_id AND ps.is_active = 1
    WHERE cp.fiscal_status = "failed" AND cp.fiscal_attempts < ?
    ORDER BY cp.created_at ASC
    LIMIT 100
');
$stmt->execute([MAX_ATTEMPTS]);
$rows = $stmt->fetchAll();

$ok = 0;
$fail = 0;

foreach ($rows as $row) {
    $description = $row['tariff_name'] ?: 'Послуги спортивного клубу';
    CheckboxService::maybeFiscalize(
        $pdo,
        (int)$row['club_id'],
        (int)$row['id'],
        $row['payment_method'],
        (float)$row['amount'],
        $description,
        false
    );

    $check = $pdo->prepare('SELECT fiscal_status FROM club_payments WHERE id = ?');
    $check->execute([$row['id']]);
    if ($check->fetchColumn() === 'sent') $ok++; else $fail++;
}

echo sprintf(
    "[%s] retry_fiscal_receipts: %d знайдено, %d успішно, %d ще не вдалось\n",
    date('Y-m-d H:i:s'), count($rows), $ok, $fail
);

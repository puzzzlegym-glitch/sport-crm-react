<?php
/**
 * CashShiftService — баланс каси та закриття зміни.
 * Спільна логіка для api/cash_api.php (ручне закриття) і
 * cron/auto_close_shifts.php (автоматичне закриття за розкладом власника),
 * щоб обидва місця рахували баланс і закривали зміну однаково.
 */

class CashShiftService
{
    // location: 'register' (денна каса/зміна) | 'safe' (сейф).
    public static function balance(PDO $pdo, int $clubId, string $location = 'register'): float
    {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN type IN('income','transfer_in') THEN amount ELSE 0 END),0) -
                   COALESCE(SUM(CASE WHEN type IN('expense','encashment','transfer_out') THEN amount ELSE 0 END),0) +
                   COALESCE(SUM(CASE WHEN type='adjustment' THEN amount ELSE 0 END),0) AS bal
            FROM club_cashflow WHERE club_id=? AND payment_method='cash' AND location=?
        ");
        $st->execute([$clubId, $location]);
        return (float)$st->fetchColumn();
    }

    public static function getOpenShift(PDO $pdo, int $clubId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
        $st->execute([$clubId]);
        return $st->fetch() ?: null;
    }

    /**
     * Закриває зміну. $counted — фактично пораховано в касі (null — без перерахунку,
     * напр. автозакриття). Різницю з очікуваним залишком записуємо коригуванням
     * «Недостача»/«Надлишок» у цю ж зміну, тож balance_close = фактичний залишок,
     * а розбіжність лишається видимою в зміні (discrepancy) і в журналі.
     */
    public static function close(PDO $pdo, array $shift, int $closedBy, string $closedName, string $reason = 'manual', ?string $notes = null, ?float $counted = null): float
    {
        $clubId      = (int)$shift['club_id'];
        $discrepancy = null;
        if ($counted !== null) {
            $expected    = self::balance($pdo, $clubId, 'register');
            $discrepancy = round($counted - $expected, 2);
            if (abs($discrepancy) >= 0.01) {
                $pdo->prepare("
                    INSERT INTO club_cashflow
                      (club_id,type,category,description,amount,payment_method,source,shift_id,location,admin_id,admin_name)
                    VALUES (?,'adjustment',?,?,?,'cash','adjustment',?,'register',?,?)
                ")->execute([
                    $clubId,
                    $discrepancy < 0 ? 'Недостача' : 'Надлишок',
                    'Перерахунок при закритті зміни: очікувалось ' . number_format($expected, 2, '.', '')
                        . ', фактично ' . number_format($counted, 2, '.', ''),
                    $discrepancy, $shift['id'], $closedBy, $closedName,
                ]);
            }
        }

        $balanceClose = self::balance($pdo, $clubId, 'register');
        $totals = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN type IN('income','transfer_in') THEN amount ELSE 0 END),0) AS inc,
                   COALESCE(SUM(CASE WHEN type IN('expense','encashment','transfer_out') THEN amount ELSE 0 END),0) AS exp,
                   COALESCE(SUM(CASE WHEN type='adjustment' THEN amount ELSE 0 END),0) AS adj
            FROM club_cashflow WHERE club_id=? AND payment_method='cash' AND location='register' AND shift_id=?
        ");
        $totals->execute([$clubId, $shift['id']]);
        $t = $totals->fetch();
        $pdo->prepare("
            UPDATE cash_shifts SET
              closed_by=?, closed_name=?, closed_at=NOW(),
              balance_close=?, balance_counted=?, discrepancy=?,
              income_shift=?, expense_shift=?, adjustments_shift=?,
              notes=COALESCE(?,notes), status='closed', closed_reason=?
            WHERE id=?
        ")->execute([
            $closedBy, $closedName, $balanceClose, $counted, $discrepancy,
            (float)$t['inc'], (float)$t['exp'], (float)$t['adj'],
            $notes, $reason, $shift['id'],
        ]);
        return $balanceClose;
    }
}

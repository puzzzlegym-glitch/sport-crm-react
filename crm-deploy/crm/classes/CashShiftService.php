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

    public static function close(PDO $pdo, array $shift, int $closedBy, string $closedName, string $reason = 'manual', ?string $notes = null): float
    {
        $balanceClose = self::balance($pdo, (int)$shift['club_id'], 'register');
        $totals = $pdo->prepare("
            SELECT COALESCE(SUM(CASE WHEN type IN('income','transfer_in') THEN amount ELSE 0 END),0) AS inc,
                   COALESCE(SUM(CASE WHEN type IN('expense','encashment','transfer_out') THEN amount ELSE 0 END),0) AS exp
            FROM club_cashflow WHERE club_id=? AND payment_method='cash' AND location='register' AND shift_id=?
        ");
        $totals->execute([$shift['club_id'], $shift['id']]);
        $t = $totals->fetch();
        $pdo->prepare("
            UPDATE cash_shifts SET
              closed_by=?, closed_name=?, closed_at=NOW(),
              balance_close=?, income_shift=?, expense_shift=?,
              notes=COALESCE(?,notes), status='closed', closed_reason=?
            WHERE id=?
        ")->execute([$closedBy, $closedName, $balanceClose, (float)$t['inc'], (float)$t['exp'], $notes, $reason, $shift['id']]);
        return $balanceClose;
    }
}

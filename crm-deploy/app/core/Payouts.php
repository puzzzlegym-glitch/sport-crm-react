<?php
/**
 * Payouts.php — оренда тренерів (оплата / утримання з виплати) і сторно виплат.
 *
 * Облік:
 *   виплата тренеру / зарплата персоналу = захищена витрата club_expenses
 *     (source trainer_payout / staff_payroll), готівка дзеркалиться в club_cashflow;
 *   оплата оренди = trainer_rent_payments; готівка — прихід у касу
 *     (club_cashflow source='trainer_rent').
 *   Утримання оренди з готівкової виплати: витрата на повну суму + прихід оренди
 *     готівкою → у касі мінус рівно сума "до видачі".
 * Сторно — лише власник, з причиною; все знімається і пишеться в payout_reversals.
 */
class Payouts
{
    public const RENT_METHODS = ['cash' => 'Готівка', 'card' => 'Картка', 'transfer' => 'Рахунок'];

    private static function openShiftId(PDO $pdo, int $clubId): ?int
    {
        $st = $pdo->prepare("SELECT id FROM cash_shifts WHERE club_id=? AND status='open' LIMIT 1");
        $st->execute([$clubId]);
        return (int)$st->fetchColumn() ?: null;
    }

    /** Прихід готівки в касу (у відкриту зміну). */
    private static function cashIncome(PDO $pdo, int $clubId, float $amount, string $category, string $desc, string $source, int $sourceId, array $sess): void
    {
        $pdo->prepare("
            INSERT INTO club_cashflow
              (club_id, type, category, description, amount, payment_method, source, source_id, shift_id, location, admin_id, admin_name)
            VALUES (?, 'income', ?, ?, ?, 'cash', ?, ?, ?, 'register', ?, ?)
        ")->execute([$clubId, $category, $desc, $amount, $source, $sourceId, self::openShiftId($pdo, $clubId), $sess['user_id'] ?? null, $sess['full_name'] ?? null]);
    }

    private static function recalcRent(PDO $pdo, int $rentId): void
    {
        $pdo->prepare("
            UPDATE trainer_rent r SET
              r.paid_amount = (SELECT COALESCE(SUM(p.amount),0) FROM trainer_rent_payments p WHERE p.rent_id = r.id),
              r.status = CASE
                  WHEN (SELECT COALESCE(SUM(p.amount),0) FROM trainer_rent_payments p WHERE p.rent_id = r.id) >= r.amount - 0.001 THEN 'paid'
                  WHEN (SELECT COALESCE(SUM(p.amount),0) FROM trainer_rent_payments p WHERE p.rent_id = r.id) > 0 THEN 'partial'
                  ELSE 'pending' END,
              r.paid_at = CASE WHEN (SELECT COALESCE(SUM(p.amount),0) FROM trainer_rent_payments p WHERE p.rent_id = r.id) >= r.amount - 0.001 THEN NOW() ELSE NULL END
            WHERE r.id = ?
        ")->execute([$rentId]);
    }

    /** Залишок оренди тренера за типом ('manual' | 'deduction'). */
    public static function rentRemaining(PDO $pdo, int $trainerId, string $type): float
    {
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount - paid_amount),0) FROM trainer_rent WHERE trainer_id=? AND rent_type=? AND status <> 'paid'");
        $st->execute([$trainerId, $type]);
        return max(0, round((float)$st->fetchColumn(), 2));
    }

    /** Тренер сам сплатив оренду (кнопка «Оплачено»). */
    public static function payRent(PDO $pdo, int $clubId, int $rentId, float $amount, string $method, array $sess, ?string $notes = null): float
    {
        if (!isset(self::RENT_METHODS[$method])) throw new RuntimeException('Невірний спосіб оплати');
        $st = $pdo->prepare("
            SELECT r.*, u.full_name AS trainer_name FROM trainer_rent r
            JOIN club_trainers ct ON ct.id = r.trainer_id JOIN sys_users u ON u.id = ct.user_id
            WHERE r.id=? AND ct.club_id=? FOR UPDATE
        ");
        $st->execute([$rentId, $clubId]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('Запис оренди не знайдено');
        $left = round((float)$r['amount'] - (float)$r['paid_amount'], 2);
        if ($left <= 0) throw new RuntimeException('Оренду вже сплачено');
        if ($amount <= 0 || $amount > $left + 0.001) throw new RuntimeException("Сума має бути від 0 до {$left} грн");

        $pdo->prepare("
            INSERT INTO trainer_rent_payments (club_id, rent_id, trainer_id, amount, payment_method, source, admin_id, admin_name, notes)
            VALUES (?,?,?,?,?, 'manual', ?,?,?)
        ")->execute([$clubId, $rentId, $r['trainer_id'], $amount, $method, $sess['user_id'] ?? null, $sess['full_name'] ?? null, $notes]);
        $payId = (int)$pdo->lastInsertId();
        if ($method === 'cash') {
            self::cashIncome($pdo, $clubId, $amount, 'Оренда тренера', "Оренда: {$r['trainer_name']}", 'trainer_rent', $payId, $sess);
        }
        self::recalcRent($pdo, $rentId);
        return round($left - $amount, 2);
    }

    /**
     * Утримати оренду (rent_type='deduction') з виплати тренеру — найстаріші першими.
     * Повертає утриману суму (≤ $gross).
     */
    public static function deductRent(PDO $pdo, int $clubId, int $trainerId, float $gross, string $method, int $expenseId, string $trainerName, array $sess): float
    {
        $st = $pdo->prepare("
            SELECT id, amount, paid_amount FROM trainer_rent
            WHERE trainer_id=? AND rent_type='deduction' AND status <> 'paid'
            ORDER BY period_start, id FOR UPDATE
        ");
        $st->execute([$trainerId]);
        $left = $gross;
        $total = 0.0;
        foreach ($st->fetchAll() as $r) {
            if ($left <= 0.001) break;
            $take = round(min($left, (float)$r['amount'] - (float)$r['paid_amount']), 2);
            if ($take <= 0) continue;
            $pdo->prepare("
                INSERT INTO trainer_rent_payments (club_id, rent_id, trainer_id, amount, payment_method, source, expense_id, admin_id, admin_name)
                VALUES (?,?,?,?,?, 'deduction', ?, ?,?)
            ")->execute([$clubId, $r['id'], $trainerId, $take, $method, $expenseId, $sess['user_id'] ?? null, $sess['full_name'] ?? null]);
            $payId = (int)$pdo->lastInsertId();
            if ($method === 'cash') {
                self::cashIncome($pdo, $clubId, $take, 'Оренда тренера', "Оренда (утримано з виплати): {$trainerName}", 'trainer_rent', $payId, $sess);
            }
            self::recalcRent($pdo, (int)$r['id']);
            $left  -= $take;
            $total += $take;
        }
        return round($total, 2);
    }

    private static function logReversal(PDO $pdo, int $clubId, string $kind, int $refId, float $amount, ?string $method, ?string $desc, string $reason, array $sess): void
    {
        $pdo->prepare("
            INSERT INTO payout_reversals (club_id, kind, ref_id, amount, payment_method, description, reason, reversed_by, reversed_by_name)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([$clubId, $kind, $refId, $amount, $method, $desc, $reason, $sess['user_id'] ?? null, $sess['full_name'] ?? null]);
    }

    private static function deleteRentPayment(PDO $pdo, array $p): void
    {
        $pdo->prepare("DELETE FROM club_cashflow WHERE source='trainer_rent' AND source_id=?")->execute([$p['id']]);
        $pdo->prepare("DELETE FROM trainer_rent_payments WHERE id=?")->execute([$p['id']]);
        self::recalcRent($pdo, (int)$p['rent_id']);
    }

    private static function deleteExpense(PDO $pdo, int $expenseId): void
    {
        $pdo->prepare("DELETE FROM club_expenses WHERE id=?")->execute([$expenseId]);
        Recalc::cashflowSyncExpense($pdo, $expenseId); // запису вже немає → рядок каси видаляється
    }

    private static function loadExpense(PDO $pdo, int $clubId, int $expenseId, string $source): array
    {
        $st = $pdo->prepare("SELECT * FROM club_expenses WHERE id=? AND club_id=? AND source=? FOR UPDATE");
        $st->execute([$expenseId, $clubId, $source]);
        $e = $st->fetch();
        if (!$e) throw new RuntimeException('Виплату не знайдено');
        return $e;
    }

    /** Сторно виплати тренеру: нарахування знову до виплати, утримана оренда повертається в борг. */
    public static function reverseTrainerPayout(PDO $pdo, int $clubId, int $expenseId, string $reason, array $sess): void
    {
        $e = self::loadExpense($pdo, $clubId, $expenseId, 'trainer_payout');
        $st = $pdo->prepare("SELECT * FROM trainer_earnings WHERE id=? FOR UPDATE");
        $st->execute([$e['source_id']]);
        $earn = $st->fetch();
        if ($earn) {
            $paid = max(0, round((float)$earn['paid_amount'] - (float)$e['amount'], 2));
            $status = $paid > 0 ? 'partial' : ((float)$earn['available_amount'] > 0 ? 'available' : 'locked');
            $pdo->prepare("UPDATE trainer_earnings SET paid_amount=?, status=?, updated_at=NOW() WHERE id=?")
                ->execute([$paid, $status, $earn['id']]);
        }
        $rp = $pdo->prepare("SELECT * FROM trainer_rent_payments WHERE expense_id=?");
        $rp->execute([$expenseId]);
        foreach ($rp->fetchAll() as $p) self::deleteRentPayment($pdo, $p);

        self::deleteExpense($pdo, $expenseId);
        self::logReversal($pdo, $clubId, 'trainer_payout', $expenseId, (float)$e['amount'], $e['payment_method'], $e['description'], $reason, $sess);
    }

    /** Сторно виплати зарплати персоналу. */
    public static function reverseStaffPayout(PDO $pdo, int $clubId, int $expenseId, string $reason, array $sess): void
    {
        $e = self::loadExpense($pdo, $clubId, $expenseId, 'staff_payroll');
        $st = $pdo->prepare("SELECT * FROM staff_payroll WHERE id=? AND club_id=? FOR UPDATE");
        $st->execute([$e['source_id'], $clubId]);
        $p = $st->fetch();
        if ($p) {
            $paid = max(0, round((float)$p['paid_amount'] - (float)$e['amount'], 2));
            $status = $paid <= 0 ? 'pending' : ($paid >= (float)$p['total_amount'] ? 'paid' : 'partial');
            $pdo->prepare("UPDATE staff_payroll SET paid_amount=?, status=?, updated_at=NOW() WHERE id=?")
                ->execute([$paid, $status, $p['id']]);
        }
        self::deleteExpense($pdo, $expenseId);
        self::logReversal($pdo, $clubId, 'staff_payroll', $expenseId, (float)$e['amount'], $e['payment_method'], $e['description'], $reason, $sess);
    }

    /** Сторно оплати оренди, яку тренер вносив сам (утримання знімається лише разом з виплатою). */
    public static function reverseRentPayment(PDO $pdo, int $clubId, int $paymentId, string $reason, array $sess): void
    {
        $st = $pdo->prepare("SELECT * FROM trainer_rent_payments WHERE id=? AND club_id=? FOR UPDATE");
        $st->execute([$paymentId, $clubId]);
        $p = $st->fetch();
        if (!$p) throw new RuntimeException('Оплату не знайдено');
        if ($p['source'] !== 'manual') throw new RuntimeException('Утримання з виплати скасовується разом зі сторно самої виплати');
        self::deleteRentPayment($pdo, $p);
        self::logReversal($pdo, $clubId, 'trainer_rent', $paymentId, (float)$p['amount'], $p['payment_method'], 'Оплата оренди', $reason, $sess);
    }
}

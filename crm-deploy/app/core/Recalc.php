<?php
/**
 * Recalc.php — уся похідна/каскадна логіка, що раніше жила в MySQL-тригерах
 * (clients.phone_normalized, clients.balance, client_invoices.paid_amount/
 * status/visits_used, trainer_earnings, club_cashflow, saas-білінг,
 * products.stock_qty). Усі тригери видалено 2026-09-12 (див.
 * sql/2026-09-12_drop_all_remaining_triggers.sql) — це єдине джерело
 * істини для цієї логіки.
 */
class Recalc
{
    public static function normalizePhone(?string $phone): ?string
    {
        return $phone === null || $phone === '' ? null : preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Єдина точка коригування залишку товару на складі (раніше — 6 різних
     * тригерів на product_arrivals/product_sales, кожен зі своєю копією
     * знаку +/-). Викликати з дельтою напряму: додатне число = прихід на
     * склад, від'ємне = списання. Один product_id — один UPDATE, простіше
     * і прозоріше за тригер, що ганявся за OLD/NEW і operation/status.
     */
    public static function adjustStock(PDO $pdo, ?int $productId, float $delta): void
    {
        if (!$productId || $delta == 0.0) return;
        $pdo->prepare("UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?")
            ->execute([$delta, $productId]);
    }

    /** Знак впливу на склад для операції приходу: arrival=+, overdue/repack/transfer=-. */
    public static function arrivalStockSign(string $operation): int
    {
        return $operation === 'arrival' ? 1 : -1;
    }

    public static function clientBalance(PDO $pdo, int $clientId): void
    {
        $pdo->prepare("
            UPDATE clients
            SET balance = (SELECT COALESCE(SUM(amount), 0) FROM client_deposits WHERE client_id = ?)
            WHERE id = ?
        ")->execute([$clientId, $clientId]);
    }

    public static function invoicePaidAmount(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare("
            UPDATE client_invoices
            SET paid_amount = (SELECT COALESCE(SUM(amount), 0) FROM club_payments WHERE invoice_id = ?)
            WHERE id = ?
        ")->execute([$invoiceId, $invoiceId]);
    }

    public static function invoiceVisitsUsed(PDO $pdo, int $invoiceId): void
    {
        $pdo->prepare("
            UPDATE client_invoices
            SET visits_used = (SELECT COUNT(*) FROM visits WHERE invoice_id = ?)
            WHERE id = ?
        ")->execute([$invoiceId, $invoiceId]);
        // 'expired' ставить Attendance::recordVisit, коли ліміт вичерпано. Якщо після
        // видалення відмітки заняття знову є — повертаємо 'active', інакше клієнта
        // не пустить на вхід (scan/check_in шукають лише status='active').
        $pdo->prepare("
            UPDATE client_invoices SET status = 'active'
            WHERE id = ? AND status = 'expired'
              AND visits_total IS NOT NULL AND visits_used < visits_total
        ")->execute([$invoiceId]);
        self::unlockInvoiceTrainerEarnings($pdo, $invoiceId);
    }

    /**
     * Розморожує абонементи, у яких запланований термін заморозки
     * (freeze_start + freeze_days) уже минув, а адміністратор не натиснув
     * "розморозити" вручну. Без цього ci.status лишається 'frozen' назавжди
     * (це ручний стан — не рахується EFFECTIVE_STATUS_SQL по датах), через
     * що абонемент "зависає" замороженим і після власного end_date замість
     * природного переходу active→finished. end_date вже враховує повний
     * запланований freeze_days, тож при природному спливанні терміну
     * коригувати його не потрібно (на відміну від ручного дострокового
     * розморожування в invoices_api.php:freeze). Викликати на початку
     * кожного запиту, що читає/фільтрує client_invoices.status для клубу.
     */
    public static function autoUnfreezeExpired(PDO $pdo, int $clubId): void
    {
        $pdo->prepare("
            UPDATE client_invoices
            SET status = 'active', freeze_start = NULL
            WHERE club_id = ?
              AND status = 'frozen'
              AND freeze_start IS NOT NULL
              AND DATE_ADD(freeze_start, INTERVAL freeze_days DAY) <= CURDATE()
        ")->execute([$clubId]);
    }

    /**
     * Викликається після зміни оплат абонемента (ПІСЛЯ invoicePaidAmount()).
     * Колишня логіка тригерів trg_payment_status_* (активний↔скасований за
     * paid_amount vs price) прибрана — див. коментар у тілі.
     */
    public static function invoiceStatus(PDO $pdo, int $invoiceId): void
    {
        // Статус абонемента більше НЕ залежить від суми оплати: часткова оплата
        // (продаж з боргом) лишає абонемент активним, борг видно окремо
        // (price - paid_amount). Раніше неповна оплата ставила 'cancelled' —
        // клієнта не пускало на вхід, а ручне скасування власником могло
        // "ожити" після редагування оплати. 'cancelled' тепер — лише ручна дія
        // (invoices_api: cancel/restore).
        self::unlockInvoiceTrainerEarnings($pdo, $invoiceId);
    }

    /**
     * Категорія 2 (Фаза 3): розблокування trainer_earnings при завершенні
     * абонемента, об'єднує обидва IF-блоки trg_invoice_trainer_earning
     * (client_invoices AFTER UPDATE) в один ідемпотентний перерахунок за
     * ПОТОЧНИМ станом рядка (не порівнює OLD/NEW — і не мусить: обидва
     * UPDATE нижче самі захищені WHERE-умовою на status, тож повторний
     * виклик з тим самим станом нічого не змінює). Викликати після БУДЬ-
     * якої зміни client_invoices.status/visits_used/end_date.
     */
    public static function unlockInvoiceTrainerEarnings(PDO $pdo, int $invoiceId): void
    {
        $row = $pdo->prepare("SELECT status, visits_total, visits_used, end_date FROM client_invoices WHERE id = ?");
        $row->execute([$invoiceId]);
        $ci = $row->fetch();
        if (!$ci) return;

        $completed = in_array($ci['status'], ['expired', 'cancelled'], true)
            || ($ci['visits_total'] !== null && (int)$ci['visits_used'] >= (int)$ci['visits_total'])
            || ($ci['status'] === 'active' && $ci['end_date'] < date('Y-m-d'));

        if ($completed) {
            $pdo->prepare("
                UPDATE trainer_earnings SET
                    available_amount = amount,
                    status = CASE
                        WHEN paid_amount >= amount THEN 'paid'
                        WHEN paid_amount > 0       THEN 'partial'
                        ELSE 'available'
                    END,
                    available_at = NOW(),
                    updated_at   = NOW()
                WHERE invoice_id = ?
                  AND release_trigger IN ('on_visits_done', 'on_end_date')
                  AND status NOT IN ('paid')
            ")->execute([$invoiceId]);
        }

        if ($ci['status'] === 'active') {
            $pdo->prepare("
                UPDATE trainer_earnings SET
                    available_amount = amount,
                    status       = CASE WHEN paid_amount >= amount THEN 'paid' ELSE 'available' END,
                    available_at = NOW(),
                    updated_at   = NOW()
                WHERE invoice_id    = ?
                  AND release_trigger = 'on_sale'
                  AND status = 'locked'
            ")->execute([$invoiceId]);
        }
    }

    /**
     * Категорія 2 (Фаза 3): каскад SaaS-білінгу, об'єднує
     * trg_saas_payment_after_insert (paid) і trg_saas_payment_after_delete
     * (draft + past_due) в один ідемпотентний перерахунок за SUM(success).
     * Викликати після будь-якої зміни/видалення рядка saas_payments.
     */
    public static function saasInvoiceStatus(PDO $pdo, int $invoiceId): void
    {
        $sumStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM saas_payments
            WHERE invoice_id = ? AND status = 'success'
        ");
        $sumStmt->execute([$invoiceId]);
        $totalPaid = (float)$sumStmt->fetchColumn();

        $invStmt = $pdo->prepare("SELECT amount, club_id FROM saas_invoices WHERE id = ?");
        $invStmt->execute([$invoiceId]);
        $invoice = $invStmt->fetch();
        if (!$invoice) return;

        if ($totalPaid >= (float)$invoice['amount']) {
            $pdo->prepare("
                UPDATE saas_invoices SET status = 'paid', paid_at = NOW()
                WHERE id = ? AND status != 'paid'
            ")->execute([$invoiceId]);
        } else {
            $pdo->prepare("
                UPDATE saas_invoices SET status = 'draft', paid_at = NULL
                WHERE id = ? AND status = 'paid'
            ")->execute([$invoiceId]);
            $pdo->prepare("
                UPDATE saas_subscriptions SET status = 'past_due'
                WHERE club_id = ? AND status = 'active'
            ")->execute([$invoice['club_id']]);
            $pdo->prepare("
                UPDATE sys_clubs SET subscription_status = 'past_due' WHERE id = ?
            ")->execute([$invoice['club_id']]);
        }
    }

    /**
     * Категорія 2 (Фаза 3): дзеркало каси (club_cashflow). НА ВІДМІНУ від
     * усіх методів вище — це НЕ ідемпотентний перерахунок, а вставка/зняття
     * рядка. trg_cashflow_expense_x, trg_cashflow_payment_x, trg_cashflow_sale_x
     * роблять просто INSERT — дублювати їх у PHP, поки тригер активний, дало б ДРУГИЙ
     * рядок у журналі на кожну подію. Тому ці 3 методи використовують
     * INSERT ... ON DUPLICATE KEY UPDATE, що вимагає
     * UNIQUE KEY uniq_cashflow_source (source, source_id) —
     * див. sql/2026-09-12_club_cashflow_unique_source.sql. Якщо тригер
     * встиг вставити рядок першим — цей upsert лише оновить його (не
     * подвоїть); ЦЮ МІГРАЦІЮ ТРЕБА ЗАСТОСУВАТИ НА ПРОДІ ДО того, як цей
     * код почне виконуватись там, інакше ON DUPLICATE KEY нічого не
     * ловитиме і рядки таки задвояться.
     *
     * Кожен метод приймає лише ID рядка-джерела (а не готовий масив полів)
     * і сам читає актуальний стан з БД — так виклик після INSERT/UPDATE/
     * DELETE джерела виглядає однаково в усіх місцях виклику.
     */
    public static function cashflowSyncExpense(PDO $pdo, int $expenseId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM club_expenses WHERE id = ?");
        $stmt->execute([$expenseId]);
        $row = $stmt->fetch();

        if (!$row || $row['payment_method'] !== 'cash') {
            $pdo->prepare("DELETE FROM club_cashflow WHERE source = 'expense' AND source_id = ?")
                ->execute([$expenseId]);
            return;
        }

        $pdo->prepare("
            INSERT INTO club_cashflow
              (club_id, type, category, description, amount, payment_method,
               source, source_id, shift_id, admin_id, admin_name)
            VALUES (?, 'expense', ?, ?, ?, 'cash', 'expense', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              club_id = VALUES(club_id), category = VALUES(category),
              description = VALUES(description), amount = VALUES(amount),
              shift_id = VALUES(shift_id), admin_id = VALUES(admin_id), admin_name = VALUES(admin_name)
        ")->execute([
            $row['club_id'], $row['category'], $row['description'], $row['amount'],
            $expenseId, $row['shift_id'], $row['admin_id'], $row['admin_name'],
        ]);
    }

    public static function cashflowSyncPayment(PDO $pdo, int $paymentId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM club_payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch();

        if (!$row || $row['payment_method'] !== 'cash') {
            $pdo->prepare("DELETE FROM club_cashflow WHERE source = 'invoice' AND source_id = ?")
                ->execute([$paymentId]);
            return;
        }

        $pdo->prepare("
            INSERT INTO club_cashflow
              (club_id, type, category, description, amount, payment_method,
               source, source_id, shift_id, admin_id, admin_name)
            VALUES (?, 'income', 'Абонемент', 'Оплата абонементу', ?, 'cash', 'invoice', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              club_id = VALUES(club_id), amount = VALUES(amount),
              shift_id = VALUES(shift_id), admin_id = VALUES(admin_id), admin_name = VALUES(admin_name)
        ")->execute([
            $row['club_id'], $row['amount'], $paymentId,
            $row['shift_id'], $row['admin_id'], $row['admin_name'],
        ]);
    }

    public static function cashflowSyncSale(PDO $pdo, int $saleId): void
    {
        $stmt = $pdo->prepare("SELECT * FROM product_sales WHERE id = ?");
        $stmt->execute([$saleId]);
        $row = $stmt->fetch();

        if (!$row || $row['payment_method'] !== 'cash') {
            $pdo->prepare("DELETE FROM club_cashflow WHERE source = 'product_sale' AND source_id = ?")
                ->execute([$saleId]);
            return;
        }

        $pdo->prepare("
            INSERT INTO club_cashflow
              (club_id, type, category, description, amount, payment_method,
               source, source_id, shift_id, admin_id, admin_name)
            VALUES (?, 'income', 'Товар', ?, ?, 'cash', 'product_sale', ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              club_id = VALUES(club_id), description = VALUES(description), amount = VALUES(amount),
              shift_id = VALUES(shift_id), admin_id = VALUES(admin_id), admin_name = VALUES(admin_name)
        ")->execute([
            $row['club_id'], 'Продаж: ' . $row['product_name'], $row['total_amount'], $saleId,
            $row['shift_id'], $row['admin_id'], $row['admin_name'],
        ]);
    }
}

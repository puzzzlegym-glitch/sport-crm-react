<?php
// Цей фрагмент вставити в billing_api.php у switch блок

    // ════ ЗМІНА ПЛАНУ З ПЕРЕРАХУНКОМ ══════════════════════════
    case 'change_plan':
        $planId = (int)($input['plan_id'] ?? 0);
        $yearly = (bool)($input['yearly'] ?? false);
        if (!$planId) Response::error('Оберіть план');

        // Перевіряємо план
        $planStmt = $pdo->prepare("
            SELECT id, name, price_monthly FROM saas_plans
            WHERE id=? AND is_active=1
        ");
        $planStmt->execute([$planId]);
        $plan = $planStmt->fetch();
        if (!$plan) Response::error('План не знайдено');

        // Поточна підписка
        $subStmt = $pdo->prepare("
            SELECT status, trial_ends_at, current_period_end, plan_id
            FROM saas_subscriptions WHERE club_id=? LIMIT 1
        ");
        $subStmt->execute([$clubId]);
        $sub = $subStmt->fetch();

        // Не дозволяємо міняти на той самий план
        if ($sub && (int)$sub['plan_id'] === $planId) {
            Response::error('Це вже ваш поточний план');
        }

        // Розраховуємо суму
        $months = $yearly ? 12 : 1;
        $amount = Billing::calcAmount((float)$plan['price_monthly'], $months, $yearly);

        // Активуємо (тріал залишок додається всередині Billing::activate)
        Billing::changePlan($clubId, $planId);

        $label = $plan['name'] . ($yearly ? ' (річна)' : ' (' . $months . ' міс.)');
        Response::ok([
            'plan_name' => $plan['name'],
            'amount'    => $amount,
            'yearly'    => $yearly,
            'months'    => $months,
        ], "План змінено на «{$label}»");

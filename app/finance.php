<?php
declare(strict_types=1);

function instruction_cost_totals(array $hourRows): array {
    $totals = [
        'hours' => 0.0,
        'approved_cost' => 0.0,
        'pending_cost' => 0.0,
    ];

    foreach ($hourRows as $row) {
        $hours = (float)($row['hours'] ?? 0);
        $costPerHour = (float)($row['cost_per_hour'] ?? 0);
        $lineCost = $hours * $costPerHour;
        $totals['hours'] += $hours;

        if ((int)($row['approved'] ?? 0) === 1) {
            $totals['approved_cost'] += $lineCost;
        } else {
            $totals['pending_cost'] += $lineCost;
        }
    }

    return $totals;
}

function financial_projection(mixed $revenue, mixed $costs, mixed $targetMarginPercent, mixed $contractCount, mixed $averageMonthlyValue = null): array {
    $inputs = ['revenue' => $revenue, 'costs' => $costs, 'target margin' => $targetMarginPercent];
    if ($averageMonthlyValue !== null) {
        $inputs['average monthly contract value'] = $averageMonthlyValue;
    }
    foreach ($inputs as $label => $value) {
        if (!is_numeric($value) || !is_finite((float)$value)) {
            throw new InvalidArgumentException('Valor inválido para ' . $label . '.');
        }
    }

    $revenue = (float)$revenue;
    $costs = (float)$costs;
    $targetMarginPercent = (float)$targetMarginPercent;
    if ($revenue < 0 || $costs < 0) {
        throw new InvalidArgumentException('Receita e custos não podem ser negativos.');
    }
    if ($targetMarginPercent < 0 || $targetMarginPercent >= 100) {
        throw new InvalidArgumentException('A margem-alvo deve estar entre 0% e menos de 100%.');
    }
    if ($averageMonthlyValue !== null && (float)$averageMonthlyValue < 0) {
        throw new InvalidArgumentException('O ticket médio mensal não pode ser negativo.');
    }
    if (!is_int($contractCount) && !is_string($contractCount)) {
        throw new InvalidArgumentException('A quantidade de contratos deve ser um inteiro não negativo.');
    }
    $contractCountRaw = (string)$contractCount;
    if (!preg_match('/\A\d+\z/', $contractCountRaw)) {
        throw new InvalidArgumentException('A quantidade de contratos deve ser um inteiro não negativo.');
    }
    $contractCountCanonical = ltrim($contractCountRaw, '0');
    $contractCountCanonical = $contractCountCanonical === '' ? '0' : $contractCountCanonical;
    $validatedContractCount = filter_var($contractCountCanonical, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($validatedContractCount === false) {
        throw new InvalidArgumentException('A quantidade de contratos está fora do limite suportado.');
    }

    $contractCount = $validatedContractCount;
    $operatingResult = $revenue - $costs;
    $currentMarginPercent = $revenue > 0 ? ($operatingResult / $revenue) * 100 : null;
    $requiredRevenue = $costs / (1 - ($targetMarginPercent / 100));
    $additionalRevenue = max(0.0, $requiredRevenue - $revenue);
    $averageRevenuePerContract = $averageMonthlyValue !== null
        ? (float)$averageMonthlyValue
        : ($contractCount > 0 ? $revenue / $contractCount : null);
    $additionalContracts = 0;

    if ($additionalRevenue > 0) {
        $additionalContracts = $averageRevenuePerContract !== null && $averageRevenuePerContract > 0
            ? (int)ceil(($additionalRevenue / $averageRevenuePerContract) - 1e-12)
            : null;
    }

    return [
        'operating_result' => round($operatingResult, 2),
        'current_margin_percent' => $currentMarginPercent === null ? null : round($currentMarginPercent, 2),
        'required_revenue' => round($requiredRevenue, 2),
        'additional_revenue' => round($additionalRevenue, 2),
        'average_revenue_per_contract' => $averageRevenuePerContract === null ? null : round($averageRevenuePerContract, 2),
        'additional_contracts' => $additionalContracts,
        'target_margin_percent' => $targetMarginPercent,
    ];
}

function contract_monthly_revenue(array $contract, string $month): array {
    if (!preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $month)) {
        throw new InvalidArgumentException('Competência inválida.');
    }

    $monthStart = new DateTimeImmutable($month . '-01');
    $monthEnd = $monthStart->modify('last day of this month');
    $daysInMonth = (int)$monthEnd->format('j');
    $parseDate = static function (mixed $raw): array {
        if ($raw === null || $raw === '') {
            return [null, false, true];
        }
        if (!is_string($raw) && !is_int($raw)) {
            return [null, true, false];
        }
        $value = trim((string)$raw);
        if ($value === '') {
            return [null, false, true];
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        $valid = $date !== false
            && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
        return [$valid ? $date : null, true, $valid];
    };

    $amountRaw = $contract['monthly_value'] ?? null;
    if (!is_numeric($amountRaw) || !is_finite((float)$amountRaw) || (float)$amountRaw < 0) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'invalid_monthly_value'];
    }

    $status = mb_strtolower(trim((string)($contract['status'] ?? '')), 'UTF-8');
    $isDischarged = $status === 'desligado';
    if (!in_array($status, ['ativo', 'desligado'], true)) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'invalid_status'];
    }
    [$terminationDate, $hasTerminationValue, $terminationValid] = $parseDate($contract['termination_date'] ?? null);
    if ($hasTerminationValue && !$terminationValid) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'invalid_termination_date'];
    }
    if ($isDischarged && !$terminationDate) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'missing_termination_date'];
    }
    if (!$isDischarged && $terminationDate) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'status_date_conflict'];
    }

    [$startDate, $hasStartValue, $startValid] = $parseDate($contract['start_date'] ?? null);
    if ($hasStartValue && !$startValid) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'invalid_start_date'];
    }
    [$endDate, $hasEndValue, $endValid] = $parseDate($contract['end_date'] ?? null);
    if ($hasEndValue && !$endValid) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'invalid_end_date'];
    }
    if ($startDate && $terminationDate && $terminationDate < $startDate) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'termination_before_start'];
    }
    if ($startDate && $endDate && $endDate < $startDate) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => 'invalid_contract_period'];
    }

    $activeStart = $startDate && $startDate > $monthStart ? $startDate : $monthStart;
    $activeEnd = $endDate && $endDate < $monthEnd ? $endDate : $monthEnd;
    if ($isDischarged && $terminationDate && $terminationDate < $activeEnd) {
        $activeEnd = $terminationDate;
    }
    if ($activeEnd < $activeStart) {
        return ['amount' => 0.0, 'active_days' => 0, 'recognized' => false, 'pending_reason' => null];
    }

    $activeDays = $activeStart->diff($activeEnd)->days + 1;
    $monthlyValue = (float)$amountRaw;
    return [
        'amount' => round($monthlyValue * $activeDays / $daysInMonth, 2),
        'active_days' => $activeDays,
        'recognized' => true,
        'pending_reason' => null,
    ];
}

function contract_monthly_candidate_where(string $alias = 'c', bool $hasTerminationDateColumn = true): string {
    if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $alias)) {
        throw new InvalidArgumentException('Alias SQL inválido.');
    }

    $where = "({$alias}.start_date IS NULL OR {$alias}.start_date <= ?)
              AND ({$alias}.end_date IS NULL OR {$alias}.end_date >= ?)";
    if ($hasTerminationDateColumn) {
        $where .= " AND (LOWER(TRIM(COALESCE({$alias}.status, ''))) <> 'desligado'
                         OR {$alias}.termination_date IS NULL
                         OR {$alias}.termination_date >= ?)";
    }
    return $where;
}

function contract_monthly_summary(array $contracts, string $month): array {
    $metrics = ['contract_count' => 0, 'revenue' => 0.0, 'monthly_value_total' => 0.0, 'average_monthly_value' => null, 'pending_count' => 0];
    $groups = [];

    foreach ($contracts as $contract) {
        $calculation = contract_monthly_revenue($contract, $month);
        $subsedeId = $contract['subsede_id'] ?? null;
        $poloId = $contract['polo_id'] ?? null;
        $key = json_encode([$subsedeId, $poloId], JSON_THROW_ON_ERROR);

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'subsede_id' => $subsedeId,
                'subsede_name' => (string)($contract['subsede_name'] ?? 'Subsede não identificada'),
                'polo_id' => $poloId,
                'polo_name' => isset($contract['polo_name']) ? (string)$contract['polo_name'] : null,
                'contract_count' => 0,
                'revenue' => 0.0,
                'monthly_value_total' => 0.0,
                'average_monthly_value' => null,
                'pending_count' => 0,
            ];
        }

        if ($calculation['pending_reason'] !== null) {
            $groups[$key]['pending_count']++;
            $metrics['pending_count']++;
            continue;
        }
        if (!$calculation['recognized']) {
            continue;
        }

        $groups[$key]['contract_count']++;
        $groups[$key]['revenue'] += $calculation['amount'];
        $groups[$key]['monthly_value_total'] += (float)$contract['monthly_value'];
        $metrics['contract_count']++;
        $metrics['revenue'] += $calculation['amount'];
        $metrics['monthly_value_total'] += (float)$contract['monthly_value'];
    }

    foreach ($groups as &$group) {
        $group['revenue'] = round($group['revenue'], 2);
        $group['monthly_value_total'] = round($group['monthly_value_total'], 2);
        $group['average_monthly_value'] = $group['contract_count'] > 0
            ? round($group['monthly_value_total'] / $group['contract_count'], 2)
            : null;
    }
    unset($group);

    $metrics['revenue'] = round($metrics['revenue'], 2);
    $metrics['monthly_value_total'] = round($metrics['monthly_value_total'], 2);
    $metrics['average_monthly_value'] = $metrics['contract_count'] > 0
        ? round($metrics['monthly_value_total'] / $metrics['contract_count'], 2)
        : null;
    return ['metrics' => $metrics, 'groups' => array_values($groups)];
}

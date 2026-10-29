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

function financial_projection(mixed $revenue, mixed $costs, mixed $targetMarginPercent, mixed $contractCount): array {
    foreach (['revenue' => $revenue, 'costs' => $costs, 'target margin' => $targetMarginPercent] as $label => $value) {
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
    $averageRevenuePerContract = $contractCount > 0 ? $revenue / $contractCount : null;
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

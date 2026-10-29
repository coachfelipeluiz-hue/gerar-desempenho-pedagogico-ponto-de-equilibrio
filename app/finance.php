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

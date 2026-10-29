<?php
declare(strict_types=1);

require __DIR__ . '/../app/finance.php';

$rows = [
    ['hours' => 2.0, 'cost_per_hour' => 30.0, 'approved' => 1],
    ['hours' => 1.5, 'cost_per_hour' => 40.0, 'approved' => 0],
    ['hours' => 3.0, 'cost_per_hour' => 50.0, 'approved' => 1],
];
$totals = instruction_cost_totals($rows);
$expected = [
    'hours' => 6.5,
    'approved_cost' => 210.0,
    'pending_cost' => 60.0,
];

if ($totals !== $expected) {
    fwrite(STDERR, 'Totais inesperados: ' . var_export($totals, true) . "\n");
    exit(1);
}

fwrite(STDOUT, "OK: custo aprovado e pendente separados corretamente.\n");

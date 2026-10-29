<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Finance/BreakEvenCalculator.php';

use Gerar\Finance\BreakEvenCalculator;

$checks = 0;
$failures = [];

$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$checks, &$failures): void {
    $checks++;
    if ($expected !== $actual) {
        $failures[] = $label . ': esperado ' . var_export($expected, true)
            . ', obtido ' . var_export($actual, true);
    }
};

$assertThrows = static function (string $label, callable $callback) use (&$checks, &$failures): void {
    $checks++;
    try {
        $callback();
        $failures[] = $label . ': era esperada InvalidArgumentException.';
    } catch (InvalidArgumentException) {
        // Resultado esperado.
    }
};

$revenue = BreakEvenCalculator::contractRevenue([
    ['monthly_value' => 300, 'active_days' => 10, 'period_days' => 30],
    ['monthly_value' => 200, 'active_days' => 30, 'period_days' => 30],
]);
$assertSame('receita integral', 500.0, $revenue['full_revenue']);
$assertSame('receita proporcional', 300.0, $revenue['prorated_revenue']);
$assertSame('quantidade de contratos', 2, $revenue['contracts_count']);

$margin = BreakEvenCalculator::contributionMargin(1000.0, 250.0);
$assertSame('margem de contribuição', 750.0, $margin['contribution_margin']);
$assertSame('taxa de margem', 0.75, $margin['contribution_margin_rate']);
$assertSame('taxa indefinida sem receita', null,
    BreakEvenCalculator::contributionMargin(0.0, 0.0)['contribution_margin_rate']);
$assertSame('ponto de equilíbrio de receita', 2000.0,
    BreakEvenCalculator::breakEvenRevenue(1500.0, 0.75));
$assertSame('não calcular com margem nula', null,
    BreakEvenCalculator::breakEvenRevenue(1500.0, 0.0));
$assertSame('não calcular com taxa acima de 100%', null,
    BreakEvenCalculator::breakEvenRevenue(1500.0, 1.1));

$assertSame('turma abaixo da meta', 12,
    BreakEvenCalculator::operationalTarget(3)['additional_needed']);
$assertSame('15 jovens é faixa regular', 'within_range',
    BreakEvenCalculator::operationalTarget(15)['status']);
$assertSame('25 jovens é faixa regular', 'within_range',
    BreakEvenCalculator::operationalTarget(25)['status']);
$assertSame('26 jovens acima da faixa', 'above_range',
    BreakEvenCalculator::operationalTarget(26)['status']);

$assertThrows('rejeitar dias acima do período', static fn() =>
    BreakEvenCalculator::contractRevenue([
        ['monthly_value' => 100, 'active_days' => 31, 'period_days' => 30],
    ]));
$assertThrows('rejeitar valor negativo', static fn() =>
    BreakEvenCalculator::contractRevenue([
        ['monthly_value' => -1, 'active_days' => 1, 'period_days' => 30],
    ]));
$assertThrows('rejeitar custo variável negativo', static fn() =>
    BreakEvenCalculator::contributionMargin(100.0, -1.0));

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: {$checks} verificações de cálculo financeiro." . PHP_EOL;

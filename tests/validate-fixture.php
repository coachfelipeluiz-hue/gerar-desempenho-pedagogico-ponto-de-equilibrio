<?php
declare(strict_types=1);

/**
 * Valida a aritmética dos dados sintéticos da Etapa 0.
 * Uso: php tests/validate-fixture.php
 * Não acessa banco de dados nem executa a aplicação.
 */

$path = __DIR__ . '/fixtures/ponto-equilibrio-sintetico-v1.json';
$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "ERRO: não foi possível ler {$path}\n");
    exit(1);
}

$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$errors = [];
$checks = 0;

$assertSame = static function (string $label, mixed $expected, mixed $actual) use (&$errors, &$checks): void {
    $checks++;
    if ($expected !== $actual) {
        $errors[] = "{$label}: esperado " . var_export($expected, true) . ", obtido " . var_export($actual, true);
    }
};

$contracts = $data['contracts'] ?? [];
$monthlyTotal = array_sum(array_map(static fn(array $c): float => (float) $c['monthly_value'], $contracts));
$attributedTotal = array_sum(array_map(
    static fn(array $c): float => !empty($c['polo_id']) && !empty($c['class_id']) ? (float) $c['monthly_value'] : 0.0,
    $contracts
));

$assertSame('quantidade de contratos', 5, count($contracts));
$assertSame('soma dos valores mensais integrais', 1500.0, $monthlyTotal);
$assertSame('soma dos contratos atribuídos a turma e polo', 1220.0, $attributedTotal);

foreach (['2026-03' => 1200.0, '2026-04' => 300.0] as $competence => $expectedRevenue) {
    $actualRevenue = array_sum(array_map(
        static fn(array $c): float => $c['competence'] === $competence ? (float) $c['monthly_value'] : 0.0,
        $contracts
    ));
    $assertSame("receita integral por competência {$competence}", $expectedRevenue, $actualRevenue);
}

$checksData = $data['expected_results']['dataset_checks'] ?? [];
$assertSame('meta operacional Frentista com 3 jovens', 12, (int) $checksData['frentista_turma_3_youths']['additional_youths_needed']);
$partial = $checksData['partial_contract_example'];
$assertSame('receita proporcional do contrato parcial', 100.0, round((float) $partial['monthly_value'] * (int) $partial['active_days'] / (int) $partial['period_days'], 2));
$instructor = $checksData['instructor_duplicate_example'];
$assertSame('custo por horas', 480.0, (float) $instructor['hours'] * (float) $instructor['hourly_rate']);
$assertSame('duplicidade sinalizada', true, (bool) $instructor['must_flag_possible_duplicate']);
$capacity = $checksData['capacity_example'];
$assertSame('ocupação acima da capacidade', 112, (int) round((int) $capacity['youths'] / (int) $capacity['capacity'] * 100));
$assertSame('excesso de capacidade', 12, (int) $capacity['youths'] - (int) $capacity['capacity']);

$independent = $data['expected_results']['independent_scenarios'] ?? [];
$avg = $independent['average_example'];
$assertSame('média por contrato do cenário independente', 300.0, round((float) $avg['revenue_total'] / (int) $avg['contract_count'], 2));
$assertSame('média de jovens por turma do cenário independente', 10.0, round(array_sum($avg['class_youth_counts']) / count($avg['class_youth_counts']), 2));
$margin = $independent['contribution_margin_example'];
$assertSame('margem de contribuição', 4000.0, (float) $margin['revenue'] - (float) $margin['variable_costs']);
$assertSame('percentual da margem de contribuição', 0.4, round(((float) $margin['revenue'] - (float) $margin['variable_costs']) / (float) $margin['revenue'], 4));
$consolidated = $independent['consolidated_example'];
$assertSame('resultado consolidado independente', 1500.0, (float) $consolidated['revenue'] - (float) $consolidated['costs']);

if ($errors !== []) {
    fwrite(STDERR, "FALHA: " . count($errors) . " de {$checks} verificações.\n");
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

echo "OK: {$checks} verificações aritméticas passaram.\n";
echo "Escopo: fixture sintética; não testa aplicação, permissões, banco de dados ou interface.\n";

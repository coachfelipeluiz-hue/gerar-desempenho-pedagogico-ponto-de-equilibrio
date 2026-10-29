<?php
declare(strict_types=1);

require __DIR__ . '/../app/import_contracts.php';

function assert_import(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'gerar-contract-import-');
if ($path === false) {
    throw new RuntimeException('Não foi possível criar CSV temporário de teste.');
}

$header = [
    '2 Contrato',
    '8 Subsede',
    '9 Subsede',
    '10 Polo (Local Curso)',
    '12 Empresa',
    '15 Dt Inicio Ctr',
    '16 Dt Final Ctr',
    '17 Desligado',
    '18 Desligado',
    '19 $ Mensal',
];
$rows = [
    ['CTR-001', 'S-01', 'Subsede A', 'Polo A', 'Empresa A', '2026-10-01', '2027-03-31', 'Não', '', '1.500,00'],
    ['CTR-002', 'S-02', 'Subsede B', 'Polo B', 'Empresa B', '2026-10-02', '', 'Não', '', '0'],
];

$handle = fopen($path, 'wb');
if (!$handle) {
    @unlink($path);
    throw new RuntimeException('Não foi possível abrir CSV temporário de teste.');
}
fputcsv($handle, $header);
foreach ($rows as $row) {
    fputcsv($handle, $row);
}
fclose($handle);

try {
    $parsed = iterator_to_array(contract_csv_import_rows($path), false);
    assert_import(count($parsed) === 2, 'A importação deve percorrer as duas linhas de dados.');
    assert_import(($parsed[0][2][0] ?? null) === 'CTR-001', 'A primeira linha de dados não pode ser descartada.');
    assert_import(($parsed[1][2][0] ?? null) === 'CTR-002', 'A segunda linha de dados deve permanecer disponível.');
    assert_import(money_to_float('1.500,00') === 1500.0, 'Valor monetário brasileiro deve ser interpretado corretamente.');
    assert_import(money_to_float('0') === 0.0, 'Zero explícito deve continuar válido.');
    assert_import(!is_finite(money_to_float('')), 'Valor mensal vazio deve ser inválido, não zero.');
    $seenCodes = [];
    assert_unique_contract_code('CTR-001', $seenCodes);
    $duplicateRejected = false;
    try {
        assert_unique_contract_code('ctr-001', $seenCodes);
    } catch (RuntimeException) {
        $duplicateRejected = true;
    }
    assert_import($duplicateRejected, 'Código de contrato repetido no mesmo lote deve ser rejeitado.');
} finally {
    @unlink($path);
}

fwrite(STDOUT, "OK: duas linhas e valores mensais foram validados.\n");

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
    $discharged = contract_discharge_data('Sim', '10/03/2026');
    assert_import($discharged['status'] === 'Desligado' && $discharged['termination_date'] === '2026-03-10' && !$discharged['pending'], 'Flag Sim com data válida deve guardar a data de desligamento.');
    $missingTermination = contract_discharge_data('Sim', '');
    assert_import($missingTermination['status'] === 'Desligado' && $missingTermination['termination_date'] === null && $missingTermination['pending'], 'Flag Sim sem data deve permanecer pendente.');
    $invalidTermination = contract_discharge_data('Sim', '31/02/2026');
    assert_import($invalidTermination['pending_reason'] === 'missing_or_invalid_termination_date', 'Data impossível de desligamento deve ficar pendente.');
    $active = contract_discharge_data('Não', 'Não');
    assert_import($active['status'] === 'Ativo' && !$active['pending'], 'Marcador Não reconhecido no segundo campo deve continuar ativo.');
    $observedActiveMarker = contract_discharge_data('Não', 'Ativo');
    assert_import($observedActiveMarker['status'] === 'Ativo' && !$observedActiveMarker['pending'], 'Marcador Ativo presente na base deve permanecer válido.');
    $conflict = contract_discharge_data('Não', '10/03/2026');
    assert_import($conflict['status'] === 'Pendente' && $conflict['pending'], 'Data de desligamento com flag Não deve ficar pendente, sem gerar receita silenciosamente.');
    $invalidDateConflict = contract_discharge_data('Não', '31/02/2026');
    assert_import($invalidDateConflict['status'] === 'Pendente' && $invalidDateConflict['pending_reason'] === 'invalid_termination_date', 'Data com formato de calendário impossível não deve virar contrato ativo.');
    $unrecognizedText = contract_discharge_data('Não', 'foo');
    assert_import($unrecognizedText['status'] === 'Pendente' && $unrecognizedText['pending'], 'Texto inesperado no campo de data não deve virar contrato ativo.');
    $unsupportedDate = contract_discharge_data('Não', '31.02.2026');
    assert_import($unsupportedDate['status'] === 'Pendente' && $unsupportedDate['pending'], 'Data em formato desconhecido não deve virar contrato ativo.');
    $unknown = contract_discharge_data('Talvez', '');
    assert_import($unknown['status'] === 'Pendente' && $unknown['pending'], 'Flag desconhecida não deve ser assumida como ativo.');
    $emptyFlag = contract_discharge_data('', '');
    assert_import($emptyFlag['status'] === 'Pendente' && $emptyFlag['pending'], 'Flag vazia não deve ser assumida como ativo.');

    $optionalPeriod = contract_period_data('', '');
    assert_import(!$optionalPeriod['pending'] && $optionalPeriod['start_date'] === null && $optionalPeriod['end_date'] === null, 'Início/fim opcionais vazios devem continuar representando limites abertos.');
    $invalidPeriod = contract_period_data('2026-10-03', '2026-10-02');
    assert_import($invalidPeriod['pending'] && $invalidPeriod['pending_reason'] === 'invalid_contract_period', 'Período invertido deve ser pendência.');
    $invalidStart = contract_period_data('31/02/2026', '2026-12-31');
    assert_import($invalidStart['pending'] && $invalidStart['pending_reason'] === 'invalid_start_date', 'Data inicial impossível deve ser pendência.');
} finally {
    @unlink($path);
}

fwrite(STDOUT, "OK: duas linhas e valores mensais foram validados.\n");

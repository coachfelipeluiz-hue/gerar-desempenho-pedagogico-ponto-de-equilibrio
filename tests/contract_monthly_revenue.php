<?php
declare(strict_types=1);

require __DIR__ . '/../app/finance.php';

function assert_monthly_revenue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fullMonth = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Ativo', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => null,
], '2026-03');
assert_monthly_revenue($fullMonth['amount'] === 3100.0 && $fullMonth['active_days'] === 31, 'Contrato ativo no mês inteiro deve reconhecer o valor integral.');

$terminated = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Desligado', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => '2026-03-10',
], '2026-03');
assert_monthly_revenue($terminated['amount'] === 1000.0 && $terminated['active_days'] === 10, 'Desligamento em 10 de março deve contar inclusive o último dia ativo.');

$midMonthStart = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Desligado', 'start_date' => '2026-03-05', 'end_date' => '2026-12-31', 'termination_date' => '2026-03-10',
], '2026-03');
assert_monthly_revenue($midMonthStart['amount'] === 600.0 && $midMonthStart['active_days'] === 6, 'Contrato iniciado no meio do mês deve usar somente dias entre início e desligamento.');

$endedContract = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Ativo', 'start_date' => '2026-01-01', 'end_date' => '2026-03-15', 'termination_date' => null,
], '2026-03');
assert_monthly_revenue($endedContract['amount'] === 1500.0 && $endedContract['active_days'] === 15, 'Fim contratual no meio do mês deve limitar os dias reconhecidos.');

$leapYear = contract_monthly_revenue([
    'monthly_value' => '2900.00', 'status' => 'Desligado', 'start_date' => '2024-02-01', 'end_date' => '2024-12-31', 'termination_date' => '2024-02-29',
], '2024-02');
assert_monthly_revenue($leapYear['amount'] === 2900.0 && $leapYear['active_days'] === 29, 'Fevereiro bissexto deve usar 29 dias.');

$missingDate = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Desligado', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => null,
], '2026-03');
assert_monthly_revenue(!$missingDate['recognized'] && $missingDate['amount'] === 0.0 && $missingDate['pending_reason'] === 'missing_termination_date', 'Desligado sem data deve ficar fora da receita e sinalizado.');

$invalidDate = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Desligado', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => '2026-02-30',
], '2026-03');
assert_monthly_revenue(!$invalidDate['recognized'] && $invalidDate['pending_reason'] === 'invalid_termination_date', 'Data de desligamento impossível deve ficar pendente.');

$notYetActive = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Ativo', 'start_date' => '2026-04-01', 'end_date' => '2026-12-31', 'termination_date' => null,
], '2026-03');
assert_monthly_revenue(!$notYetActive['recognized'] && $notYetActive['amount'] === 0.0, 'Contrato iniciado após a competência não deve gerar receita no mês.');

$conflict = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Ativo', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'termination_date' => '2026-03-10',
], '2026-03');
assert_monthly_revenue(!$conflict['recognized'] && $conflict['pending_reason'] === 'status_date_conflict', 'Data de desligamento incompatível com o status ativo deve ficar pendente.');

$unknownStatus = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Pendente', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'termination_date' => null,
], '2026-03');
assert_monthly_revenue(!$unknownStatus['recognized'] && $unknownStatus['pending_reason'] === 'invalid_status', 'Status não reconhecido não deve ser contado como receita.');

$openEnded = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Ativo', 'start_date' => null, 'end_date' => null, 'termination_date' => null,
], '2026-03');
assert_monthly_revenue($openEnded['recognized'] && $openEnded['amount'] === 3100.0 && $openEnded['active_days'] === 31, 'Início/fim opcionais devem usar os limites da competência para contrato aberto.');

$emptyStatus = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => '', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'termination_date' => null,
], '2026-03');
assert_monthly_revenue(!$emptyStatus['recognized'] && $emptyStatus['pending_reason'] === 'invalid_status', 'Status vazio não deve ser assumido ativo.');

$invertedPeriod = contract_monthly_revenue([
    'monthly_value' => '3100.00', 'status' => 'Ativo', 'start_date' => '2026-03-20', 'end_date' => '2026-03-10', 'termination_date' => null,
], '2026-03');
assert_monthly_revenue(!$invertedPeriod['recognized'] && $invertedPeriod['pending_reason'] === 'invalid_contract_period', 'Intervalo de contrato invertido deve aparecer como pendência.');

$summary = contract_monthly_summary([
    ['subsede_id' => 1, 'subsede_name' => 'Subsede A', 'polo_id' => 10, 'polo_name' => 'Polo A', 'monthly_value' => '3100.00', 'status' => 'Ativo', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => null],
    ['subsede_id' => 1, 'subsede_name' => 'Subsede A', 'polo_id' => 10, 'polo_name' => 'Polo A', 'monthly_value' => '3100.00', 'status' => 'Desligado', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => '2026-03-10'],
    ['subsede_id' => 1, 'subsede_name' => 'Subsede A', 'polo_id' => 10, 'polo_name' => 'Polo A', 'monthly_value' => '3100.00', 'status' => 'Desligado', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => null],
    ['subsede_id' => 1, 'subsede_name' => 'Subsede A', 'polo_id' => 11, 'polo_name' => 'Polo B', 'monthly_value' => '6200.00', 'status' => 'Ativo', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31', 'termination_date' => null],
], '2026-03');
assert_monthly_revenue($summary['metrics']['contract_count'] === 3, 'A consolidação deve contar apenas contratos com dados de receita reconhecíveis.');
assert_monthly_revenue($summary['metrics']['revenue'] === 10300.0, 'A receita consolidada deve usar o valor mensal proporcional de cada contrato.');
assert_monthly_revenue($summary['metrics']['pending_count'] === 1, 'Desligamento sem data deve ser consolidado como pendência.');
assert_monthly_revenue($summary['metrics']['average_monthly_value'] === 4133.33, 'Ticket geral deve usar os valores mensais nominais dos contratos reconhecidos.');
assert_monthly_revenue(count($summary['groups']) === 2, 'Polos distintos devem permanecer em linhas/grupos separados.');
assert_monthly_revenue($summary['groups'][0]['contract_count'] === 2 && $summary['groups'][0]['pending_count'] === 1, 'O grupo do polo deve separar contratos reconhecidos e pendências.');
assert_monthly_revenue($summary['groups'][0]['revenue'] === 4100.0 && $summary['groups'][0]['average_monthly_value'] === 3100.0, 'Receita proporcional e ticket nominal devem permanecer distintos no polo.');

fwrite(STDOUT, "OK: receita proporcional e pendências de desligamento verificadas.\n");

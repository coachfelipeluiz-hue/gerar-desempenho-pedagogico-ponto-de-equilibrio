<?php
declare(strict_types=1);

require __DIR__ . '/../app/finance.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE contracts (source_code TEXT, start_date TEXT, end_date TEXT, termination_date TEXT, status TEXT, monthly_value NUMERIC)');
$insert = $pdo->prepare('INSERT INTO contracts VALUES (?,?,?,?,?,?)');
foreach ([
    ['active', '2026-01-01', '2026-12-31', null, 'Ativo', 3100],
    ['dismissed-in-month', '2026-01-01', '2026-12-31', '2026-03-10', 'Desligado', 3100],
    ['dismissed-before-month', '2026-01-01', '2026-12-31', '2026-02-28', 'Desligado', 3100],
    ['dismissed-no-date', '2026-01-01', '2026-12-31', null, 'Desligado', 3100],
    ['starts-later', '2026-04-01', '2026-12-31', null, 'Ativo', 3100],
    ['ended-before-month', '2026-01-01', '2026-02-28', null, 'Ativo', 3100],
    ['unknown-status', '2026-01-01', '2026-12-31', null, 'Pendente', 3100],
] as $row) {
    $insert->execute($row);
}

$where = contract_monthly_candidate_where('c', true);
$stmt = $pdo->prepare('SELECT c.* FROM contracts c WHERE ' . $where . ' ORDER BY c.source_code');
$stmt->execute(['2026-03-31', '2026-03-01', '2026-03-01']);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$codes = array_column($rows, 'source_code');
if ($codes !== ['active', 'dismissed-in-month', 'dismissed-no-date', 'unknown-status']) {
    throw new RuntimeException('Filtro mensal selecionou registros inesperados.');
}
$summary = contract_monthly_summary($rows, '2026-03');
if ($summary['metrics']['contract_count'] !== 2 || $summary['metrics']['pending_count'] !== 2 || $summary['metrics']['revenue'] !== 4100.0) {
    throw new RuntimeException('Desligados sem data e status desconhecido devem ficar pendentes.');
}

$legacyWhere = contract_monthly_candidate_where('c', false);
$pdo->exec('CREATE TABLE legacy_contracts (source_code TEXT, start_date TEXT, end_date TEXT, status TEXT, monthly_value NUMERIC)');
$legacyInsert = $pdo->prepare('INSERT INTO legacy_contracts VALUES (?,?,?,?,?)');
$legacyInsert->execute(['legacy-active', '2026-01-01', '2026-12-31', 'Ativo', 3100]);
$legacyInsert->execute(['legacy-dismissed', '2026-01-01', '2026-12-31', 'Desligado', 3100]);
$legacyStmt = $pdo->prepare('SELECT c.*, NULL AS termination_date FROM legacy_contracts c WHERE ' . $legacyWhere . ' ORDER BY c.source_code');
$legacyStmt->execute(['2026-03-31', '2026-03-01']);
$legacySummary = contract_monthly_summary($legacyStmt->fetchAll(PDO::FETCH_ASSOC), '2026-03');
if ($legacySummary['metrics']['contract_count'] !== 1 || $legacySummary['metrics']['pending_count'] !== 1) {
    throw new RuntimeException('Bancos legados sem data de desligamento devem manter desligados como pendência.');
}

$aliasRejected = false;
try {
    contract_monthly_candidate_where('c; DROP TABLE contracts', true);
} catch (InvalidArgumentException) {
    $aliasRejected = true;
}
if (!$aliasRejected) {
    throw new RuntimeException('Alias SQL não confiável deve ser rejeitado.');
}

fwrite(STDOUT, "OK: seleção temporal mensal e exclusão segura de pendências verificadas.\n");

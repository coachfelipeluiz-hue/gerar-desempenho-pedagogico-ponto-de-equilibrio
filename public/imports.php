<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/import_contracts.php';
require __DIR__ . '/../app/layout.php';
require_login();
if (!is_master_user()) {
    http_response_code(403); page_header('Acesso negado'); echo '<div class="error">Importações de contratos podem alterar várias subsedes e, até existir filtragem por unidade, ficam restritas ao Master.</div>'; page_footer(); exit;
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_import'])) $_SESSION['csrf_import'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_import'];
$messages = [];
$errors = [];
$preview = null;
$uploadDir = dirname(__DIR__) . '/storage/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0770, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
    } elseif (($_POST['action'] ?? '') === 'upload') {
        $f = $_FILES['csv_file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Selecione um arquivo CSV válido para enviar.';
        } elseif (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $errors[] = 'Nesta etapa, envie apenas o arquivo Gerar.csv. O arquivo de turmas será tratado separadamente.';
        } elseif ((int)$f['size'] > 25 * 1024 * 1024) {
            $errors[] = 'O arquivo ultrapassa o limite de 25 MB.';
        } else {
            try {
                $tempName = bin2hex(random_bytes(16)) . '.csv';
                $target = $uploadDir . '/' . $tempName;
                if (!move_uploaded_file($f['tmp_name'], $target)) throw new RuntimeException('Não foi possível guardar o arquivo temporário. Verifique a permissão de escrita em storage/uploads.');
                $rows = 0; $valid = 0; $invalid = 0; $sample = []; $map = null; $headerSeen = false;
                foreach (import_csv_rows($target) as [$line, $header, $row]) {
                    if (!$headerSeen) { $map = contract_csv_map($header); $headerSeen = true; }
                    $rows++;
                    $contract = trim((string)($row[$map['2 Contrato']] ?? ''));
                    $subName = trim((string)($row[$map['9 Subsede']] ?? ''));
                    $amount = money_to_float($row[$map['19 $ Mensal']] ?? '');
                    if ($contract !== '' && $subName !== '' && is_finite($amount) && $amount >= 0) $valid++; else $invalid++;
                    if (count($sample) < 8) $sample[] = ['contrato'=>$contract, 'subsede'=>$subName, 'polo'=>trim((string)($row[$map['10 Polo (Local Curso)']] ?? '')), 'empresa'=>trim((string)($row[$map['12 Empresa']] ?? '')), 'mensal'=>is_finite($amount) ? $amount : null, 'desligado'=>trim((string)($row[$map['17 Desligado']] ?? ''))];
                }
                if (!$headerSeen) throw new RuntimeException('Não há registros no CSV.');
                $_SESSION['pending_contract_import'] = ['path'=>$target, 'name'=>basename((string)$f['name']), 'rows'=>$rows, 'valid'=>$valid, 'invalid'=>$invalid];
                $preview = ['rows'=>$rows, 'valid'=>$valid, 'invalid'=>$invalid, 'sample'=>$sample];
            } catch (Throwable $e) { $errors[] = $e->getMessage(); }
        }
    } elseif (($_POST['action'] ?? '') === 'confirm') {
        $pending = $_SESSION['pending_contract_import'] ?? null;
        if (!$pending || !is_file((string)($pending['path'] ?? ''))) {
            $errors[] = 'Não há arquivo pendente. Envie o CSV novamente.';
        } elseif (($pending['invalid'] ?? 0) > 0 && empty($_POST['skip_invalid'])) {
            $errors[] = 'Existem linhas inválidas. Marque a opção para ignorá-las ou envie uma base corrigida.';
        } elseif (!$pdo instanceof PDO) {
            $errors[] = 'Banco de dados indisponível. Configure config/database.php e importe database/schema.sql antes de confirmar.';
        } else {
            try {
                $pdo->beginTransaction();
                $batch = $pdo->prepare("INSERT INTO import_batches (source_type,file_name,status,total_rows,valid_rows,rejected_rows,uploaded_by) VALUES ('contracts_csv',?,'validated',?,?,?,?)");
                $batch->execute([$pending['name'], $pending['rows'], $pending['valid'], $pending['invalid'], (int)(current_user()['id'] ?? 0) ?: null]);
                $batchId = (int)$pdo->lastInsertId();
                $subSelect = $pdo->prepare('SELECT id FROM subsedes WHERE source_code = ? LIMIT 1');
                $subInsert = $pdo->prepare('INSERT INTO subsedes (name,source_code,active) VALUES (?,?,1)');
                $subUpdate = $pdo->prepare('UPDATE subsedes SET name = ?, active = 1 WHERE id = ?');
                $poloSelect = $pdo->prepare('SELECT id FROM polos WHERE source_code = ? LIMIT 1');
                $poloInsert = $pdo->prepare('INSERT INTO polos (name,source_code,active) VALUES (?,?,1)');
                $poloUpdate = $pdo->prepare('UPDATE polos SET name = ?, active = 1 WHERE id = ?');
                $historyCurrent = $pdo->prepare('SELECT id, subsede_id, valid_from FROM polo_subsede_history WHERE polo_id = ? AND valid_until IS NULL ORDER BY valid_from DESC, id DESC LIMIT 1');
                $historyClose = $pdo->prepare('UPDATE polo_subsede_history SET valid_until = DATE_SUB(?, INTERVAL 1 DAY) WHERE id = ?');
                $historyInsert = $pdo->prepare('INSERT INTO polo_subsede_history (polo_id, subsede_id, valid_from, created_by) VALUES (?, ?, ?, ?)');
                $contractSelect = $pdo->prepare('SELECT id FROM contracts WHERE source_code = ?');
                $contractUpdate = $pdo->prepare('UPDATE contracts SET subsede_id=?,polo_id=?,customer_name=?,monthly_value=?,start_date=?,end_date=?,status=?,imported_batch_id=? WHERE id=?');
                $contractInsert = $pdo->prepare('INSERT INTO contracts (source_code,subsede_id,polo_id,customer_name,monthly_value,start_date,end_date,status,imported_batch_id) VALUES (?,?,?,?,?,?,?,?,?)');
                $count = 0; $skipped = 0; $seenContractCodes = [];
                foreach (contract_csv_import_rows($pending['path']) as [$line, $map, $row]) {
                    $code = trim((string)($row[$map['2 Contrato']] ?? ''));
                    $subCode = trim((string)($row[$map['8 Subsede']] ?? ''));
                    $subName = trim((string)($row[$map['9 Subsede']] ?? ''));
                    $poloName = trim((string)($row[$map['10 Polo (Local Curso)']] ?? ''));
                    $company = trim((string)($row[$map['12 Empresa']] ?? ''));
                    $monthly = money_to_float($row[$map['19 $ Mensal']] ?? '');
                    if ($code === '' || $subName === '' || !is_finite($monthly) || $monthly < 0) { $skipped++; continue; }
                    assert_unique_contract_code($code, $seenContractCodes);
                    $subSelect->execute([$subCode]); $subId = $subSelect->fetchColumn();
                    if ($subId) { $subUpdate->execute([$subName,(int)$subId]); }
                    else { $subInsert->execute([$subName,$subCode !== '' ? $subCode : null]); $subId = $pdo->lastInsertId(); }
                    $poloId = null;
                    if ($poloName !== '') {
                        $poloSelect->execute([$poloName]); $poloId = $poloSelect->fetchColumn();
                        if ($poloId) $poloUpdate->execute([$poloName,(int)$poloId]);
                        else { $poloInsert->execute([$poloName,$poloName]); $poloId = $pdo->lastInsertId(); }
                    }
                    $start = date_or_null($row[$map['15 Dt Inicio Ctr']] ?? '');
                    $end = date_or_null($row[$map['16 Dt Final Ctr']] ?? '');
                    // Mantém a responsabilidade do polo vinculada à subsede no histórico.
                    if ($poloId) {
                        $effectiveDate = $start ?? date('Y-m-d');
                        $historyCurrent->execute([(int)$poloId]);
                        $currentAssignment = $historyCurrent->fetch();
                        if (!$currentAssignment) {
                            $historyInsert->execute([(int)$poloId, (int)$subId, $effectiveDate, (int)(current_user()['id'] ?? 0) ?: null]);
                        } elseif ((int)$currentAssignment['subsede_id'] !== (int)$subId && $effectiveDate > $currentAssignment['valid_from']) {
                            $historyClose->execute([$effectiveDate, (int)$currentAssignment['id']]);
                            $historyInsert->execute([(int)$poloId, (int)$subId, $effectiveDate, (int)(current_user()['id'] ?? 0) ?: null]);
                        }
                    }
                    $disabled = mb_strtolower(trim((string)($row[$map['17 Desligado']] ?? '')), 'UTF-8');
                    $status = ($disabled === 'sim' || $disabled === 'yes') ? 'Desligado' : 'Ativo';
                    $contractSelect->execute([$code]);
                    $existingContracts = $contractSelect->fetchAll();
                    if (count($existingContracts) > 1) throw new RuntimeException('O número de contrato já identifica mais de um registro no banco. Corrija a duplicidade antes de importar.');
                    $existingId = $existingContracts[0]['id'] ?? null;
                    if ($existingId) $contractUpdate->execute([(int)$subId, $poloId ? (int)$poloId : null, $company, $monthly, $start, $end, $status, $batchId, (int)$existingId]);
                    else $contractInsert->execute([$code,(int)$subId,$poloId ? (int)$poloId : null,$company,$monthly,$start,$end,$status,$batchId]);
                    $count++;
                }
                $pdo->prepare("UPDATE import_batches SET status='imported', valid_rows=?, rejected_rows=? WHERE id=?")->execute([$count,$skipped,$batchId]);
                if (function_exists('audit')) audit($pdo, (int)(current_user()['id'] ?? 0) ?: null, 'import_contracts_csv', 'import_batch', $batchId, ['imported'=>$count,'skipped'=>$skipped,'file'=>$pending['name']]);
                $pdo->commit();
                @unlink($pending['path']); unset($_SESSION['pending_contract_import']);
                $messages[] = "Importação concluída: {$count} contratos processados; {$skipped} linhas ignoradas. Reimportar a mesma base atualiza contratos existentes pelo número do contrato.";
            } catch (Throwable $e) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'A importação foi cancelada e revertida: ' . $e->getMessage();
            }
        }
    }
}
if (!$preview && !empty($_SESSION['pending_contract_import'])) {
    $p = $_SESSION['pending_contract_import'];
    $preview = ['rows'=>$p['rows'],'valid'=>$p['valid'],'invalid'=>$p['invalid'],'sample'=>[]];
}
page_header('Importação de dados');
?>
<?php foreach ($messages as $m): ?><div class="notice"><strong>Sucesso:</strong> <?= e($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $m): ?><div class="error"><?= e($m) ?></div><?php endforeach; ?>
<div class="notice"><strong>Importação inicial.</strong> Esta etapa importa a base de contratos por número de contrato, relaciona subsede e polo e usa a coluna <code>$ Mensal</code>. Não importa CPF nem nome de aprendiz. Faça uma cópia de segurança do banco antes de importar uma base oficial.</div>
<div class="panel"><h2>1. Contratos — Gerar.csv</h2><p class="muted">Envie o CSV original exportado do sistema. A tela valida o cabeçalho e mostra uma prévia antes de gravar no banco. Limite: 25 MB.</p>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="upload"><label for="csv_file">Arquivo CSV</label><input id="csv_file" type="file" name="csv_file" accept=".csv,text/csv" required><p><button type="submit">Enviar e validar CSV</button></p></form></div>
<?php if ($preview): ?><div class="panel"><h2>2. Prévia da importação</h2><div class="stats"><div class="stat"><span>Linhas lidas</span><strong><?= (int)$preview['rows'] ?></strong></div><div class="stat"><span>Válidas</span><strong><?= (int)$preview['valid'] ?></strong></div><div class="stat"><span>Inválidas</span><strong><?= (int)$preview['invalid'] ?></strong></div></div>
<?php if (!empty($preview['sample'])): ?><div class="table-wrap"><table><thead><tr><th>Contrato</th><th>Subsede</th><th>Polo</th><th>Empresa</th><th>Valor mensal</th><th>Desligado?</th></tr></thead><tbody><?php foreach ($preview['sample'] as $r): ?><tr><td><?= e($r['contrato']) ?></td><td><?= e($r['subsede']) ?></td><td><?= e($r['polo']) ?></td><td><?= e($r['empresa']) ?></td><td><?= $r['mensal'] === null ? 'Inválido' : 'R$ ' . number_format((float)$r['mensal'],2,',','.') ?></td><td><?= e($r['desligado']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="confirm"><?php if ((int)$preview['invalid'] > 0): ?><label><input type="checkbox" name="skip_invalid" value="1" required> Ignorar as linhas inválidas e importar somente as válidas.</label><?php endif; ?><p><button type="submit">Confirmar e importar para o banco</button></p></form></div><?php endif; ?>
<div class="panel"><h2>Próximas fontes</h2>
<div class="import-row"><div><strong>Turmas (CSV)</strong><p class="muted">A base recebida tem um campo em que informações de turma/curso/vagas parecem concatenadas. Vamos resolver o mapeamento antes de importar para não gravar dados incorretos.</p></div><span class="pill">Em análise</span></div>
<div class="import-row"><div><strong>Base de instrutores (XLSX)</strong><p class="muted">Cadastro por nome, cargo, categoria e departamento. Custos-hora e alocação por turma serão informados separadamente.</p></div><span class="pill">Próxima etapa</span></div></div>
<?php page_footer(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();
if (!can_access_module('imports')) {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">Você não tem permissão para importar turmas.</div>';
    page_footer();
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    page_header('Importação de turmas');
    echo '<div class="error">Não foi possível conectar ao banco de dados.</div>';
    page_footer();
    exit;
}
if (empty($_SESSION['csrf_classes_import'])) $_SESSION['csrf_classes_import'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_classes_import'];
$messages = [];
$errors = [];
$uploadDir = dirname(__DIR__) . '/storage/uploads';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0770, true);

function class_csv_read(string $path): array {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('Não foi possível ler o CSV enviado.');
    $first = fgets($fh);
    if ($first === false) { fclose($fh); throw new RuntimeException('O arquivo está vazio.'); }

    // Escolhe o delimitador pela consistência do número de colunas nas primeiras linhas,
    // e não pela contagem bruta de separadores (que falha com separadores dentro de aspas).
    $candidates = [',' , ';', "\t"];
    $scores = [];
    foreach ($candidates as $candidate) {
        $probe = fopen($path, 'rb');
        if (!$probe) continue;
        $counts = [];
        $sampled = 0;
        while (($probeRow = fgetcsv($probe, 0, $candidate, '"', '\\')) !== false && $sampled < 12) {
            if (count($probeRow) === 1 && trim((string)$probeRow[0]) === '') continue;
            $counts[] = count($probeRow);
            $sampled++;
        }
        fclose($probe);
        if (!$counts) { $scores[$candidate] = -1; continue; }
        $freq = array_count_values($counts);
        arsort($freq);
        $dominant = (int)array_key_first($freq);
        $consistent = (int)($freq[$dominant] ?? 0);
        $scores[$candidate] = ($dominant > 1 ? 1000 : 0) + ($consistent * 10) + $dominant;
    }
    arsort($scores);
    $delimiter = (string)array_key_first($scores);
    if (($scores[$delimiter] ?? 0) < 0) { fclose($fh); throw new RuntimeException('Não foi possível identificar o separador do CSV.'); }
    rewind($fh);
    $header = fgetcsv($fh, 0, $delimiter, '"', '\\');
    if (!$header) { fclose($fh); throw new RuntimeException('Cabeçalho não encontrado.'); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $header = array_map(static fn($v) => trim((string)$v), $header);
    $rows = [];
    $line = 1;
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
        $line++;
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $rows[] = ['line'=>$line, 'values'=>$row];
    }
    fclose($fh);
    return ['header'=>$header, 'rows'=>$rows, 'delimiter'=>$delimiter];
}
function class_norm_header(string $v): string {
    $v = mb_strtolower(trim($v), 'UTF-8');
    $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v) ?: $v;
    return preg_replace('/[^a-z0-9]+/', '', $v);
}
function class_header_guess(array $header, array $aliases): string {
    foreach ($header as $h) {
        if (in_array(class_norm_header($h), $aliases, true)) return $h;
    }
    foreach ($header as $h) {
        $n = class_norm_header($h);
        foreach ($aliases as $alias) if ($alias !== '' && str_contains($n, $alias)) return $h;
    }
    return '';
}
function class_int_value(mixed $v): ?int {
    $s = trim((string)$v);
    if ($s === '') return null;
    $s = preg_replace('/[^0-9-]/', '', $s);
    if ($s === '' || !preg_match('/^-?\d+$/', $s)) return null;
    return max(0, min(1000000, (int)$s));
}
function class_date_value(mixed $v): ?string {
    $s = trim((string)$v);
    if ($s === '') return null;
    foreach (['Y-m-d','d/m/Y','d-m-Y','m/Y'] as $format) {
        $d = DateTimeImmutable::createFromFormat('!' . $format, $s);
        if ($d && $d->format($format) === $s) return $format === 'm/Y' ? $d->format('Y-m-01') : $d->format('Y-m-d');
    }
    return null;
}
function class_value(array $row, array $map, string $field): string {
    $idx = $map[$field] ?? -1;
    return $idx >= 0 ? trim((string)($row[$idx] ?? '')) : '';
}

$pending = $_SESSION['pending_classes_import'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('A sessão expirou. Atualize a página e tente novamente.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'upload') {
            $f = $_FILES['csv_file'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione um arquivo CSV.');
            if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'csv') throw new RuntimeException('Envie um arquivo com extensão .csv.');
            if ((int)$f['size'] > 25 * 1024 * 1024) throw new RuntimeException('O arquivo ultrapassa 25 MB.');
            $target = $uploadDir . '/' . bin2hex(random_bytes(16)) . '.csv';
            if (!move_uploaded_file($f['tmp_name'], $target)) throw new RuntimeException('Não foi possível guardar o arquivo. Verifique storage/uploads.');
            $parsed = class_csv_read($target);
            if (count($parsed['header']) < 2 || !$parsed['rows']) { @unlink($target); throw new RuntimeException('O CSV precisa ter cabeçalho e pelo menos uma linha de dados.'); }
            $pending = ['path'=>$target,'name'=>basename((string)$f['name']),'header'=>$parsed['header'],'delimiter'=>$parsed['delimiter'],'rows'=>count($parsed['rows']),'sample'=>array_slice($parsed['rows'],0,6),'uploaded_at'=>time()];
            $_SESSION['pending_classes_import'] = $pending;
        } elseif ($action === 'cancel') {
            if ($pending && !empty($pending['path'])) @unlink($pending['path']);
            unset($_SESSION['pending_classes_import']);
            $pending = null;
            $messages[] = 'Prévia cancelada. Nenhuma turma foi importada.';
        } elseif ($action === 'confirm' && $pending && is_file((string)$pending['path'])) {
            if (($_POST['confirm_mapping'] ?? '') !== '1') throw new RuntimeException('Marque a confirmação do mapeamento antes de importar.');
            $header = $pending['header'];
            $fields = ['source_code','name','course_name','subsede','polo','active_apprentices','capacity','status','competence'];
            $map = [];
            foreach ($fields as $field) {
                $selected = (string)($_POST['map'][$field] ?? '');
                $map[$field] = $selected === '' ? -1 : array_search($selected, $header, true);
                if ($map[$field] === false) $map[$field] = -1;
            }
            if ($map['name'] < 0) throw new RuntimeException('Selecione a coluna que contém o nome da turma.');
            if ($map['polo'] < 0 && $map['subsede'] >= 0) throw new RuntimeException('Você selecionou a subsede, mas não o polo. Se o arquivo não contém polos, deixe ambos sem mapeamento.');
            $mapped = [];
            foreach ($map as $field => $index) {
                if ($index < 0) continue;
                if (isset($mapped[$index])) {
                    throw new RuntimeException('A mesma coluna foi atribuída a dois campos (' . $mapped[$index] . ' e ' . $field . '). Revise o mapeamento antes de importar.');
                }
                $mapped[$index] = $field;
            }
            $parsed = class_csv_read((string)$pending['path']);
            if (count($parsed['header']) !== count($header)) throw new RuntimeException('O cabeçalho do CSV mudou desde a prévia. Envie o arquivo novamente.');
            $preflightErrors = [];
            $seenCodes = [];
            $seenIdentities = [];
            // Verifica ambiguidades no banco existente antes de abrir a transação.
            $existingCodeCount = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE source_code = ?');
            $existingIdentityCount = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE name = ? AND (polo_id <=> ?)');
            $existingPoloId = $pdo->prepare('SELECT id FROM polos WHERE name = ? ORDER BY id');
            foreach ($parsed['rows'] as $item) {
                $row = $item['values'];
                $line = (int)$item['line'];
                if (count($row) !== count($header)) {
                    $preflightErrors[] = 'Linha ' . $line . ': contém ' . count($row) . ' campos; o cabeçalho tem ' . count($header) . '.';
                    continue;
                }
                $name = class_value($row, $map, 'name');
                if ($name === '') {
                    $preflightErrors[] = 'Linha ' . $line . ': nome da turma vazio.';
                    continue;
                }
                $code = class_value($row, $map, 'source_code');
                if ($code !== '') {
                    $key = mb_strtolower($code, 'UTF-8');
                    if (isset($seenCodes[$key])) $preflightErrors[] = 'Linha ' . $line . ': código de origem duplicado no arquivo (também na linha ' . $seenCodes[$key] . '): ' . $code . '.';
                    else $seenCodes[$key] = $line;
                } else {
                    $polo = mb_strtolower(class_value($row, $map, 'polo'), 'UTF-8');
                    $identity = mb_strtolower(trim($name), 'UTF-8') . '|' . $polo;
                    if (isset($seenIdentities[$identity])) $preflightErrors[] = 'Linha ' . $line . ': nome e polo repetidos sem código de origem (também na linha ' . $seenIdentities[$identity] . ').';
                    else $seenIdentities[$identity] = $line;
                }

                // Não escolhe arbitrariamente entre vários registros já existentes.
                if ($code !== '') {
                    $existingCodeCount->execute([$code]);
                    $codeMatches = (int)$existingCodeCount->fetchColumn();
                    if ($codeMatches > 1) {
                        $preflightErrors[] = 'Linha ' . $line . ': o código ' . $code . ' corresponde a ' . $codeMatches . ' turmas já cadastradas. Corrija a base antes de importar.';
                        continue;
                    }
                }
                $poloNameForMatch = class_value($row, $map, 'polo');
                $poloIdForMatch = null;
                if ($poloNameForMatch !== '') {
                    $existingPoloId->execute([$poloNameForMatch]);
                    $poloMatches = $existingPoloId->fetchAll(PDO::FETCH_COLUMN);
                    if (count($poloMatches) > 1) {
                        $preflightErrors[] = 'Linha ' . $line . ': o polo "' . $poloNameForMatch . '" corresponde a vários polos cadastrados; não é seguro identificar a turma.';
                        continue;
                    }
                    if (count($poloMatches) === 1) $poloIdForMatch = (int)$poloMatches[0];
                }
                // A lógica atual procura por nome+polo quando o código não encontra registro.
                $shouldFallbackToIdentity = $code === '';
                if (!$shouldFallbackToIdentity) {
                    $existingCodeCount->execute([$code]);
                    $shouldFallbackToIdentity = (int)$existingCodeCount->fetchColumn() === 0;
                }
                if ($shouldFallbackToIdentity) {
                    $existingIdentityCount->execute([$name, $poloIdForMatch]);
                    $identityMatches = (int)$existingIdentityCount->fetchColumn();
                    if ($identityMatches > 1) {
                        $preflightErrors[] = 'Linha ' . $line . ': nome "' . $name . '" e polo "' . $poloNameForMatch . '" correspondem a ' . $identityMatches . ' turmas já cadastradas. Nenhuma delas será atualizada automaticamente.';
                    }
                }
            }
            if ($preflightErrors) {
                $shown = array_slice($preflightErrors, 0, 12);
                $suffix = count($preflightErrors) > count($shown) ? ' E mais ' . (count($preflightErrors) - count($shown)) . ' erro(s).' : '';
                throw new RuntimeException("Pré-validação interrompida; nenhuma linha foi gravada:\n- " . implode("\n- ", $shown) . $suffix);
            }
            $pdo->beginTransaction();
            $batchStmt = $pdo->prepare("INSERT INTO import_batches (source_type,file_name,status,total_rows,valid_rows,rejected_rows,uploaded_by) VALUES ('classes_csv',?,'validated',?,0,0,?)");
            $batchStmt->execute([$pending['name'],$pending['rows'],(int)(current_user()['id'] ?? 0) ?: null]);
            $batchId = (int)$pdo->lastInsertId();
            $subFind = $pdo->prepare('SELECT id FROM subsedes WHERE name = ? LIMIT 1');
            $subAdd = $pdo->prepare('INSERT INTO subsedes (name, active) VALUES (?,1)');
            $poloFind = $pdo->prepare('SELECT id FROM polos WHERE name = ? LIMIT 1');
            $poloAdd = $pdo->prepare('INSERT INTO polos (name, source_code, active) VALUES (?, ?, 1)');
            $histFind = $pdo->prepare('SELECT id, subsede_id, valid_from FROM polo_subsede_history WHERE polo_id = ? AND valid_until IS NULL ORDER BY valid_from DESC LIMIT 1');
            $histClose = $pdo->prepare('UPDATE polo_subsede_history SET valid_until = ? WHERE id = ?');
            $histAdd = $pdo->prepare('INSERT INTO polo_subsede_history (polo_id, subsede_id, valid_from, created_by) VALUES (?,?,?,?)');
            $classFindCode = $pdo->prepare('SELECT id FROM classes WHERE source_code = ? LIMIT 1');
            $classFindName = $pdo->prepare('SELECT id FROM classes WHERE name = ? AND (polo_id <=> ?) LIMIT 1');
            $classUpdate = $pdo->prepare('UPDATE classes SET name=?, group_id=?, polo_id=?, course_name=?, active_apprentices=?, capacity=?, status=?, competence=?, imported_batch_id=? WHERE id=?');
            $classInsert = $pdo->prepare('INSERT INTO classes (source_code,name,group_id,polo_id,course_name,active_apprentices,capacity,status,competence,imported_batch_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $groupFind = $pdo->prepare('SELECT id FROM groups_catalog WHERE name = ? LIMIT 1');
            $groupAdd = $pdo->prepare('INSERT INTO groups_catalog (name, active) VALUES (?,1)');
            $imported = 0; $skipped = 0; $seen = [];
            foreach ($parsed['rows'] as $item) {
                $row = $item['values'];
                $name = class_value($row,$map,'name');
                $code = class_value($row,$map,'source_code');
                $course = class_value($row,$map,'course_name');
                $subName = class_value($row,$map,'subsede');
                $poloName = class_value($row,$map,'polo');
                $apprentices = class_int_value(class_value($row,$map,'active_apprentices')) ?? 0;
                $capacity = class_int_value(class_value($row,$map,'capacity'));
                $status = class_value($row,$map,'status');
                $competence = class_date_value(class_value($row,$map,'competence'));
                $subId = null; $poloId = null;
                if ($subName !== '') {
                    $subFind->execute([$subName]); $subId = $subFind->fetchColumn();
                    if (!$subId) { $subAdd->execute([$subName]); $subId = (int)$pdo->lastInsertId(); }
                    else $subId = (int)$subId;
                }
                if ($poloName !== '') {
                    $poloFind->execute([$poloName]); $poloId = $poloFind->fetchColumn();
                    if (!$poloId) { $poloAdd->execute([$poloName,$poloName]); $poloId = (int)$pdo->lastInsertId(); }
                    else $poloId = (int)$poloId;
                    if ($subId) {
                        $effective = $competence ?? date('Y-m-01');
                        $histFind->execute([$poloId]); $current = $histFind->fetch();
                        if (!$current) $histAdd->execute([$poloId,$subId,$effective,(int)(current_user()['id'] ?? 0) ?: null]);
                        elseif ((int)$current['subsede_id'] !== $subId && $effective > $current['valid_from']) {
                            $histClose->execute([$effective,(int)$current['id']]);
                            $histAdd->execute([$poloId,$subId,$effective,(int)(current_user()['id'] ?? 0) ?: null]);
                        }
                    }
                }
                $groupId = null;
                if ($course !== '') {
                    $groupFind->execute([$course]); $groupId = $groupFind->fetchColumn();
                    if (!$groupId) { $groupAdd->execute([$course]); $groupId = (int)$pdo->lastInsertId(); }
                    else $groupId = (int)$groupId;
                }
                $existingId = false;
                if ($code !== '') { $classFindCode->execute([$code]); $existingId = $classFindCode->fetchColumn(); }
                if (!$existingId) { $classFindName->execute([$name,$poloId]); $existingId = $classFindName->fetchColumn(); }
                if ($existingId) {
                    $classUpdate->execute([$name,$groupId,$poloId,$course !== '' ? $course : null,$apprentices,$capacity,$status !== '' ? $status : null,$competence,$batchId,(int)$existingId]);
                } else {
                    $classInsert->execute([$code !== '' ? $code : null,$name,$groupId,$poloId,$course !== '' ? $course : null,$apprentices,$capacity,$status !== '' ? $status : null,$competence,$batchId]);
                }
                $imported++;
            }
            $pdo->prepare("UPDATE import_batches SET status='imported', valid_rows=?, rejected_rows=? WHERE id=?")->execute([$imported,$skipped,$batchId]);
            audit($pdo,(int)$user['id'],'import_classes_csv','import_batch',$batchId,['imported'=>$imported,'skipped'=>$skipped,'file'=>$pending['name']]);
            $pdo->commit();
            @unlink((string)$pending['path']);
            unset($_SESSION['pending_classes_import']);
            $pending = null;
            $messages[] = "Importação concluída: {$imported} turmas processadas; {$skipped} linhas sem nome de turma ignoradas.";
        } else {
            throw new RuntimeException('A prévia expirou. Envie o arquivo novamente.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($ex instanceof PDOException && (string)$ex->getCode() === '23000') $errors[] = 'Conflito de integridade no banco; toda a transação foi desfeita. Verifique códigos duplicados, relações e campos obrigatórios. Detalhe técnico: ' . $ex->getMessage();
        else $errors[] = $ex->getMessage();
    }
}
$pending = $_SESSION['pending_classes_import'] ?? null;
$fieldsMeta = [
 'source_code'=>['Código da turma',['codigo','codigodaturma','codturma','turmaid','idturma','sourcecode']],
 'name'=>['Nome da turma (obrigatório)',['turma','nometurma','nomedaturma','nome','class','classname','grupo']],
 'course_name'=>['Curso / grupo',['curso','nomecurso','cursodaturma','nomegrupo','group','course','coursename']],
 'subsede'=>['Subsede responsável',['subsede','nomesubsede','unidade','unidadeensino','filial']],
 'polo'=>['Polo / local da turma',['polo','nomepolo','localcurso','localdaturma','pololocalcurso','local']],
 'active_apprentices'=>['Aprendizes ativos (número)',['aprendizes','aprendizesativos','quantidadeaprendizes','qtdaprendizes','alunos','matriculados','ativos']],
 'capacity'=>['Capacidade / vagas (número)',['capacidade','vagas','quantidadevagas','limitevagas','capacity']],
 'status'=>['Status da turma',['status','situacao','situacaoturma']],
 'competence'=>['Competência / data de referência',['competencia','datareferencia','mesreferencia','dataturma','data','inicio']],
];
$guess = [];
if ($pending) foreach ($fieldsMeta as $field=>$meta) $guess[$field] = class_header_guess($pending['header'],$meta[1]);
page_header('Importação de turmas');
?>
<?php foreach ($messages as $m): ?><div class="notice"><strong>Sucesso:</strong> <?= e($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $m): ?><div class="error"><?= e($m) ?></div><?php endforeach; ?>
<div class="notice"><strong>Importação com mapeamento manual.</strong> A tela não presume o significado das colunas. Confira cada campo antes de confirmar. Campos numéricos que não forem mapeados ficam com zero ou vazios; turmas existentes serão atualizadas pelo código de origem ou, na ausência dele, pelo nome e polo.</div>
<section class="panel">
<h2>1. Enviar CSV de turmas</h2>
<p class="muted">Aceita CSV separado por vírgula, ponto e vírgula ou tabulação. Limite de 25 MB. O arquivo é lido para prévia e só grava no banco após confirmação.</p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="upload">
<label for="csv_file">Arquivo CSV</label><input id="csv_file" type="file" name="csv_file" accept=".csv,text/csv" required>
<p><button type="submit">Enviar e ler cabeçalhos</button></p>
</form>
</section>
<?php if ($pending): ?>
<section class="panel">
<h2>2. Conferir colunas e prévia</h2>
<p><strong>Arquivo:</strong> <?= e($pending['name']) ?> · <strong>Linhas:</strong> <?= (int)$pending['rows'] ?> · <strong>Colunas detectadas:</strong> <?= count($pending['header']) ?></p>
<form method="post">
<input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="confirm">
<div class="filters">
<?php foreach ($fieldsMeta as $field=>$meta): ?>
<label><?= e($meta[0]) ?><?= $field==='name' ? ' *' : '' ?>
<select name="map[<?= e($field) ?>]">
<option value="">Não mapear</option>
<?php foreach ($pending['header'] as $h): ?><option value="<?= e($h) ?>" <?= ($guess[$field] ?? '')===$h ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?>
</select>
</label>
<?php endforeach; ?>
</div>
<h3>Amostra das primeiras linhas</h3>
<div style="overflow-x:auto"><table><thead><tr><?php foreach ($pending['header'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($pending['sample'] as $item): ?><tr><?php foreach ($pending['header'] as $idx=>$h): ?><td><?= e((string)($item['values'][$idx] ?? '')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div>
<p><label><input type="checkbox" name="confirm_mapping" value="1" required> Conferi o mapeamento e a amostra antes de importar.</label></p>
<p><button type="submit">Confirmar e importar turmas</button></p>
</form>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel"><button type="submit">Cancelar prévia</button></form>
</section>
<?php endif; ?>
<?php page_footer(); ?>

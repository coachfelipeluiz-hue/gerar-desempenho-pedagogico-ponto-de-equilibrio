<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/import_class_fields.php';
require __DIR__ . '/../app/layout.php';
require_login();
$user = current_user();
if (!is_master_user()) {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">Importações de turmas podem alterar dados de várias unidades e, até existir filtragem por unidade, ficam restritas ao Master.</div>';
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
    $delimiters = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($delimiters);
    $delimiter = (string)array_key_first($delimiters);
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
            $parsed = class_csv_read((string)$pending['path']);
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
            $classColumns = 'id, source_code, group_id, polo_id, course_name, active_apprentices, capacity, status, competence';
            $classFindCode = $pdo->prepare("SELECT {$classColumns} FROM classes WHERE source_code = ?");
            $classFindName = $pdo->prepare("SELECT {$classColumns} FROM classes WHERE name = ? AND (polo_id <=> ?)");
            $classFindNameOnly = $pdo->prepare("SELECT {$classColumns} FROM classes WHERE name = ?");
            $classUpdate = $pdo->prepare('UPDATE classes SET source_code=?, name=?, group_id=?, polo_id=?, course_name=?, active_apprentices=?, capacity=?, status=?, competence=?, imported_batch_id=? WHERE id=?');
            $classInsert = $pdo->prepare('INSERT INTO classes (source_code,name,group_id,polo_id,course_name,active_apprentices,capacity,status,competence,imported_batch_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $groupFind = $pdo->prepare('SELECT id FROM groups_catalog WHERE name = ? LIMIT 1');
            $groupAdd = $pdo->prepare('INSERT INTO groups_catalog (name, active) VALUES (?,1)');
            $imported = 0; $skipped = 0; $seen = []; $seenIds = [];
            foreach ($parsed['rows'] as $item) {
                $row = $item['values'];
                $name = class_value($row,$map,'name');
                if ($name === '') { $skipped++; continue; }
                $code = class_value($row,$map,'source_code');
                $course = class_value($row,$map,'course_name');
                $subName = class_value($row,$map,'subsede');
                $poloName = class_value($row,$map,'polo');
                $apprenticesRaw = class_value($row,$map,'active_apprentices');
                $apprentices = class_int_value($apprenticesRaw);
                if ($apprenticesRaw !== '' && $apprentices === null) throw new RuntimeException('Quantidade de aprendizes inválida na linha ' . $item['line'] . '.');
                $capacityRaw = class_value($row,$map,'capacity');
                $capacity = class_int_value($capacityRaw);
                if ($capacityRaw !== '' && $capacity === null) throw new RuntimeException('Capacidade inválida na linha ' . $item['line'] . '.');
                $status = class_value($row,$map,'status');
                $competenceRaw = class_value($row,$map,'competence');
                $competence = class_date_value($competenceRaw);
                if ($competenceRaw !== '' && $competence === null) throw new RuntimeException('Competência/data inválida na linha ' . $item['line'] . '.');
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
                $identity = class_import_identity_key($code, $name, ($map['polo'] >= 0 && $poloName !== '') ? (int)$poloId : null);
                if (isset($seen[$identity])) throw new RuntimeException('A linha ' . $item['line'] . ' repete uma turma já presente neste CSV. Nenhuma alteração foi gravada.');
                $seen[$identity] = true;

                $existing = null;
                if ($code !== '') {
                    $classFindCode->execute([$code]);
                    $matches = $classFindCode->fetchAll();
                    if (count($matches) > 1) throw new RuntimeException('O código de origem ' . $code . ' identifica mais de uma turma no banco. Corrija a duplicidade antes de importar.');
                    $existing = $matches[0] ?? null;
                }
                if (!$existing && $map['polo'] >= 0 && $poloName !== '') {
                    $classFindName->execute([$name, (int)$poloId]);
                    $matches = $classFindName->fetchAll();
                    if (count($matches) > 1) throw new RuntimeException('O nome e polo da linha ' . $item['line'] . ' correspondem a mais de uma turma no banco.');
                    $existing = $matches[0] ?? null;
                }
                if (!$existing && ($map['polo'] < 0 || $poloName === '')) {
                    $classFindNameOnly->execute([$name]);
                    $matches = $classFindNameOnly->fetchAll();
                    if (count($matches) > 1) throw new RuntimeException('O nome da linha ' . $item['line'] . ' é ambíguo. Mapeie código de origem ou polo para identificar a turma.');
                    $existing = $matches[0] ?? null;
                }
                $existingId = $existing ? (int)$existing['id'] : null;
                if ($existing && $code !== '' && trim((string)($existing['source_code'] ?? '')) !== ''
                    && mb_strtolower(trim((string)$existing['source_code']), 'UTF-8') !== mb_strtolower(trim($code), 'UTF-8')) {
                    throw new RuntimeException('A linha ' . $item['line'] . ' corresponde a uma turma com outro código de origem. Resolva a identidade antes de importar.');
                }
                if ($existingId !== null && isset($seenIds[$existingId])) {
                    throw new RuntimeException('Duas linhas do CSV apontam para a mesma turma existente. Nenhuma alteração foi gravada.');
                }
                if ($existingId !== null) $seenIds[$existingId] = true;

                $groupId = null;
                if ($course !== '') {
                    $groupFind->execute([$course]); $groupId = $groupFind->fetchColumn();
                    if (!$groupId) { $groupAdd->execute([$course]); $groupId = (int)$pdo->lastInsertId(); }
                    else $groupId = (int)$groupId;
                }
                $values = merge_class_import_fields($existing, [
                    'source_code' => $code !== '' ? $code : null,
                    'name' => $name,
                    'group_id' => $groupId,
                    'polo_id' => ($map['polo'] >= 0 && $poloName !== '') ? (int)$poloId : null,
                    'course_name' => $course !== '' ? $course : null,
                    'active_apprentices' => $apprentices,
                    'capacity' => $capacity,
                    'status' => $status !== '' ? $status : null,
                    'competence' => $competence,
                ]);
                if ($existingId) {
                    $classUpdate->execute([$values['source_code'],$values['name'],$values['group_id'],$values['polo_id'],$values['course_name'],$values['active_apprentices'],$values['capacity'],$values['status'],$values['competence'],$batchId,$existingId]);
                } else {
                    $classInsert->execute([$values['source_code'],$values['name'],$values['group_id'],$values['polo_id'],$values['course_name'],$values['active_apprentices'],$values['capacity'],$values['status'],$values['competence'],$batchId]);
                    $createdId = (int)$pdo->lastInsertId();
                    if ($createdId > 0) $seenIds[$createdId] = true;
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
        if ($ex instanceof PDOException && (string)$ex->getCode() === '23000') $errors[] = 'A importação encontrou um conflito de dados (por exemplo, código de origem duplicado). Confira o CSV e tente novamente.';
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
<div class="notice"><strong>Importação com mapeamento manual.</strong> A tela não presume o significado das colunas. Confira cada campo antes de confirmar. Em turmas existentes, campos não mapeados ou vazios são preservados. Para novas turmas, aprendizes sem valor começam em zero e outros campos opcionais ficam vazios. Duplicatas ou nomes ambíguos cancelam a importação, sem gravar alterações.</div>
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

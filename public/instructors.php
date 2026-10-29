<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();

$user = current_user();
if (!can_access_module('instructors')) {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">Você não tem permissão para acessar instrutores e horas de instrução.</div>';
    page_footer();
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    page_header('Instrutores e horas');
    echo '<div class="error">Não foi possível conectar ao banco de dados. Confira a configuração local.</div>';
    page_footer();
    exit;
}
if (empty($_SESSION['csrf_instructors'])) $_SESSION['csrf_instructors'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_instructors'];
$messages = [];
$errors = [];
$isMaster = ($user['role'] ?? '') === 'master';
$allowedUnits = array_map('intval', user_unit_ids($pdo));
$canCreateInstructor = can_perform_module_action('instructors', 'create');
$canEditInstructor = can_perform_module_action('instructors', 'edit');
$canManageHours = $canCreateInstructor || $canEditInstructor;

function instructor_user_can_unit(bool $isMaster, array $allowedUnits, ?int $unitId): bool {
    if ($isMaster) return true;
    return $unitId !== null && in_array($unitId, $allowedUnits, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('A sessão expirou. Atualize a página e tente novamente.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_instructor') {
            $id = (int)($_POST['instructor_id'] ?? 0);
            $permission = $id > 0 ? 'edit' : 'create';
            if (!can_perform_module_action('instructors', $permission)) {
                throw new RuntimeException('Você não tem permissão para cadastrar ou alterar instrutores.');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            $code = trim((string)($_POST['source_code'] ?? ''));
            $unitId = (int)($_POST['subsede_id'] ?? 0) ?: null;
            $category = trim((string)($_POST['category'] ?? ''));
            $position = trim((string)($_POST['position_name'] ?? ''));
            $active = isset($_POST['active']) ? 1 : 0;
            if ($name === '' || mb_strlen($name) > 180) throw new RuntimeException('Informe o nome do instrutor (até 180 caracteres).');
            if (mb_strlen($code) > 120 || mb_strlen($category) > 120 || mb_strlen($position) > 160) throw new RuntimeException('Um dos campos ultrapassou o tamanho permitido.');
            if (!instructor_user_can_unit($isMaster, $allowedUnits, $unitId)) throw new RuntimeException('Selecione uma subsede à qual seu usuário tenha acesso.');
            if ($unitId) {
                $check = $pdo->prepare('SELECT id FROM subsedes WHERE id = ? AND active = 1');
                $check->execute([$unitId]);
                if (!$check->fetch()) throw new RuntimeException('A subsede selecionada não está ativa.');
            }
            if ($id > 0) {
                $check = $pdo->prepare('SELECT id FROM instructors WHERE id = ?');
                $check->execute([$id]);
                if (!$check->fetch()) throw new RuntimeException('Instrutor não encontrado.');
                $stmt = $pdo->prepare('UPDATE instructors SET source_code = ?, name = ?, subsede_id = ?, category = ?, position_name = ?, active = ? WHERE id = ?');
                $stmt->execute([$code !== '' ? $code : null, $name, $unitId, $category !== '' ? $category : null, $position !== '' ? $position : null, $active, $id]);
                audit($pdo, (int)$user['id'], 'update', 'instructor', $id, ['name'=>$name,'subsede_id'=>$unitId,'active'=>$active]);
                $messages[] = 'Instrutor atualizado.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO instructors (source_code, name, subsede_id, category, position_name, active) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$code !== '' ? $code : null, $name, $unitId, $category !== '' ? $category : null, $position !== '' ? $position : null, $active]);
                $id = (int)$pdo->lastInsertId();
                audit($pdo, (int)$user['id'], 'create', 'instructor', $id, ['name'=>$name,'subsede_id'=>$unitId,'active'=>$active]);
                $messages[] = 'Instrutor cadastrado.';
            }
        } elseif ($action === 'save_hours') {
            if (!$canManageHours) throw new RuntimeException('Você não tem permissão para registrar ou alterar horas de instrução.');
            $instructorId = (int)($_POST['instructor_id'] ?? 0);
            $classId = (int)($_POST['class_id'] ?? 0);
            $competence = (string)($_POST['competence'] ?? date('Y-m'));
            $hoursRaw = str_replace(',', '.', trim((string)($_POST['hours'] ?? '')));
            $costRaw = str_replace(',', '.', trim((string)($_POST['cost_per_hour'] ?? '')));
            $hours = is_numeric($hoursRaw) ? (float)$hoursRaw : -1;
            $cost = is_numeric($costRaw) ? (float)$costRaw : -1;
            $includesCharges = isset($_POST['cost_hour_includes_charges']) ? 1 : 0;
            $sourceNote = trim((string)($_POST['source_note'] ?? ''));
            if ($instructorId < 1 || $classId < 1) throw new RuntimeException('Selecione o instrutor e a turma.');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competence)) throw new RuntimeException('Selecione uma competência válida.');
            if ($hours <= 0 || $hours > 744 || $cost < 0 || $cost > 1000000) throw new RuntimeException('Informe horas maiores que zero e um custo por hora válido.');
            if (mb_strlen($sourceNote) > 255) throw new RuntimeException('A observação deve ter até 255 caracteres.');
            $competenceDate = $competence . '-01';
            $existingHours = $pdo->prepare('SELECT id FROM instruction_hours WHERE instructor_id = ? AND class_id = ? AND competence = ?');
            $existingHours->execute([$instructorId, $classId, $competenceDate]);
            $permission = $existingHours->fetchColumn() ? 'edit' : 'create';
            if (!can_perform_module_action('instructors', $permission)) {
                throw new RuntimeException('Você não tem permissão para registrar ou alterar horas de instrução.');
            }
            $stmt = $pdo->prepare('SELECT id, subsede_id FROM instructors WHERE id = ? AND active = 1');
            $stmt->execute([$instructorId]);
            $instructor = $stmt->fetch();
            if (!$instructor) throw new RuntimeException('Instrutor não encontrado ou inativo.');
            if (!instructor_user_can_unit($isMaster, $allowedUnits, $instructor['subsede_id'] !== null ? (int)$instructor['subsede_id'] : null)) throw new RuntimeException('O instrutor não pertence a uma subsede autorizada.');
            $stmt = $pdo->prepare('SELECT cl.id, cl.polo_id FROM classes cl WHERE cl.id = ?');
            $stmt->execute([$classId]);
            $class = $stmt->fetch();
            if (!$class) throw new RuntimeException('Turma não encontrada.');
            if (!$isMaster) {
                $stmt = $pdo->prepare('SELECT 1 FROM polo_subsede_history WHERE polo_id = ? AND subsede_id IN (' . ($allowedUnits ? implode(',', array_fill(0, count($allowedUnits), '?')) : '0') . ') AND valid_from <= LAST_DAY(CONCAT(?, "-01")) AND (valid_until IS NULL OR valid_until >= CONCAT(?, "-01")) LIMIT 1');
                $stmt->execute(array_merge([(int)$class['polo_id']], $allowedUnits, [$competence, $competence]));
                if (!$stmt->fetchColumn()) throw new RuntimeException('A turma não pertence a uma subsede autorizada nesta competência.');
            }
            $competenceDate = $competence . '-01';
            $stmt = $pdo->prepare('INSERT INTO instruction_hours (instructor_id, class_id, competence, hours, cost_per_hour, cost_hour_includes_charges, approved, source_note)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE hours = VALUES(hours), cost_per_hour = VALUES(cost_per_hour), cost_hour_includes_charges = VALUES(cost_hour_includes_charges), source_note = VALUES(source_note), approved = 0');
            $stmt->execute([$instructorId, $classId, $competenceDate, $hours, $cost, $includesCharges, $sourceNote !== '' ? $sourceNote : null]);
            audit($pdo, (int)$user['id'], 'save', 'instruction_hours', null, ['instructor_id'=>$instructorId,'class_id'=>$classId,'competence'=>$competenceDate,'hours'=>$hours,'cost_per_hour'=>$cost]);
            $messages[] = 'Horas registradas. O lançamento ficou pendente de aprovação.';
        } elseif ($action === 'approve_hours') {
            if (!$isMaster) throw new RuntimeException('Somente o Master pode aprovar horas nesta versão.');
            $recordId = (int)($_POST['record_id'] ?? 0);
            $approved = (int)($_POST['approved'] ?? 0) === 1 ? 1 : 0;
            $stmt = $pdo->prepare('UPDATE instruction_hours SET approved = ? WHERE id = ?');
            $stmt->execute([$approved, $recordId]);
            audit($pdo, (int)$user['id'], $approved ? 'approve' : 'unapprove', 'instruction_hours', $recordId);
            $messages[] = $approved ? 'Horas aprovadas e consideradas nos custos conhecidos.' : 'Aprovação retirada.';
        } else {
            throw new RuntimeException('Ação não reconhecida.');
        }
    } catch (Throwable $ex) {
        if ($ex instanceof PDOException && (string)$ex->getCode() === '23000') $errors[] = 'Já existe um registro com esses dados ou há uma referência inválida.';
        else $errors[] = $ex->getMessage();
    }
}

$unitsSql = 'SELECT id, name FROM subsedes WHERE active = 1';
$unitsParams = [];
if (!$isMaster) {
    if ($allowedUnits) {
        $unitsSql .= ' AND id IN (' . implode(',', array_fill(0, count($allowedUnits), '?')) . ')';
        $unitsParams = $allowedUnits;
    } else $unitsSql .= ' AND 1 = 0';
}
$unitsSql .= ' ORDER BY name';
$stmt = $pdo->prepare($unitsSql);
$stmt->execute($unitsParams);
$subsedes = $stmt->fetchAll();

$instructorSql = 'SELECT i.id, i.name, i.source_code, i.subsede_id, i.category, i.position_name, i.active, s.name AS subsede_name FROM instructors i LEFT JOIN subsedes s ON s.id = i.subsede_id';
$params = [];
if (!$isMaster) {
    if ($allowedUnits) {
        $instructorSql .= ' WHERE i.subsede_id IN (' . implode(',', array_fill(0, count($allowedUnits), '?')) . ')';
        $params = $allowedUnits;
    } else $instructorSql .= ' WHERE 1 = 0';
}
$instructorSql .= ' ORDER BY i.active DESC, i.name';
$stmt = $pdo->prepare($instructorSql);
$stmt->execute($params);
$instructors = $stmt->fetchAll();

$classSql = 'SELECT cl.id, cl.name, cl.course_name, cl.polo_id FROM classes cl';
$classParams = [];
if (!$isMaster) {
    if ($allowedUnits) {
        $classSql .= ' WHERE EXISTS (SELECT 1 FROM polo_subsede_history h WHERE h.polo_id = cl.polo_id AND h.subsede_id IN (' . implode(',', array_fill(0, count($allowedUnits), '?')) . ') AND (h.valid_until IS NULL OR h.valid_until >= CURDATE()))';
        $classParams = $allowedUnits;
    } else $classSql .= ' WHERE 1 = 0';
}
$classSql .= ' ORDER BY cl.name';
$stmt = $pdo->prepare($classSql);
$stmt->execute($classParams);
$classes = $stmt->fetchAll();

$month = (string)($_GET['competence'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$sql = 'SELECT ih.id, ih.competence, ih.hours, ih.cost_per_hour, ih.cost_hour_includes_charges, ih.approved, ih.source_note, i.name AS instructor_name, s.name AS subsede_name, cl.name AS class_name, cl.course_name
 FROM instruction_hours ih INNER JOIN instructors i ON i.id = ih.instructor_id INNER JOIN classes cl ON cl.id = ih.class_id LEFT JOIN subsedes s ON s.id = i.subsede_id WHERE ih.competence BETWEEN ? AND ?';
$params = [$start, $end];
if (!$isMaster) {
    if ($allowedUnits) {
        $sql .= ' AND i.subsede_id IN (' . implode(',', array_fill(0, count($allowedUnits), '?')) . ')';
        array_push($params, ...$allowedUnits);
    } else $sql .= ' AND 1 = 0';
}
$sql .= ' ORDER BY ih.competence DESC, i.name, cl.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hourRows = $stmt->fetchAll();
$money = static fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$totalHours = array_sum(array_map(static fn($r) => (float)$r['hours'], $hourRows));
$totalCost = array_sum(array_map(static fn($r) => (float)$r['hours'] * (float)$r['cost_per_hour'], $hourRows));
page_header('Instrutores e horas');
?>
<?php foreach ($messages as $m): ?><div class="notice"><?= e($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $err): ?><div class="error"><?= e($err) ?></div><?php endforeach; ?>

<section class="panel">
    <h2>Cadastrar instrutor</h2>
    <?php if ($canCreateInstructor): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_instructor">
        <div class="filters">
            <label>Nome completo<input name="name" maxlength="180" required></label>
            <label>Código de origem<input name="source_code" maxlength="120"></label>
            <label>Subsede
                <select name="subsede_id" required><option value="">Selecione</option><?php foreach ($subsedes as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Categoria<input name="category" maxlength="120" placeholder="Ex.: horista, mensalista"></label>
            <label>Cargo<input name="position_name" maxlength="160"></label>
            <label><input type="checkbox" name="active" value="1" checked> Ativo</label>
            <div><button type="submit">Cadastrar instrutor</button></div>
        </div>
    </form>
    <?php else: ?>
    <p class="muted">Seu perfil não tem permissão para cadastrar instrutores.</p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Registrar horas de instrução</h2>
    <p>O registro é único por instrutor, turma e competência. Se você salvar novamente a mesma combinação, o registro será atualizado e voltará a ficar pendente de aprovação.</p>
    <?php if ($canManageHours): ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_hours">
        <div class="filters">
            <label>Instrutor<select name="instructor_id" required><option value="">Selecione</option><?php foreach ($instructors as $i): if (!(int)$i['active']) continue; ?><option value="<?= (int)$i['id'] ?>"><?= e($i['name']) ?><?= $i['subsede_name'] ? ' — ' . e($i['subsede_name']) : '' ?></option><?php endforeach; ?></select></label>
            <label>Turma<select name="class_id" required><option value="">Selecione</option><?php foreach ($classes as $cl): ?><option value="<?= (int)$cl['id'] ?>"><?= e($cl['name']) ?><?= $cl['course_name'] ? ' — ' . e($cl['course_name']) : '' ?></option><?php endforeach; ?></select></label>
            <label>Competência<input type="month" name="competence" value="<?= e(date('Y-m')) ?>" required></label>
            <label>Horas no mês<input type="number" name="hours" min="0.01" max="744" step="0.01" required></label>
            <label>Custo por hora (R$)<input name="cost_per_hour" inputmode="decimal" placeholder="Ex.: 35,00" required></label>
            <label><input type="checkbox" name="cost_hour_includes_charges" value="1"> Custo/hora já inclui encargos</label>
            <label style="grid-column:1/-1">Origem / observações<input name="source_note" maxlength="255" placeholder="Ex.: apontamento mensal validado"></label>
            <div><button type="submit">Registrar horas</button></div>
        </div>
    </form>
    <?php else: ?>
    <p class="muted">Seu perfil não tem permissão para registrar ou alterar horas de instrução.</p>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Instrutores cadastrados</h2>
    <div style="overflow-x:auto"><table><thead><tr><th>Nome</th><th>Código</th><th>Subsede</th><th>Categoria</th><th>Cargo</th><th>Status</th></tr></thead><tbody>
    <?php if (!$instructors): ?><tr><td colspan="6">Nenhum instrutor cadastrado.</td></tr><?php endif; ?>
    <?php foreach ($instructors as $i): ?><tr><td><?= e($i['name']) ?></td><td><?= e($i['source_code'] ?? '—') ?></td><td><?= e($i['subsede_name'] ?? '—') ?></td><td><?= e($i['category'] ?? '—') ?></td><td><?= e($i['position_name'] ?? '—') ?></td><td><?= (int)$i['active'] ? 'Ativo' : 'Inativo' ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
</section>

<section class="panel">
    <h2>Horas registradas na competência</h2>
    <form method="get" class="filters"><label>Competência<input type="month" name="competence" value="<?= e($month) ?>"></label><div style="align-self:end"><button type="submit">Consultar</button></div></form>
    <p><strong><?= number_format($totalHours, 2, ',', '.') ?> horas</strong> · Custo calculado: <strong><?= e($money($totalCost)) ?></strong> · <?= count($hourRows) ?> lançamentos</p>
    <div style="overflow-x:auto"><table><thead><tr><th>Competência</th><th>Instrutor</th><th>Subsede</th><th>Turma</th><th>Horas</th><th>Custo/hora</th><th>Total</th><th>Encargos incluídos?</th><th>Status</th><?php if ($isMaster): ?><th>Ação</th><?php endif; ?></tr></thead><tbody>
    <?php if (!$hourRows): ?><tr><td colspan="<?= $isMaster ? 10 : 9 ?>">Nenhuma hora registrada nesta competência.</td></tr><?php endif; ?>
    <?php foreach ($hourRows as $h): ?><tr>
        <td><?= e(date('m/Y', strtotime($h['competence']))) ?></td><td><?= e($h['instructor_name']) ?></td><td><?= e($h['subsede_name'] ?? '—') ?></td><td><?= e($h['class_name']) ?><?= $h['course_name'] ? ' — ' . e($h['course_name']) : '' ?></td>
        <td><?= number_format((float)$h['hours'], 2, ',', '.') ?></td><td><?= e($money($h['cost_per_hour'])) ?></td><td><?= e($money((float)$h['hours'] * (float)$h['cost_per_hour'])) ?></td>
        <td><?= (int)$h['cost_hour_includes_charges'] ? 'Sim' : 'Não' ?></td><td><?= (int)$h['approved'] ? 'Aprovado' : 'Pendente' ?></td>
        <?php if ($isMaster): ?><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="approve_hours"><input type="hidden" name="record_id" value="<?= (int)$h['id'] ?>"><input type="hidden" name="approved" value="<?= (int)$h['approved'] ? 0 : 1 ?>"><button type="submit"><?= (int)$h['approved'] ? 'Retirar aprovação' : 'Aprovar' ?></button></form></td><?php endif; ?>
    </tr><?php endforeach; ?>
    </tbody></table></div>
</section>
<?php page_footer(); ?>

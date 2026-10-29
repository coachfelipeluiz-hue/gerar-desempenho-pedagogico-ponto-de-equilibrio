<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();

$user = current_user();
if (!can_access_module('classes')) {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">Você não tem permissão para consultar turmas.</div>';
    page_footer();
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    page_header('Turmas');
    echo '<div class="error">Não foi possível conectar ao banco de dados.</div>';
    page_footer();
    exit;
}

$month = (string)($_GET['competence'] ?? date('Y-m'));
if (!preg_match('/^\\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$isMaster = ($user['role'] ?? '') === 'master';
$allowedUnits = $isMaster ? [] : array_map('intval', user_unit_ids($pdo));
$selectedUnit = (int)($_GET['subsede_id'] ?? 0);
$selectedPolo = (int)($_GET['polo_id'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));
$minCount = (string)($_GET['range'] ?? 'all');
if (!in_array($minCount, ['all','below','regular','optimal'], true)) $minCount = 'all';

$unitOptions = [];
if ($isMaster) {
    $unitOptions = $pdo->query('SELECT id, name FROM subsedes WHERE active = 1 ORDER BY name')->fetchAll();
} elseif ($allowedUnits) {
    $ph = implode(',', array_fill(0, count($allowedUnits), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM subsedes WHERE active = 1 AND id IN ($ph) ORDER BY name");
    $stmt->execute($allowedUnits);
    $unitOptions = $stmt->fetchAll();
}
$unitFilter = $isMaster ? [] : $allowedUnits;
if ($selectedUnit > 0) {
    if ($isMaster || in_array($selectedUnit, $allowedUnits, true)) $unitFilter = [$selectedUnit];
    else $unitFilter = [-1];
}
$poloSql = 'SELECT DISTINCT p.id, p.name FROM polos p INNER JOIN polo_subsede_history h ON h.polo_id=p.id WHERE p.active=1 AND h.valid_from <= ? AND (h.valid_until IS NULL OR h.valid_until >= ?)';
$poloParams = [$end, $start];
if ($unitFilter) {
    $ph = implode(',', array_fill(0, count($unitFilter), '?'));
    $poloSql .= " AND h.subsede_id IN ($ph)";
    array_push($poloParams, ...$unitFilter);
} elseif (!$isMaster) {
    $poloSql .= ' AND 1=0';
}
$poloSql .= ' ORDER BY p.name';
$stmt = $pdo->prepare($poloSql);
$stmt->execute($poloParams);
$poloOptions = $stmt->fetchAll();
$validPoloIds = array_map(static fn($p) => (int)$p['id'], $poloOptions);
if ($selectedPolo > 0 && !in_array($selectedPolo, $validPoloIds, true)) $selectedPolo = 0;

$sql = 'SELECT cl.id, cl.source_code, cl.name, cl.course_name, cl.active_apprentices, cl.capacity, cl.status, cl.competence, p.name AS polo_name,
        (SELECT s.name FROM polo_subsede_history h2 INNER JOIN subsedes s ON s.id=h2.subsede_id WHERE h2.polo_id=cl.polo_id AND h2.valid_from <= COALESCE(cl.competence, ?) AND (h2.valid_until IS NULL OR h2.valid_until >= COALESCE(cl.competence, ?)) ORDER BY h2.valid_from DESC LIMIT 1) AS subsede_name
        FROM classes cl LEFT JOIN polos p ON p.id=cl.polo_id
        WHERE (cl.competence IS NULL OR cl.competence BETWEEN ? AND ?)';
$params = [$end, $start, $start, $end];
if ($unitFilter) {
    $ph = implode(',', array_fill(0, count($unitFilter), '?'));
    $sql .= " AND EXISTS (SELECT 1 FROM polo_subsede_history h WHERE h.polo_id=cl.polo_id AND h.subsede_id IN ($ph) AND h.valid_from <= COALESCE(cl.competence, ?) AND (h.valid_until IS NULL OR h.valid_until >= COALESCE(cl.competence, ?)))";
    array_push($params, ...$unitFilter, $end, $start);
} elseif (!$isMaster) {
    $sql .= ' AND 1=0';
}
if ($selectedPolo > 0) {
    $sql .= ' AND cl.polo_id = ?';
    $params[] = $selectedPolo;
}
if ($search !== '') {
    $sql .= ' AND (cl.name LIKE ? OR cl.course_name LIKE ? OR cl.source_code LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
if ($minCount === 'below') $sql .= ' AND cl.active_apprentices < 15';
elseif ($minCount === 'regular') $sql .= ' AND cl.active_apprentices BETWEEN 15 AND 25';
elseif ($minCount === 'optimal') $sql .= ' AND cl.active_apprentices > 25';
$sql .= ' ORDER BY cl.active_apprentices DESC, cl.name ASC LIMIT 1000';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$classes = $stmt->fetchAll();
$counts = ['below'=>0,'regular'=>0,'optimal'=>0];
foreach ($classes as $class) {
    $n = (int)$class['active_apprentices'];
    if ($n < 15) $counts['below']++;
    elseif ($n <= 25) $counts['regular']++;
    else $counts['optimal']++;
}
$moneyCount = static fn($n) => number_format((int)$n, 0, ',', '.');
page_header('Turmas');
?>
<div class="notice">Classificação operacional: abaixo do mínimo = menos de 15 jovens; faixa regular = 15 a 25; turma ótima = mais de 25 jovens. A consulta mostra até 1.000 registros por vez.</div>
<section class="cards">
    <article class="card"><span>Abaixo do mínimo</span><strong><?= $moneyCount($counts['below']) ?></strong><small>Menos de 15 jovens nos resultados filtrados</small></article>
    <article class="card"><span>Faixa regular</span><strong><?= $moneyCount($counts['regular']) ?></strong><small>De 15 a 25 jovens nos resultados filtrados</small></article>
    <article class="card"><span>Turmas ótimas</span><strong><?= $moneyCount($counts['optimal']) ?></strong><small>Mais de 25 jovens nos resultados filtrados</small></article>
</section>
<section class="panel">
    <h2>Consultar turmas</h2>
    <form method="get" class="filters">
        <label>Competência<input type="month" name="competence" value="<?= e($month) ?>"></label>
        <label>Subsede<select name="subsede_id"><option value="0">Todas as autorizadas</option><?php foreach ($unitOptions as $unit): ?><option value="<?= (int)$unit['id'] ?>" <?= $selectedUnit === (int)$unit['id'] ? 'selected' : '' ?>><?= e($unit['name']) ?></option><?php endforeach; ?></select></label>
        <label>Polo<select name="polo_id"><option value="0">Todos os polos autorizados</option><?php foreach ($poloOptions as $polo): ?><option value="<?= (int)$polo['id'] ?>" <?= $selectedPolo === (int)$polo['id'] ? 'selected' : '' ?>><?= e($polo['name']) ?></option><?php endforeach; ?></select></label>
        <label>Ocupação<select name="range"><option value="all" <?= $minCount==='all'?'selected':'' ?>>Todas</option><option value="below" <?= $minCount==='below'?'selected':'' ?>>Abaixo de 15</option><option value="regular" <?= $minCount==='regular'?'selected':'' ?>>15 a 25</option><option value="optimal" <?= $minCount==='optimal'?'selected':'' ?>>Mais de 25 (ótima)</option></select></label>
        <label>Buscar turma/curso/código<input name="q" value="<?= e($search) ?>" maxlength="120" placeholder="Digite para pesquisar"></label>
        <div style="align-self:end"><button type="submit">Filtrar</button></div>
    </form>
    <p><strong><?= count($classes) ?></strong> turmas encontradas<?= count($classes) === 1000 ? ' (limite de exibição atingido)' : '' ?>.</p>
    <div style="overflow-x:auto"><table>
        <thead><tr><th>Turma</th><th>Curso</th><th>Subsede</th><th>Polo</th><th>Competência</th><th>Jovens</th><th>Classificação</th><th>Capacidade</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (!$classes): ?><tr><td colspan="9">Nenhuma turma encontrada com esses filtros.</td></tr><?php endif; ?>
        <?php foreach ($classes as $cl): $n=(int)$cl['active_apprentices']; $quality=$n<15?'Abaixo do mínimo':($n<=25?'Regular':'Ótima'); ?>
        <tr>
            <td><?= e($cl['name']) ?><?= $cl['source_code'] ? '<br><small>' . e($cl['source_code']) . '</small>' : '' ?></td>
            <td><?= e($cl['course_name'] ?? '—') ?></td><td><?= e($cl['subsede_name'] ?? '—') ?></td><td><?= e($cl['polo_name'] ?? '—') ?></td>
            <td><?= $cl['competence'] ? e(date('m/Y', strtotime($cl['competence']))) : 'Não informada' ?></td>
            <td><?= $moneyCount($n) ?></td><td><?= e($quality) ?></td><td><?= $cl['capacity'] !== null ? $moneyCount($cl['capacity']) : '—' ?></td><td><?= e($cl['status'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php page_footer(); ?>

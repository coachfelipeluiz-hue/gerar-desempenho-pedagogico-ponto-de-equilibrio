<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();
if (!can_access_module('dashboard')) {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">Você não tem permissão para acessar a visão geral.</div>';
    page_footer();
    exit;
}

$user = current_user();
if (!$pdo instanceof PDO) {
    http_response_code(500);
    page_header('Visão geral');
    echo '<div class="error">Não foi possível conectar ao banco de dados. Confira a configuração local.</div>';
    page_footer();
    exit;
}

$month = (string)($_GET['competence'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$isMaster = ($user['role'] ?? '') === 'master';
$unitIds = user_unit_ids($pdo);
$selectedUnit = (int)($_GET['subsede_id'] ?? 0);
$selectedPolo = (int)($_GET['polo_id'] ?? 0);

$allowedUnitIds = $isMaster ? [] : array_map('intval', $unitIds);
$unitFilterIds = $allowedUnitIds;
if ($isMaster && $selectedUnit > 0) {
    $unitFilterIds = [$selectedUnit];
} elseif (!$isMaster && $selectedUnit > 0) {
    $unitFilterIds = in_array($selectedUnit, $allowedUnitIds, true) ? [$selectedUnit] : [];
}
$unitOptions = $isMaster
    ? $pdo->query('SELECT id, name FROM subsedes WHERE active = 1 ORDER BY name')->fetchAll()
    : [];
if (!$isMaster && $allowedUnitIds) {
    $placeholders = implode(',', array_fill(0, count($allowedUnitIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name FROM subsedes WHERE active = 1 AND id IN ($placeholders) ORDER BY name");
    $stmt->execute($allowedUnitIds);
    $unitOptions = $stmt->fetchAll();
}
$poloOptions = [];
if ($isMaster || $allowedUnitIds) {
    $sql = 'SELECT DISTINCT p.id, p.name FROM polos p
            INNER JOIN polo_subsede_history h ON h.polo_id = p.id
            WHERE p.active = 1 AND h.valid_from <= ? AND (h.valid_until IS NULL OR h.valid_until >= ?)';
    $params = [$monthEnd, $monthStart];
    if ($unitFilterIds) {
        $ph = implode(',', array_fill(0, count($unitFilterIds), '?'));
        $sql .= " AND h.subsede_id IN ($ph)";
        array_push($params, ...$unitFilterIds);
    } elseif (!$isMaster) {
        $sql .= ' AND 1 = 0';
    }
    $sql .= ' ORDER BY p.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $poloOptions = $stmt->fetchAll();
}
$validPoloIds = array_map(static fn($p) => (int)$p['id'], $poloOptions);
if ($selectedPolo > 0 && !in_array($selectedPolo, $validPoloIds, true)) {
    $selectedPolo = 0;
}
$unitWhereContracts = '';
$contractParams = [$monthEnd, $monthStart];
if ($unitFilterIds) {
    $ph = implode(',', array_fill(0, count($unitFilterIds), '?'));
    $unitWhereContracts .= " AND c.subsede_id IN ($ph)";
    array_push($contractParams, ...$unitFilterIds);
} elseif (!$isMaster) {
    $unitWhereContracts .= ' AND 1 = 0';
}
if ($selectedPolo > 0) {
    $unitWhereContracts .= ' AND c.polo_id = ?';
    $contractParams[] = $selectedPolo;
}
$contractSql = "SELECT COUNT(*) AS contract_count, COALESCE(SUM(c.monthly_value),0) AS revenue
                FROM contracts c
                WHERE (c.start_date IS NULL OR c.start_date <= ?)
                  AND (c.end_date IS NULL OR c.end_date >= ?)
                  AND (c.status IS NULL OR c.status <> 'Desligado')" . $unitWhereContracts;
$stmt = $pdo->prepare($contractSql);
$stmt->execute($contractParams);
$contractMetrics = $stmt->fetch() ?: ['contract_count' => 0, 'revenue' => 0];

$classWhere = ' WHERE cl.competence >= ? AND cl.competence <= ?';
$classParams = [$monthStart, $monthEnd];
if ($unitFilterIds) {
    $ph = implode(',', array_fill(0, count($unitFilterIds), '?'));
    $classWhere .= " AND EXISTS (SELECT 1 FROM polo_subsede_history h WHERE h.polo_id = cl.polo_id AND h.subsede_id IN ($ph) AND h.valid_from <= cl.competence AND (h.valid_until IS NULL OR h.valid_until >= cl.competence))";
    array_push($classParams, ...$unitFilterIds);
} elseif (!$isMaster) {
    $classWhere .= ' AND 1 = 0';
}
if ($selectedPolo > 0) {
    $classWhere .= ' AND cl.polo_id = ?';
    $classParams[] = $selectedPolo;
}
$stmt = $pdo->prepare("SELECT COUNT(*) AS class_count, COALESCE(SUM(cl.active_apprentices),0) AS apprentices FROM classes cl" . $classWhere);
$stmt->execute($classParams);
$classMetrics = $stmt->fetch() ?: ['class_count' => 0, 'apprentices' => 0];
$classesBelow = 0;
$classesRegular = 0;
$classesOptimal = 0;
if ((int)$classMetrics['class_count'] > 0) {
    $stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN COALESCE(cl.active_apprentices, 0) < 15 THEN 1 ELSE 0 END) AS below_minimum,
        SUM(CASE WHEN COALESCE(cl.active_apprentices, 0) BETWEEN 15 AND 25 THEN 1 ELSE 0 END) AS regular_range,
        SUM(CASE WHEN COALESCE(cl.active_apprentices, 0) > 25 THEN 1 ELSE 0 END) AS optimal_range
        FROM classes cl" . $classWhere);
    $stmt->execute($classParams);
    $classQuality = $stmt->fetch() ?: [];
    $classesBelow = (int)($classQuality['below_minimum'] ?? 0);
    $classesRegular = (int)($classQuality['regular_range'] ?? 0);
    $classesOptimal = (int)($classQuality['optimal_range'] ?? 0);
}

$costWhere = ' WHERE ce.competence >= ? AND ce.competence <= ?';
$costParams = [$monthStart, $monthEnd];
if ($unitFilterIds) {
    $ph = implode(',', array_fill(0, count($unitFilterIds), '?'));
    $costWhere .= " AND (ce.subsede_id IN ($ph) OR (ce.subsede_id IS NULL AND EXISTS (SELECT 1 FROM polo_subsede_history h WHERE h.polo_id = ce.polo_id AND h.subsede_id IN ($ph) AND h.valid_from <= ce.competence AND (h.valid_until IS NULL OR h.valid_until >= ce.competence))) OR (ce.subsede_id IS NULL AND ce.polo_id IS NULL AND EXISTS (SELECT 1 FROM classes cl INNER JOIN polo_subsede_history h ON h.polo_id = cl.polo_id WHERE cl.id = ce.class_id AND h.subsede_id IN ($ph) AND h.valid_from <= ce.competence AND (h.valid_until IS NULL OR h.valid_until >= ce.competence))) )";
    array_push($costParams, ...$unitFilterIds, ...$unitFilterIds, ...$unitFilterIds);
} elseif (!$isMaster) {
    $costWhere .= ' AND 1 = 0';
}
if ($selectedPolo > 0) {
    $costWhere .= ' AND (ce.polo_id = ? OR ce.class_id IN (SELECT id FROM classes WHERE polo_id = ?))';
    array_push($costParams, $selectedPolo, $selectedPolo);
}
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM cost_entries ce' . $costWhere);
$stmt->execute($costParams);
$directCosts = (float)$stmt->fetchColumn();

$hoursSql = 'SELECT COALESCE(SUM(ih.hours * ih.cost_per_hour),0)
             FROM instruction_hours ih
             INNER JOIN classes cl ON cl.id = ih.class_id
             WHERE ih.approved = 1 AND ih.competence >= ? AND ih.competence <= ?';
$hoursParams = [$monthStart, $monthEnd];
if ($unitFilterIds) {
    $ph = implode(',', array_fill(0, count($unitFilterIds), '?'));
    $hoursSql .= " AND EXISTS (SELECT 1 FROM polo_subsede_history h WHERE h.polo_id = cl.polo_id AND h.subsede_id IN ($ph) AND h.valid_from <= ih.competence AND (h.valid_until IS NULL OR h.valid_until >= ih.competence))";
    array_push($hoursParams, ...$unitFilterIds);
} elseif (!$isMaster) {
    $hoursSql .= ' AND 1 = 0';
}
if ($selectedPolo > 0) {
    $hoursSql .= ' AND cl.polo_id = ?';
    $hoursParams[] = $selectedPolo;
}
$stmt = $pdo->prepare($hoursSql);
$stmt->execute($hoursParams);
$approvedInstructionCosts = (float)$stmt->fetchColumn();
$knownCosts = $directCosts + $approvedInstructionCosts;
$revenue = (float)$contractMetrics['revenue'];
$result = $revenue - $knownCosts;
$average = (int)$classMetrics['class_count'] > 0 ? (int)$classMetrics['apprentices'] / (int)$classMetrics['class_count'] : 0;

$money = static fn($value) => 'R$ ' . number_format((float)$value, 2, ',', '.');
page_header('Visão geral');
?>
<div class="notice">
    <strong>Indicadores preliminares.</strong>
    Os valores são calculados a partir dos registros disponíveis no banco para <?= e(date('m/Y', strtotime($monthStart))) ?>. Custos incompletos ou dados ainda não importados podem distorcer o resultado; este painel não substitui a validação contábil.
</div>

<form method="get" class="panel">
    <div class="filters">
        <label>Competência
            <input type="month" name="competence" value="<?= e($month) ?>">
        </label>
        <label>Subsede
            <select name="subsede_id">
                <option value="0">Todas as unidades autorizadas</option>
                <?php foreach ($unitOptions as $unit): ?>
                    <option value="<?= (int)$unit['id'] ?>" <?= $selectedUnit === (int)$unit['id'] ? 'selected' : '' ?>><?= e($unit['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Polo
            <select name="polo_id">
                <option value="0">Todos os polos autorizados</option>
                <?php foreach ($poloOptions as $polo): ?>
                    <option value="<?= (int)$polo['id'] ?>" <?= $selectedPolo === (int)$polo['id'] ? 'selected' : '' ?>><?= e($polo['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div style="align-self:end"><button type="submit">Aplicar filtros</button></div>
    </div>
</form>

<section class="cards">
    <article class="card"><span>Aprendizes registrados</span><strong><?= number_format((int)$classMetrics['apprentices'], 0, ',', '.') ?></strong><small>Somatório das turmas na competência selecionada</small></article>
    <article class="card"><span>Turmas abaixo do mínimo (&lt; 15)</span><strong><?= number_format($classesBelow, 0, ',', '.') ?></strong><small>Atenção: abaixo do mínimo operacional</small></article>
    <article class="card"><span>Turmas na faixa regular (15 a 25)</span><strong><?= number_format($classesRegular, 0, ',', '.') ?></strong><small>Dentro da faixa mínima, mas sem ultrapassar 25 jovens</small></article>
    <article class="card"><span>Turmas ótimas (&gt; 25)</span><strong><?= number_format($classesOptimal, 0, ',', '.') ?></strong><small>Mais de 25 jovens</small></article>
    <article class="card"><span>Média por turma</span><strong><?= number_format($average, 1, ',', '.') ?></strong><small>Aprendizes ÷ turmas com competência</small></article>
    <article class="card"><span>Receita mensal contratual</span><strong><?= e($money($revenue)) ?></strong><small><?= (int)$contractMetrics['contract_count'] ?> contratos vigentes na competência</small></article>
    <article class="card"><span>Custos conhecidos</span><strong><?= e($money($knownCosts)) ?></strong><small>Custos cadastrados + horas de instrução aprovadas</small></article>
    <article class="card"><span>Saldo preliminar</span><strong><?= e($money($result)) ?></strong><small>Receita contratual menos custos conhecidos; não é lucro contábil</small></article>
</section>

<section class="panel">
    <h2>Como interpretar este painel</h2>
    <ul>
        <li>Os indicadores de turma dependem de registros na tabela de turmas com a competência preenchida.</li>
        <li>A receita soma contratos com datas vigentes no período e status diferente de “Desligado”.</li>
        <li>Custos de instrutores só entram quando as horas estiverem marcadas como aprovadas.</li>
        <li>Valores ausentes não são estimados. O saldo só ficará útil após validar importações, custos e regras de atribuição.</li>
    </ul>
</section>
<?php page_footer(); ?>

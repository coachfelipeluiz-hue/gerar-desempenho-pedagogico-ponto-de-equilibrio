<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();

$user = current_user();
if (!is_master_user()) {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">O módulo de custos ainda não tem regras de leitura e gravação por unidade para perfis não Master. Até essa definição, o acesso fica restrito ao Master.</div>';
    page_footer();
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    page_header('Custos');
    echo '<div class="error">Não foi possível conectar ao banco de dados. Confira a configuração local.</div>';
    page_footer();
    exit;
}
if (empty($_SESSION['csrf_costs'])) {
    $_SESSION['csrf_costs'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_costs'];
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_category') {
            $name = trim((string)($_POST['category_name'] ?? ''));
            $type = (string)($_POST['cost_type'] ?? '');
            $level = (string)($_POST['attribution_level'] ?? '');
            if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Informe um nome de categoria com até 160 caracteres.');
            if (!in_array($type, ['fixed','variable','direct','shared'], true)) throw new RuntimeException('Tipo de custo inválido.');
            if (!in_array($level, ['class','polo','subsede','institution'], true)) throw new RuntimeException('Nível de atribuição inválido.');
            $stmt = $pdo->prepare('INSERT INTO cost_categories (name, cost_type, attribution_level, active) VALUES (?, ?, ?, 1)');
            $stmt->execute([$name, $type, $level]);
            audit($pdo, (int)$user['id'], 'create', 'cost_category', (int)$pdo->lastInsertId(), ['name'=>$name,'cost_type'=>$type,'attribution_level'=>$level]);
            $messages[] = 'Categoria cadastrada.';
        } elseif ($action === 'save_cost') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $amountRaw = trim((string)($_POST['amount'] ?? ''));
            $amountRaw = str_replace(['R$', ' ', chr(194).chr(160)], '', $amountRaw);
            if (str_contains($amountRaw, ',') && str_contains($amountRaw, '.')) $amountRaw = str_replace('.', '', $amountRaw);
            $amountRaw = str_replace(',', '.', $amountRaw);
            $amount = is_numeric($amountRaw) ? (float)$amountRaw : -1;
            $competence = (string)($_POST['competence'] ?? date('Y-m'));
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competence)) throw new RuntimeException('Selecione uma competência válida.');
            if ($categoryId < 1 || $amount < 0) throw new RuntimeException('Selecione a categoria e informe um valor válido, igual ou maior que zero.');
            $check = $pdo->prepare('SELECT id, attribution_level FROM cost_categories WHERE id = ? AND active = 1');
            $check->execute([$categoryId]);
            $category = $check->fetch();
            if (!$category) throw new RuntimeException('A categoria selecionada não está ativa.');
            $subsedeId = (int)($_POST['subsede_id'] ?? 0) ?: null;
            $poloId = (int)($_POST['polo_id'] ?? 0) ?: null;
            $isMaster = ($user['role'] ?? '') === 'master';
            $allowedUnits = array_map('intval', user_unit_ids($pdo));
            if (!$isMaster && (!$subsedeId || !in_array($subsedeId, $allowedUnits, true))) {
                throw new RuntimeException('Selecione uma subsede à qual seu usuário tenha acesso. Apenas o Master pode registrar custos institucionais sem subsede.');
            }
            $classId = (int)($_POST['class_id'] ?? 0) ?: null;
            $notes = trim((string)($_POST['notes'] ?? ''));
            if (mb_strlen($notes) > 500) throw new RuntimeException('As observações devem ter até 500 caracteres.');
            $level = $category['attribution_level'];
            if ($level === 'subsede' && !$subsedeId) throw new RuntimeException('Esta categoria exige uma subsede.');
            if ($level === 'polo' && !$poloId) throw new RuntimeException('Esta categoria exige um polo.');
            if ($level === 'class' && !$classId) throw new RuntimeException('Esta categoria exige uma turma.');
            if ($classId) {
                $check = $pdo->prepare('SELECT id, polo_id FROM classes WHERE id = ?');
                $check->execute([$classId]);
                $class = $check->fetch();
                if (!$class) throw new RuntimeException('Turma não encontrada.');
                if (!$poloId && $class['polo_id']) $poloId = (int)$class['polo_id'];
            }
            if ($poloId && !$subsedeId) {
                $stmt = $pdo->prepare('SELECT subsede_id FROM polo_subsede_history WHERE polo_id = ? AND valid_from <= LAST_DAY(CONCAT(?, "-01")) AND (valid_until IS NULL OR valid_until >= CONCAT(?, "-01")) ORDER BY valid_from DESC LIMIT 1');
                $stmt->execute([$poloId, $competence, $competence]);
                $foundSubsede = $stmt->fetchColumn();
                if ($foundSubsede) $subsedeId = (int)$foundSubsede;
            }
            $stmt = $pdo->prepare('INSERT INTO cost_entries (category_id, amount, competence, class_id, polo_id, subsede_id, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$categoryId, $amount, $competence . '-01', $classId, $poloId, $subsedeId, $notes !== '' ? $notes : null, (int)$user['id']]);
            $id = (int)$pdo->lastInsertId();
            audit($pdo, (int)$user['id'], 'create', 'cost_entry', $id, ['category_id'=>$categoryId,'amount'=>$amount,'competence'=>$competence,'subsede_id'=>$subsedeId,'polo_id'=>$poloId,'class_id'=>$classId]);
            $messages[] = 'Custo registrado.';
        } else {
            throw new RuntimeException('Ação não reconhecida.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($ex instanceof PDOException && (string)$ex->getCode() === '23000') {
            $errors[] = 'Já existe uma categoria com esse nome ou os dados selecionados não são válidos.';
        } else {
            $errors[] = $ex->getMessage();
        }
    }
}

$categories = $pdo->query('SELECT id, name, cost_type, attribution_level FROM cost_categories WHERE active = 1 ORDER BY name')->fetchAll();
$subsedes = $pdo->query('SELECT id, name FROM subsedes WHERE active = 1 ORDER BY name')->fetchAll();
$polos = $pdo->query('SELECT id, name FROM polos WHERE active = 1 ORDER BY name')->fetchAll();
$classes = $pdo->query('SELECT id, name, course_name FROM classes ORDER BY name')->fetchAll();
$month = (string)($_GET['competence'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m');
$start = $month . '-01';
$end = date('Y-m-t', strtotime($start));
$sql = 'SELECT ce.id, ce.amount, ce.competence, ce.notes, cc.name AS category_name, cc.cost_type, cc.attribution_level, s.name AS subsede_name, p.name AS polo_name, cl.name AS class_name
 FROM cost_entries ce
 INNER JOIN cost_categories cc ON cc.id = ce.category_id
 LEFT JOIN subsedes s ON s.id = ce.subsede_id
 LEFT JOIN polos p ON p.id = ce.polo_id
 LEFT JOIN classes cl ON cl.id = ce.class_id
 WHERE ce.competence BETWEEN ? AND ?';
$params = [$start, $end];
if (($user['role'] ?? '') !== 'master') {
    $allowedUnits = array_map('intval', user_unit_ids($pdo));
    if ($allowedUnits) {
        $ph = implode(',', array_fill(0, count($allowedUnits), '?'));
        $sql .= " AND ce.subsede_id IN ($ph)";
        array_push($params, ...$allowedUnits);
    } else {
        $sql .= ' AND 1 = 0';
    }
}
$sql .= ' ORDER BY ce.competence DESC, ce.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();
$total = array_sum(array_map(static fn($r) => (float)$r['amount'], $entries));
$money = static fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
page_header('Custos');
?>
<?php foreach ($messages as $message): ?><div class="notice"><?= e($message) ?></div><?php endforeach; ?>
<?php foreach ($errors as $error): ?><div class="error"><?= e($error) ?></div><?php endforeach; ?>

<section class="panel">
    <h2>Registrar custo</h2>
    <?php if (!$categories): ?><p>Antes de registrar um custo, cadastre uma categoria na seção abaixo.</p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_cost">
        <div class="filters">
            <label>Categoria
                <select name="category_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> — <?= e($c['attribution_level']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Valor (R$)<input name="amount" inputmode="decimal" placeholder="0,00" required></label>
            <label>Competência<input type="month" name="competence" value="<?= e(date('Y-m')) ?>" required></label>
            <label>Subsede (quando aplicável)
                <select name="subsede_id"><option value="0">Não se aplica</option><?php foreach ($subsedes as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Polo (quando aplicável)
                <select name="polo_id"><option value="0">Não se aplica</option><?php foreach ($polos as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Turma (quando aplicável)
                <select name="class_id"><option value="0">Não se aplica</option><?php foreach ($classes as $cl): ?><option value="<?= (int)$cl['id'] ?>"><?= e($cl['name']) ?><?= $cl['course_name'] ? ' — ' . e($cl['course_name']) : '' ?></option><?php endforeach; ?></select>
            </label>
            <label style="grid-column:1/-1">Observações<input name="notes" maxlength="500" placeholder="Ex.: aluguel, material, manutenção..."></label>
            <div><button type="submit">Salvar custo</button></div>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Cadastrar categoria de custo</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_category">
        <div class="filters">
            <label>Nome da categoria<input name="category_name" maxlength="160" required placeholder="Ex.: aluguel, material didático"></label>
            <label>Tipo
                <select name="cost_type" required>
                    <option value="fixed">Fixo</option><option value="variable">Variável</option><option value="direct">Direto</option><option value="shared">Compartilhado</option>
                </select>
            </label>
            <label>Nível de atribuição
                <select name="attribution_level" required>
                    <option value="class">Turma</option><option value="polo">Polo</option><option value="subsede">Subsede</option><option value="institution">Instituição</option>
                </select>
            </label>
            <div><button type="submit">Cadastrar categoria</button></div>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Custos da competência</h2>
    <form method="get" class="filters">
        <label>Competência<input type="month" name="competence" value="<?= e($month) ?>"></label>
        <div style="align-self:end"><button type="submit">Consultar</button></div>
    </form>
    <p><strong>Total registrado: <?= e($money($total)) ?></strong> · <?= count($entries) ?> lançamentos</p>
    <div style="overflow-x:auto">
    <table>
        <thead><tr><th>Competência</th><th>Categoria</th><th>Tipo</th><th>Atribuição</th><th>Subsede</th><th>Polo</th><th>Turma</th><th>Valor</th><th>Observações</th></tr></thead>
        <tbody>
        <?php if (!$entries): ?><tr><td colspan="9">Nenhum custo registrado nesta competência.</td></tr><?php endif; ?>
        <?php foreach ($entries as $entry): ?><tr>
            <td><?= e(date('m/Y', strtotime($entry['competence']))) ?></td>
            <td><?= e($entry['category_name']) ?></td><td><?= e($entry['cost_type']) ?></td><td><?= e($entry['attribution_level']) ?></td>
            <td><?= e($entry['subsede_name'] ?? '—') ?></td><td><?= e($entry['polo_name'] ?? '—') ?></td><td><?= e($entry['class_name'] ?? '—') ?></td>
            <td><?= e($money($entry['amount'])) ?></td><td><?= e($entry['notes'] ?? '') ?></td>
        </tr><?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php page_footer(); ?>

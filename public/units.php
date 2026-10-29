<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();

$user = current_user();
if (!is_master_user()) {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">Somente o Master pode consultar ou alterar o cadastro global de subsedes, polos e vínculos históricos.</div>';
    page_footer();
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    page_header('Subsedes e polos');
    echo '<div class="error">Não foi possível conectar ao banco de dados. Confira a configuração local.</div>';
    page_footer();
    exit;
}

if (empty($_SESSION['csrf_units'])) {
    $_SESSION['csrf_units'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_units'];
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrf, $postedToken)) {
        $errors[] = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create_subsede') {
                $name = trim((string)($_POST['subsede_name'] ?? ''));
                $sourceCode = trim((string)($_POST['subsede_code'] ?? ''));
                if ($name === '') {
                    throw new RuntimeException('Informe o nome da subsede.');
                }
                $stmt = $pdo->prepare('INSERT INTO subsedes (name, source_code, active) VALUES (?, ?, 1)');
                $stmt->execute([$name, $sourceCode !== '' ? $sourceCode : null]);
                audit($pdo, (int)$user['id'], 'create', 'subsede', (int)$pdo->lastInsertId(), ['name' => $name]);
                $messages[] = 'Subsede cadastrada com sucesso.';
            } elseif ($action === 'create_polo') {
                $name = trim((string)($_POST['polo_name'] ?? ''));
                $sourceCode = trim((string)($_POST['polo_code'] ?? ''));
                $subsedeId = (int)($_POST['subsede_id'] ?? 0);
                $validFrom = (string)($_POST['valid_from'] ?? date('Y-m-d'));
                if ($name === '' || $subsedeId < 1) {
                    throw new RuntimeException('Informe o nome do polo e a subsede responsável.');
                }
                $check = $pdo->prepare('SELECT id FROM subsedes WHERE id = ? AND active = 1');
                $check->execute([$subsedeId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Selecione uma subsede ativa.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO polos (name, source_code, active) VALUES (?, ?, 1)');
                $stmt->execute([$name, $sourceCode !== '' ? $sourceCode : null]);
                $poloId = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare('INSERT INTO polo_subsede_history (polo_id, subsede_id, valid_from, created_by) VALUES (?, ?, ?, ?)');
                $stmt->execute([$poloId, $subsedeId, $validFrom, (int)$user['id']]);
                audit($pdo, (int)$user['id'], 'create', 'polo', $poloId, ['name' => $name, 'subsede_id' => $subsedeId, 'valid_from' => $validFrom]);
                $pdo->commit();
                $messages[] = 'Polo cadastrado e vínculo com a subsede registrado.';
            } elseif ($action === 'change_assignment') {
                $poloId = (int)($_POST['polo_id'] ?? 0);
                $subsedeId = (int)($_POST['subsede_id'] ?? 0);
                $validFrom = (string)($_POST['valid_from'] ?? date('Y-m-d'));
                $check = $pdo->prepare('SELECT id FROM polos WHERE id = ? AND active = 1');
                $check->execute([$poloId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Polo não encontrado ou inativo.');
                }
                $check = $pdo->prepare('SELECT id FROM subsedes WHERE id = ? AND active = 1');
                $check->execute([$subsedeId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Selecione uma subsede ativa.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id, valid_from FROM polo_subsede_history WHERE polo_id = ? AND valid_until IS NULL ORDER BY valid_from DESC, id DESC LIMIT 1 FOR UPDATE');
                $stmt->execute([$poloId]);
                $current = $stmt->fetch();
                if ($current && $validFrom <= $current['valid_from']) {
                    throw new RuntimeException('A data de início do novo vínculo deve ser posterior à data de início do vínculo atual.');
                }
                if ($current) {
                    $close = $pdo->prepare('UPDATE polo_subsede_history SET valid_until = DATE_SUB(?, INTERVAL 1 DAY) WHERE id = ?');
                    $close->execute([$validFrom, (int)$current['id']]);
                }
                $stmt = $pdo->prepare('INSERT INTO polo_subsede_history (polo_id, subsede_id, valid_from, created_by) VALUES (?, ?, ?, ?)');
                $stmt->execute([$poloId, $subsedeId, $validFrom, (int)$user['id']]);
                audit($pdo, (int)$user['id'], 'change_assignment', 'polo', $poloId, ['subsede_id' => $subsedeId, 'valid_from' => $validFrom]);
                $pdo->commit();
                $messages[] = 'Vínculo atualizado. O histórico anterior foi preservado.';
            } elseif ($action === 'toggle_subsede' || $action === 'toggle_polo') {
                $id = (int)($_POST['id'] ?? 0);
                $table = $action === 'toggle_subsede' ? 'subsedes' : 'polos';
                $stmt = $pdo->prepare("UPDATE {$table} SET active = IF(active = 1, 0, 1) WHERE id = ?");
                $stmt->execute([$id]);
                audit($pdo, (int)$user['id'], 'toggle_active', $table === 'subsedes' ? 'subsede' : 'polo', $id);
                $messages[] = 'Status atualizado.';
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex instanceof PDOException && (string)$ex->getCode() === '23000'
                ? 'Já existe um cadastro com esse código ou nome. Confira os dados e tente novamente.'
                : $ex->getMessage();
        }
    }
}

$subsedes = $pdo->query('SELECT id, name, source_code, active FROM subsedes ORDER BY name')->fetchAll();
$assignments = $pdo->query(
    'SELECT p.id, p.name, p.source_code, p.active, s.name AS subsede_name, h.subsede_id, h.valid_from
     FROM polos p
     LEFT JOIN polo_subsede_history h ON h.polo_id = p.id AND h.valid_until IS NULL
     LEFT JOIN subsedes s ON s.id = h.subsede_id
     ORDER BY p.name'
)->fetchAll();
$history = $pdo->query(
    'SELECT p.name AS polo_name, s.name AS subsede_name, h.valid_from, h.valid_until, u.name AS created_by_name
     FROM polo_subsede_history h
     INNER JOIN polos p ON p.id = h.polo_id
     INNER JOIN subsedes s ON s.id = h.subsede_id
     LEFT JOIN users u ON u.id = h.created_by
     ORDER BY h.valid_from DESC, h.id DESC LIMIT 100'
)->fetchAll();

page_header('Subsedes e polos');
?>
<?php foreach ($messages as $message): ?>
    <div class="notice"><?= e($message) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $error): ?>
    <div class="error" style="margin:12px 0"><?= e($error) ?></div>
<?php endforeach; ?>

<div class="panel">
    <h2>Cadastrar subsede</h2>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create_subsede">
        <label>Nome da subsede
            <input name="subsede_name" maxlength="160" required>
        </label>
        <label>Código de origem (opcional)
            <input name="subsede_code" maxlength="100">
        </label>
        <button type="submit">Cadastrar subsede</button>
    </form>
</div>

<div class="panel">
    <h2>Cadastrar polo</h2>
    <form method="post" class="form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="create_polo">
        <label>Nome do polo
            <input name="polo_name" maxlength="160" required>
        </label>
        <label>Código de origem (opcional)
            <input name="polo_code" maxlength="100">
        </label>
        <label>Subsede responsável
            <select name="subsede_id" required>
                <option value="">Selecione...</option>
                <?php foreach ($subsedes as $subsede): if ((int)$subsede['active'] === 1): ?>
                    <option value="<?= (int)$subsede['id'] ?>"><?= e($subsede['name']) ?></option>
                <?php endif; endforeach; ?>
            </select>
        </label>
        <label>Início da responsabilidade
            <input type="date" name="valid_from" value="<?= e(date('Y-m-d')) ?>" required>
        </label>
        <button type="submit">Cadastrar polo</button>
    </form>
</div>

<div class="panel">
    <h2>Subsedes cadastradas</h2>
    <?php if (!$subsedes): ?><p class="muted">Nenhuma subsede cadastrada ainda.</p><?php endif; ?>
    <?php foreach ($subsedes as $subsede): ?>
        <div class="import-row">
            <div><strong><?= e($subsede['name']) ?></strong>
                <?php if ($subsede['source_code']): ?><div class="muted">Código: <?= e($subsede['source_code']) ?></div><?php endif; ?>
                <span class="pill"><?= (int)$subsede['active'] === 1 ? 'Ativa' : 'Inativa' ?></span>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="toggle_subsede">
                <input type="hidden" name="id" value="<?= (int)$subsede['id'] ?>">
                <button type="submit"><?= (int)$subsede['active'] === 1 ? 'Desativar' : 'Reativar' ?></button>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div class="panel">
    <h2>Polos e responsabilidade atual</h2>
    <?php if (!$assignments): ?><p class="muted">Nenhum polo cadastrado ainda.</p><?php endif; ?>
    <?php foreach ($assignments as $polo): ?>
        <div class="import-row">
            <div>
                <strong><?= e($polo['name']) ?></strong>
                <div class="muted">Subsede: <?= e($polo['subsede_name'] ?? 'Sem vínculo atual') ?></div>
                <span class="pill"><?= (int)$polo['active'] === 1 ? 'Ativo' : 'Inativo' ?></span>
            </div>
            <div>
                <?php if ((int)$polo['active'] === 1): ?>
                    <form method="post" class="filters" style="align-items:end">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="change_assignment">
                        <input type="hidden" name="polo_id" value="<?= (int)$polo['id'] ?>">
                        <label>Nova subsede
                            <select name="subsede_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($subsedes as $subsede): if ((int)$subsede['active'] === 1): ?>
                                    <option value="<?= (int)$subsede['id'] ?>"><?= e($subsede['name']) ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </label>
                        <label>Válido a partir de
                            <input type="date" name="valid_from" value="<?= e(date('Y-m-d')) ?>" required>
                        </label>
                        <button type="submit">Alterar vínculo</button>
                    </form>
                <?php endif; ?>
                <form method="post" style="margin-top:8px">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="toggle_polo">
                    <input type="hidden" name="id" value="<?= (int)$polo['id'] ?>">
                    <button type="submit"><?= (int)$polo['active'] === 1 ? 'Desativar polo' : 'Reativar polo' ?></button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="panel">
    <h2>Histórico de responsabilidade</h2>
    <p class="muted">Últimos 100 vínculos. Alterações de subsede mantêm os registros anteriores.</p>
    <?php if (!$history): ?><p class="muted">Ainda não há histórico de vínculos.</p><?php endif; ?>
    <?php foreach ($history as $item): ?>
        <div class="import-row">
            <div><strong><?= e($item['polo_name']) ?></strong><div><?= e($item['subsede_name']) ?></div></div>
            <div class="muted"><?= e($item['valid_from']) ?> até <?= e($item['valid_until'] ?? 'atual') ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php page_footer(); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_login();

$user = current_user();
if (($user['role'] ?? '') !== 'master') {
    http_response_code(403);
    page_header('Acesso negado');
    echo '<div class="error">Somente o Master pode gerenciar usuários e permissões.</div>';
    page_footer();
    exit;
}
if (!$pdo instanceof PDO) {
    http_response_code(500);
    page_header('Usuários e permissões');
    echo '<div class="error">Não foi possível conectar ao banco de dados. Confira a configuração local.</div>';
    page_footer();
    exit;
}

$modulesAvailable = [
    'dashboard' => 'Visão geral',
    'imports' => 'Importações',
    'units' => 'Subsedes e polos',
    'users' => 'Usuários',
    'classes' => 'Turmas e grupos',
    'instructors' => 'Instrutores',
    'costs' => 'Custos',
    'reports' => 'Relatórios e ponto de equilíbrio',
];
if (empty($_SESSION['csrf_users'])) {
    $_SESSION['csrf_users'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_users'];
$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('A sessão do formulário expirou. Atualize a página e tente novamente.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_user') {
            $id = parse_nonnegative_id($_POST['user_id'] ?? '0');
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $role = (string)($_POST['role'] ?? 'consulta');
            $password = (string)($_POST['password'] ?? '');
            $active = isset($_POST['active']) ? 1 : 0;
            $validRoles = ['master', 'gestor', 'consulta'];

            if ($name === '' || mb_strlen($name) > 160) {
                throw new RuntimeException('Informe um nome válido (até 160 caracteres).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                throw new RuntimeException('Informe um e-mail válido.');
            }
            if (!in_array($role, $validRoles, true)) {
                throw new RuntimeException('Selecione um perfil válido.');
            }
            if ($id === 0 && strlen($password) < 10) {
                throw new RuntimeException('A senha inicial deve ter pelo menos 10 caracteres.');
            }
            if ($password !== '' && strlen($password) < 10) {
                throw new RuntimeException('A nova senha deve ter pelo menos 10 caracteres.');
            }
            if ($id === (int)$user['id'] && ($role !== 'master' || $active !== 1)) {
                throw new RuntimeException('Por segurança, não é possível remover ou desativar o próprio acesso Master.');
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $check = $pdo->prepare('SELECT id FROM users WHERE id = ?');
                $check->execute([$id]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Usuário não encontrado.');
                }
                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, active = ? WHERE id = ?');
                $stmt->execute([$name, $email, $role, $active, $id]);
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                $targetId = $id;
                $auditAction = 'update';
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active]);
                $targetId = (int)$pdo->lastInsertId();
                $auditAction = 'create';
            }

            $stmt = $pdo->prepare('DELETE FROM user_module_permissions WHERE user_id = ?');
            $stmt->execute([$targetId]);
            $modulePermissions = $_POST['modules'] ?? [];
            if (!is_array($modulePermissions)) {
                $modulePermissions = [];
            }
            $insertModule = $pdo->prepare(
                'INSERT INTO user_module_permissions (user_id, module_key, can_view, can_create, can_edit, can_import, can_export)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($modulesAvailable as $key => $label) {
                $permissions = $modulePermissions[$key] ?? [];
                if (!is_array($permissions)) {
                    $permissions = [];
                }
                $view = isset($permissions['view']) ? 1 : 0;
                $create = isset($permissions['create']) ? 1 : 0;
                $edit = isset($permissions['edit']) ? 1 : 0;
                $import = isset($permissions['import']) ? 1 : 0;
                $export = isset($permissions['export']) ? 1 : 0;
                if ($role === 'consulta') {
                    $create = $edit = $import = $export = 0;
                }
                if (!$view) {
                    $create = $edit = $import = $export = 0;
                }
                if ($role !== 'master' && in_array($key, ['imports', 'units', 'users', 'costs'], true)) {
                    $view = $create = $edit = $import = $export = 0;
                }
                if ($view || $create || $edit || $import || $export) {
                    $insertModule->execute([$targetId, $key, $view, $create, $edit, $import, $export]);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM user_unit_access WHERE user_id = ?');
            $stmt->execute([$targetId]);
            $unitIds = $_POST['units'] ?? [];
            if (!is_array($unitIds)) {
                $unitIds = [];
            }
            $insertUnit = $pdo->prepare('INSERT INTO user_unit_access (user_id, subsede_id, valid_from, valid_until) VALUES (?, ?, CURDATE(), NULL)');
            $validUnitStmt = $pdo->prepare('SELECT id FROM subsedes WHERE id = ? AND active = 1');
            $uniqueUnitIds = array_values(array_unique(array_map('intval', $unitIds)));
            foreach ($uniqueUnitIds as $unitId) {
                if ($unitId < 1) {
                    continue;
                }
                $validUnitStmt->execute([$unitId]);
                if ($validUnitStmt->fetch()) {
                    $insertUnit->execute([$targetId, $unitId]);
                }
            }

            audit($pdo, (int)$user['id'], $auditAction, 'user', $targetId, [
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'active' => $active,
                'modules' => array_keys(array_filter($modulePermissions)),
                'unit_ids' => $uniqueUnitIds,
            ]);
            $pdo->commit();
            $messages[] = $id > 0 ? 'Usuário e permissões atualizados.' : 'Usuário criado com sucesso.';
        } elseif ($action === 'toggle_user') {
            $id = (int)($_POST['user_id'] ?? 0);
            if ($id === (int)$user['id']) {
                throw new RuntimeException('Você não pode desativar o próprio usuário.');
            }
            $stmt = $pdo->prepare('UPDATE users SET active = IF(active = 1, 0, 1) WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                audit($pdo, (int)$user['id'], 'toggle_active', 'user', $id);
                $messages[] = 'Status do usuário atualizado.';
            } else {
                $errors[] = 'Usuário não encontrado ou sem alteração de status.';
            }
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ex instanceof PDOException && (string)$ex->getCode() === '23000') {
            $errors[] = 'Esse e-mail já está cadastrado. Use um e-mail diferente.';
        } else {
            $errors[] = $ex->getMessage();
        }
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
$editModules = [];
$editUnits = [];
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT id, name, email, role, active FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
    if ($editing) {
        $stmt = $pdo->prepare('SELECT module_key, can_view, can_create, can_edit, can_import, can_export FROM user_module_permissions WHERE user_id = ?');
        $stmt->execute([$editId]);
        foreach ($stmt->fetchAll() as $permission) {
            $editModules[$permission['module_key']] = $permission;
        }
        $stmt = $pdo->prepare('SELECT subsede_id FROM user_unit_access WHERE user_id = ? AND (valid_until IS NULL OR valid_until >= CURDATE())');
        $stmt->execute([$editId]);
        $editUnits = array_map('intval', array_column($stmt->fetchAll(), 'subsede_id'));
    }
}
$subsedes = $pdo->query('SELECT id, name FROM subsedes WHERE active = 1 ORDER BY name')->fetchAll();
$users = $pdo->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY name')->fetchAll();

page_header('Usuários e permissões');
?>
<?php foreach ($messages as $message): ?><div class="notice"><?= e($message) ?></div><?php endforeach; ?>
<?php foreach ($errors as $error): ?><div class="error" style="margin:12px 0"><?= e($error) ?></div><?php endforeach; ?>

<div class="panel">
    <h2><?= $editing ? 'Editar usuário' : 'Cadastrar usuário' ?></h2>
    <?php if (!$subsedes): ?><p class="muted">Cadastre as subsedes na página “Subsedes e polos” antes de atribuir acessos por unidade.</p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="user_id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="filters">
            <label>Nome completo
                <input name="name" maxlength="160" required value="<?= e($editing['name'] ?? '') ?>">
            </label>
            <label>E-mail
                <input type="email" name="email" maxlength="190" required value="<?= e($editing['email'] ?? '') ?>">
            </label>
            <label>Perfil
                <select name="role" required>
                    <?php foreach (['master' => 'Master', 'gestor' => 'Gestor', 'consulta' => 'Consulta'] as $roleValue => $roleLabel): ?>
                        <option value="<?= e($roleValue) ?>" <?= (($editing['role'] ?? 'consulta') === $roleValue) ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Senha <?= $editing ? '(deixe em branco para manter)' : '(mínimo de 10 caracteres)' ?>
                <input type="password" name="password" minlength="10" <?= $editing ? '' : 'required' ?> autocomplete="new-password">
            </label>
        </div>
        <p><label><input type="checkbox" name="active" value="1" style="min-width:0;width:auto" <?= (!$editing || (int)$editing['active'] === 1) ? 'checked' : '' ?>> Usuário ativo</label></p>

        <h3>Unidades autorizadas</h3>
        <p class="muted">O acesso por unidade será usado pelos módulos que aplicarem esse filtro. Master tem acesso global.</p>
        <?php if (!$subsedes): ?><p class="muted">Nenhuma subsede ativa cadastrada.</p><?php endif; ?>
        <div class="filters">
            <?php foreach ($subsedes as $subsede): ?>
                <label style="flex-direction:row;align-items:center;font-weight:400">
                    <input type="checkbox" name="units[]" value="<?= (int)$subsede['id'] ?>" style="min-width:0;width:auto" <?= in_array((int)$subsede['id'], $editUnits, true) ? 'checked' : '' ?>>
                    <?= e($subsede['name']) ?>
                </label>
            <?php endforeach; ?>
        </div>

        <h3>Permissões por módulo</h3>
        <p class="muted">“Visualizar” é obrigatório para qualquer outra ação. “Consulta” não recebe permissões de alteração, importação ou exportação. Importações, unidades, usuários e custos permanecem exclusivos do Master nesta etapa.</p>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <thead><tr>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid #ddd">Módulo</th>
                    <th style="padding:8px;border-bottom:1px solid #ddd">Visualizar</th>
                    <th style="padding:8px;border-bottom:1px solid #ddd">Criar</th>
                    <th style="padding:8px;border-bottom:1px solid #ddd">Editar</th>
                    <th style="padding:8px;border-bottom:1px solid #ddd">Importar</th>
                    <th style="padding:8px;border-bottom:1px solid #ddd">Exportar</th>
                </tr></thead>
                <tbody>
                <?php foreach ($modulesAvailable as $moduleKey => $moduleLabel): $p = $editModules[$moduleKey] ?? []; ?>
                    <tr>
                        <td style="padding:8px;border-bottom:1px solid #eee"><?= e($moduleLabel) ?></td>
                        <?php foreach (['view' => 'can_view', 'create' => 'can_create', 'edit' => 'can_edit', 'import' => 'can_import', 'export' => 'can_export'] as $field => $column): ?>
                            <td style="text-align:center;padding:8px;border-bottom:1px solid #eee">
                                <input type="checkbox" name="modules[<?= e($moduleKey) ?>][<?= e($field) ?>]" value="1" style="min-width:0;width:auto" <?= !empty($p[$column]) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p><button type="submit"><?= $editing ? 'Salvar alterações' : 'Criar usuário' ?></button>
        <?php if ($editing): ?> <a href="<?= e(app_base_path()) ?>/users.php">Cancelar edição</a><?php endif; ?></p>
    </form>
</div>

<div class="panel">
    <h2>Usuários cadastrados</h2>
    <?php if (!$users): ?><p class="muted">Nenhum usuário cadastrado.</p><?php endif; ?>
    <?php foreach ($users as $item): ?>
        <div class="import-row">
            <div>
                <strong><?= e($item['name']) ?></strong>
                <div><?= e($item['email']) ?></div>
                <span class="pill"><?= e(ucfirst($item['role'])) ?> · <?= (int)$item['active'] === 1 ? 'Ativo' : 'Inativo' ?></span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="<?= e(app_base_path()) ?>/users.php?edit=<?= (int)$item['id'] ?>">Editar</a>
                <?php if ((int)$item['id'] !== (int)$user['id']): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="toggle_user">
                        <input type="hidden" name="user_id" value="<?= (int)$item['id'] ?>">
                        <button type="submit"><?= (int)$item['active'] === 1 ? 'Desativar' : 'Reativar' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php page_footer(); ?>

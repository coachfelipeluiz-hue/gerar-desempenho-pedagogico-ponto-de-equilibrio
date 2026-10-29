<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';

function check_same(string $label, mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL, active INTEGER NOT NULL)');
$pdo->exec('CREATE TABLE user_module_permissions (
    user_id INTEGER NOT NULL,
    module_key TEXT NOT NULL,
    can_view INTEGER NOT NULL,
    can_create INTEGER NOT NULL,
    can_edit INTEGER NOT NULL,
    can_import INTEGER NOT NULL,
    can_export INTEGER NOT NULL
)');
$pdo->exec('CREATE TABLE user_unit_access (
    user_id INTEGER NOT NULL,
    subsede_id INTEGER NOT NULL,
    valid_from TEXT NOT NULL,
    valid_until TEXT NULL
)');
$pdo->exec("INSERT INTO users VALUES
    (1, 'Gestor', 'gestor@example.test', 'gestor', 1),
    (2, 'Consulta', 'consulta@example.test', 'consulta', 1),
    (3, 'Master', 'master@example.test', 'master', 1)");
$pdo->exec("INSERT INTO user_module_permissions VALUES
    (1, 'instructors', 1, 1, 0, 0, 0),
    (1, 'dashboard', 1, 0, 0, 0, 0),
    (2, 'costs', 0, 1, 0, 0, 0)");
$pdo->exec("INSERT INTO user_unit_access VALUES
    (1, 101, date('now', '-2 days'), NULL),
    (1, 102, date('now', '+1 day'), NULL),
    (1, 103, date('now', '-10 days'), date('now', '-1 day'))");

$checks = 0;
$_SESSION = ['user' => ['id' => 1, 'name' => 'Stale', 'role' => 'master', 'modules' => ['users']]];
check_same('revalidação do usuário ativo', true, refresh_current_user($pdo)); $checks++;
check_same('perfil recarregado do banco', 'gestor', current_user()['role']); $checks++;
check_same('nome recarregado do banco', 'Gestor', current_user()['name']); $checks++;
check_same('acesso ao módulo com can_view', true, can_access_module('instructors')); $checks++;
check_same('módulo sem can_view negado', false, can_access_module('costs')); $checks++;
check_same('ação create permitida com view e can_create', true, can_perform_module_action('instructors', 'create')); $checks++;
check_same('ação edit negada sem can_edit', false, can_perform_module_action('instructors', 'edit')); $checks++;
check_same('ação desconhecida negada', false, can_perform_module_action('instructors', 'approve')); $checks++;
check_same('permissões de unidade vigentes', [101], user_unit_ids($pdo)); $checks++;

$pdo->exec("UPDATE user_module_permissions SET can_create = 0 WHERE user_id = 1 AND module_key = 'instructors'");
check_same('refresh da permissão revogada', true, refresh_current_user($pdo)); $checks++;
check_same('ação revogada aplicada na sessão', false, can_perform_module_action('instructors', 'create')); $checks++;

$_SESSION = ['user' => ['id' => 2, 'role' => 'master']];
check_same('revalidação do usuário consulta', true, refresh_current_user($pdo)); $checks++;
check_same('ação sem can_view negada mesmo com can_create', false, can_perform_module_action('costs', 'create')); $checks++;
$pdo->exec('UPDATE users SET active = 0 WHERE id = 2');
check_same('usuário desativado invalida sessão', false, refresh_current_user($pdo)); $checks++;
check_same('sessão de usuário inativo removida', null, current_user()); $checks++;

$_SESSION = ['user' => ['id' => 3, 'role' => 'consulta']];
check_same('revalidação do Master ativo', true, refresh_current_user($pdo)); $checks++;
check_same('Master tem acesso global a módulos', true, can_access_module('users')); $checks++;
check_same('Master pode executar ação administrativa', true, can_perform_module_action('imports', 'import')); $checks++;
check_same('Master recebe escopo global', ['*'], user_unit_ids($pdo)); $checks++;

fwrite(STDOUT, "OK: {$checks} verificações de autorização passaram.\n");

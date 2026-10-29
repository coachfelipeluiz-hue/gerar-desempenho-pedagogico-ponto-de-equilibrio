<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    if ($pdo instanceof PDO) {
        refresh_current_user($pdo);
    } else {
        invalidate_current_user();
    }
} else {
    $_SESSION['user'] = null;
}

require __DIR__ . '/../app/layout.php';

$error = '';
$csrfLogin = csrf_token_value('csrf_login');

$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$publicPos = strpos($script, '/public/');
$base = $publicPos !== false
    ? substr($script, 0, $publicPos) . '/public'
    : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_token_matches('csrf_login', $_POST['csrf_token'] ?? null)) {
    $error = 'A sessão do formulário expirou. Atualize a página e tente novamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = $pdo?->prepare(
        'SELECT id, name, email, password_hash, role, active
         FROM users WHERE email = ? LIMIT 1'
    );

    if ($stmt) {
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (
            $user &&
            (int)$user['active'] === 1 &&
            password_verify($password, $user['password_hash'])
        ) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => (string)$user['name'],
                'email' => (string)$user['email'],
                'role' => (string)$user['role'],
            ];

            if (refresh_current_user($pdo)) {
                unset($_SESSION['csrf_login']);
                audit($pdo, (int)$user['id'], 'login', 'user', (int)$user['id']);
                header('Location: ' . $base . '/index.php');
                exit;
            }
        }
    }

    $error = 'Não foi possível entrar. Confira as credenciais ou a configuração do banco.';
}

page_header('Entrar');
?>

<form class="panel form" method="post">
    <input type="hidden" name="csrf_token" value="<?= e($csrfLogin) ?>">
    <?php if ($error): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <label>
        E-mail
        <input type="email" name="email" required autocomplete="username">
    </label>

    <label>
        Senha
        <input type="password" name="password" required autocomplete="current-password">
    </label>

    <button type="submit">Entrar</button>

    <p class="muted">
        O acesso requer usuário cadastrado no banco. Consulte o README para configurar o primeiro Master.
    </p>
</form>

<?php page_footer(); ?>

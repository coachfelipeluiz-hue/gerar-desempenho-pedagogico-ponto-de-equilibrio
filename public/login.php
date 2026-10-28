<?php
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo?->prepare('SELECT id, name, email, password_hash, role, active FROM users WHERE email = ? LIMIT 1');
    if ($stmt) {
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && (int)$user['active'] === 1 && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $modules = [];
            if ($user['role'] !== 'master') {
                $m = $pdo->prepare('SELECT module_key FROM user_module_permissions WHERE user_id = ? AND can_view = 1');
                $m->execute([(int)$user['id']]);
                $modules = array_column($m->fetchAll(), 'module_key');
            }
            $_SESSION['user'] = ['id'=>(int)$user['id'], 'name'=>$user['name'], 'email'=>$user['email'], 'role'=>$user['role'], 'modules'=>$modules];
            audit($pdo, (int)$user['id'], 'login', 'user', (int)$user['id']);
            header('Location: /index.php'); exit;
        }
    }
    $error = 'Não foi possível entrar. Confira as credenciais ou a configuração do banco.';
}
page_header('Entrar');
?>
<form class="panel form" method="post">
  <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
  <label>E-mail <input type="email" name="email" required autocomplete="username"></label>
  <label>Senha <input type="password" name="password" required autocomplete="current-password"></label>
  <button type="submit">Entrar</button>
  <p class="muted">O acesso requer usuário cadastrado no banco. Consulte o README para configurar o primeiro Master.</p>
</form>
<?php page_footer(); ?>

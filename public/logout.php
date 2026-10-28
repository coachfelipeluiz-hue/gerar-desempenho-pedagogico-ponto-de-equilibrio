
<?php
require __DIR__ . '/../app/bootstrap.php';

$user = current_user();

if ($user && $pdo) {
    audit($pdo, (int)$user['id'], 'logout', 'user', (int)$user['id']);
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'],
        $p['domain'],
        $p['secure'],
        $p['httponly']
    );
}

session_destroy();

$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$publicPos = strpos($script, '/public/');
$base = $publicPos !== false
    ? substr($script, 0, $publicPos) . '/public'
    : '';

header('Location: ' . $base . '/login.php');
exit;

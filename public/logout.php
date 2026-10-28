<?php
require __DIR__ . '/../app/bootstrap.php';
if (current_user() && $pdo) audit($pdo, (int)current_user()['id'], 'logout', 'user', (int)current_user()['id']);
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: /login.php'); exit;

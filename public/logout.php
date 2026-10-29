<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use o botão Sair da aplicação para encerrar a sessão.');
}

if (!csrf_token_matches('csrf_logout', $_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('A sessão do formulário expirou. Atualize a página e tente novamente.');
}

$user = current_user();
if ($user && $pdo instanceof PDO) {
    try {
        audit($pdo, (int)$user['id'], 'logout', 'user', (int)$user['id']);
    } catch (Throwable $exception) {
        error_log('Não foi possível registrar auditoria de logout: ' . $exception->getMessage());
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $cookie['path'],
        $cookie['domain'],
        $cookie['secure'],
        $cookie['httponly']
    );
}

session_destroy();
header('Location: ' . app_base_path() . '/login.php');
exit;

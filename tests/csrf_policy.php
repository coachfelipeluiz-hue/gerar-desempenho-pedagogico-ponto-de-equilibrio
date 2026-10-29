<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';

function assert_csrf(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$token = csrf_token_value('csrf_test');
assert_csrf(preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'Token CSRF deve ter 256 bits hexadecimais.');
assert_csrf(csrf_token_matches('csrf_test', $token), 'Token da sessão deve ser aceito.');
assert_csrf(!csrf_token_matches('csrf_test', str_repeat('0', 64)), 'Token diferente deve ser rejeitado.');
assert_csrf(!csrf_token_matches('csrf_test', ['token']), 'Valor não textual deve ser rejeitado.');
assert_csrf(csrf_token_value('csrf_test') === $token, 'Token deve permanecer estável durante a sessão.');

$_SESSION['csrf_test'] = 'inválido';
$rotated = csrf_token_value('csrf_test');
assert_csrf($rotated !== 'inválido' && preg_match('/\A[a-f0-9]{64}\z/', $rotated) === 1, 'Token malformado deve ser substituído.');

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

fwrite(STDOUT, "OK: tokens CSRF de sessão gerados, validados e malformados renovados.\n");

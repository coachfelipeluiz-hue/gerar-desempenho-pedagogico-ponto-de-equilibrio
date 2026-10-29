<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';

function assert_preview_binding(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$current = ['preview_token' => str_repeat('a', 64)];
$stale = ['preview_token' => str_repeat('b', 64)];

assert_preview_binding(pending_preview_matches($current, str_repeat('a', 64)), 'Token da prévia atual deve ser aceito.');
assert_preview_binding(!pending_preview_matches($current, str_repeat('b', 64)), 'Token de outra prévia deve ser rejeitado.');
assert_preview_binding(!pending_preview_matches($stale, str_repeat('a', 64)), 'Formulário antigo não deve confirmar ou cancelar a nova prévia.');
assert_preview_binding(!pending_preview_matches([], str_repeat('a', 64)), 'Prévia ausente deve ser rejeitada.');
assert_preview_binding(!pending_preview_matches($current, ['token']), 'Token não textual deve ser rejeitado.');
assert_preview_binding(!pending_preview_matches(['preview_token' => 'inválido'], 'inválido'), 'Token malformado deve ser rejeitado.');

fwrite(STDOUT, "OK: confirmação e cancelamento permanecem vinculados à prévia atual.\n");

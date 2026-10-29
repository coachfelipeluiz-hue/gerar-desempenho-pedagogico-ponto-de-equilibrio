<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';

function assert_user_id(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assert_user_id(parse_nonnegative_id('0') === 0, 'Zero deve representar criação de usuário.');
assert_user_id(parse_nonnegative_id('0012') === 12, 'Zeros à esquerda devem ser normalizados.');
assert_user_id(parse_nonnegative_id(42) === 42, 'ID inteiro válido deve ser aceito.');

foreach (['-1', '', 'abc', '1.5', '999999999999999999999999999999999999999'] as $invalid) {
    $rejected = false;
    try {
        parse_nonnegative_id($invalid);
    } catch (RuntimeException) {
        $rejected = true;
    }
    assert_user_id($rejected, 'Entrada de ID inválida deve ser rejeitada: ' . $invalid);
}

$rejectedArray = false;
try {
    parse_nonnegative_id(['1']);
} catch (RuntimeException) {
    $rejectedArray = true;
}
assert_user_id($rejectedArray, 'Array enviado como ID deve ser rejeitado.');

fwrite(STDOUT, "OK: IDs não negativos validados e entradas malformadas rejeitadas.\n");

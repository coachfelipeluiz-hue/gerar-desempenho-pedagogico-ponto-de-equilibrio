<?php
declare(strict_types=1);

require __DIR__ . '/../app/import_class_fields.php';

function assert_class_import(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$existing = [
    'source_code' => 'T-001',
    'name' => 'Turma antiga',
    'group_id' => 7,
    'polo_id' => 12,
    'course_name' => 'Frentista',
    'active_apprentices' => 18,
    'capacity' => 25,
    'status' => 'Ativa',
    'competence' => '2026-10-01',
];
$incoming = [
    'source_code' => null,
    'name' => 'Turma atualizada',
    'group_id' => null,
    'polo_id' => null,
    'course_name' => '',
    'active_apprentices' => null,
    'capacity' => null,
    'status' => '',
    'competence' => null,
];
$updated = merge_class_import_fields($existing, $incoming);
assert_class_import($updated['name'] === 'Turma atualizada', 'O nome mapeado deve ser atualizado.');
foreach (['source_code', 'group_id', 'polo_id', 'course_name', 'active_apprentices', 'capacity', 'status', 'competence'] as $field) {
    assert_class_import($updated[$field] === $existing[$field], 'O campo opcional não informado deve ser preservado: ' . $field);
}

$created = merge_class_import_fields(null, ['name' => 'Turma nova']);
assert_class_import($created['name'] === 'Turma nova', 'O nome da turma nova deve ser mantido.');
assert_class_import($created['active_apprentices'] === 0, 'Turma nova sem quantidade mapeada deve iniciar com zero.');
assert_class_import($created['capacity'] === null, 'Capacidade nova não informada deve permanecer vazia.');
assert_class_import(class_int_value('25') === 25, 'Contagem inteira deve ser aceita.');
assert_class_import(class_int_value('1.000') === 1000, 'Separador brasileiro de milhar deve ser aceito.');
assert_class_import(class_int_value('12,5') === null, 'Contagem fracionária não deve ser truncada.');
assert_class_import(class_int_value('-1') === null, 'Contagem negativa deve ser rejeitada.');
assert_class_import(class_int_value('12abc') === null, 'Texto misturado a um número deve ser rejeitado.');
assert_class_import(class_int_value('1000001') === null, 'Valores acima do limite suportado devem ser rejeitados.');
assert_class_import(class_import_identity_key(' C-001 ', 'Nome', 2) === class_import_identity_key('c-001', 'Outro', 8), 'Código de origem deve formar chave de identidade normalizada.');
assert_class_import(class_import_identity_key('', 'Turma', null) === class_import_identity_key('', ' turma ', null), 'Identidade de fallback deve normalizar espaços e caixa.');

fwrite(STDOUT, "OK: campos opcionais preservados e chaves estáveis verificadas.\n");

<?php
declare(strict_types=1);

function class_int_value(mixed $value): ?int {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{1,3}(?:[. ]\d{3})+$/', $value)) {
        $normalized = preg_replace('/[. ]/', '', $value);
    } elseif (preg_match('/^\d+[,.]0+$/', $value)) {
        $normalized = preg_replace('/[,.]0+$/', '', $value);
    } elseif (preg_match('/^\d+$/', $value)) {
        $normalized = $value;
    } else {
        return null;
    }

    $number = (int)$normalized;
    return $number <= 1000000 ? $number : null;
}

function merge_class_import_fields(?array $existing, array $incoming): array {
    $defaults = [
        'source_code' => null,
        'name' => '',
        'group_id' => null,
        'polo_id' => null,
        'course_name' => null,
        'active_apprentices' => 0,
        'capacity' => null,
        'status' => null,
        'competence' => null,
    ];
    $merged = array_merge($defaults, $incoming);

    if ($existing === null) {
        foreach ($defaults as $field => $default) {
            $value = $incoming[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $merged[$field] = $default;
            }
        }
        return $merged;
    }

    $preserveWhenBlank = [
        'source_code',
        'group_id',
        'polo_id',
        'course_name',
        'active_apprentices',
        'capacity',
        'status',
        'competence',
    ];

    foreach ($preserveWhenBlank as $field) {
        $value = $incoming[$field] ?? null;
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $merged[$field] = $existing[$field] ?? null;
        }
    }

    return $merged;
}

function class_import_identity_key(string $sourceCode, string $name, ?int $poloId): string {
    $sourceCode = trim($sourceCode);
    if ($sourceCode !== '') {
        return 'code:' . mb_strtolower($sourceCode, 'UTF-8');
    }

    return 'name:' . mb_strtolower(trim($name), 'UTF-8') . '|polo:' . ($poloId === null ? 'none' : (string)$poloId);
}

<?php
declare(strict_types=1);

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array {
    $user = $_SESSION['user'] ?? null;
    return is_array($user) ? $user : null;
}

function csrf_token_value(string $sessionKey): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $token = $_SESSION[$sessionKey] ?? null;
    if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[$sessionKey] = $token;
    }

    return $token;
}

function csrf_token_matches(string $sessionKey, mixed $submitted): bool {
    $expected = $_SESSION[$sessionKey] ?? null;
    return is_string($expected)
        && preg_match('/\A[a-f0-9]{64}\z/', $expected) === 1
        && is_string($submitted)
        && hash_equals($expected, $submitted);
}

function parse_nonnegative_id(mixed $value): int {
    if (!is_string($value) && !is_int($value)) {
        throw new RuntimeException('Identificador inválido.');
    }

    $raw = (string)$value;
    if (!preg_match('/\A\d+\z/', $raw)) {
        throw new RuntimeException('Identificador inválido.');
    }

    $canonical = ltrim($raw, '0');
    $canonical = $canonical === '' ? '0' : $canonical;
    $id = filter_var($canonical, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($id === false) {
        throw new RuntimeException('Identificador inválido.');
    }

    return $id;
}

function app_base_path(): string {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $publicPos = strpos($script, '/public/');

    if ($publicPos !== false) {
        return substr($script, 0, $publicPos) . '/public';
    }

    if (str_ends_with($script, '/public')) {
        return $script;
    }

    return '';
}

function invalidate_current_user(): void {
    unset($_SESSION['user']);

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
}

function refresh_current_user(PDO $pdo): bool {
    $sessionUser = current_user();
    $userId = (int)($sessionUser['id'] ?? 0);
    if ($userId < 1) {
        invalidate_current_user();
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT id, name, email, role, active
         FROM users
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['active'] !== 1) {
        invalidate_current_user();
        return false;
    }

    $permissionStmt = $pdo->prepare(
        'SELECT module_key, can_view, can_create, can_edit, can_import, can_export
         FROM user_module_permissions
         WHERE user_id = ?'
    );
    $permissionStmt->execute([$userId]);

    $permissions = [];
    foreach ($permissionStmt->fetchAll() as $permission) {
        $module = (string)$permission['module_key'];
        $permissions[$module] = [
            'can_view' => (int)$permission['can_view'] === 1,
            'can_create' => (int)$permission['can_create'] === 1,
            'can_edit' => (int)$permission['can_edit'] === 1,
            'can_import' => (int)$permission['can_import'] === 1,
            'can_export' => (int)$permission['can_export'] === 1,
        ];
    }

    $modules = [];
    foreach ($permissions as $module => $permission) {
        if ($permission['can_view']) {
            $modules[] = $module;
        }
    }

    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'email' => (string)$user['email'],
        'role' => (string)$user['role'],
        'modules' => $modules,
        'permissions' => $permissions,
    ];

    return true;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: ' . app_base_path() . '/login.php');
        exit;
    }

    global $pdo;
    if (!($pdo instanceof PDO)) {
        invalidate_current_user();
        http_response_code(503);
        exit('Não foi possível validar sua sessão. Tente novamente quando o banco estiver disponível.');
    }

    if (!refresh_current_user($pdo)) {
        header('Location: ' . app_base_path() . '/login.php');
        exit;
    }
}

function is_master_user(): bool {
    return (current_user()['role'] ?? '') === 'master';
}

function can_access_module(string $module): bool {
    $user = current_user();

    if (!$user) {
        return false;
    }

    if (($user['role'] ?? '') === 'master') {
        return true;
    }

    return !empty($user['permissions'][$module]['can_view']);
}

function can_perform_module_action(string $module, string $action): bool {
    $permissionField = match ($action) {
        'create' => 'can_create',
        'edit' => 'can_edit',
        'import' => 'can_import',
        'export' => 'can_export',
        default => null,
    };

    $user = current_user();
    if (!$user || $permissionField === null) {
        return false;
    }

    if (($user['role'] ?? '') === 'master') {
        return true;
    }

    $permissions = $user['permissions'][$module] ?? null;
    return is_array($permissions)
        && !empty($permissions['can_view'])
        && !empty($permissions[$permissionField]);
}

function user_unit_ids(PDO $pdo): array {
    $user = current_user();

    if (!$user) {
        return [];
    }

    if (($user['role'] ?? '') === 'master') {
        return ['*'];
    }

    $stmt = $pdo->prepare(
        'SELECT subsede_id
         FROM user_unit_access
         WHERE user_id = ?
           AND valid_from <= CURRENT_DATE
           AND (valid_until IS NULL OR valid_until >= CURRENT_DATE)'
    );

    $stmt->execute([(int)$user['id']]);

    return array_map('intval', array_column($stmt->fetchAll(), 'subsede_id'));
}

function audit(
    PDO $pdo,
    ?int $userId,
    string $action,
    string $entity,
    ?int $entityId = null,
    array $metadata = []
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_log
         (user_id, action, entity_type, entity_id, metadata_json, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())'
    );

    $stmt->execute([
        $userId,
        $action,
        $entity,
        $entityId,
        json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
    ]);
}

<?php
declare(strict_types=1);

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: /login.php');
        exit;
    }
}

function can_access_module(string $module): bool {
    $user = current_user();
    if (!$user) return false;
    if (($user['role'] ?? '') === 'master') return true;
    return in_array($module, $user['modules'] ?? [], true);
}

function user_unit_ids(PDO $pdo): array {
    $user = current_user();
    if (!$user) return [];
    if (($user['role'] ?? '') === 'master') return ['*'];
    $stmt = $pdo->prepare('SELECT subsede_id FROM user_unit_access WHERE user_id = ? AND (valid_until IS NULL OR valid_until >= CURDATE())');
    $stmt->execute([(int)$user['id']]);
    return array_map('intval', array_column($stmt->fetchAll(), 'subsede_id'));
}

function audit(PDO $pdo, ?int $userId, string $action, string $entity, ?int $entityId = null, array $metadata = []): void {
    $stmt = $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, metadata_json, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$userId, $action, $entity, $entityId, json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
}

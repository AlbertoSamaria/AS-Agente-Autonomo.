<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/auth/Auth.php';

final class RBAC {
    public static function allPermissions(): array {
        $stmt = db()->query('SELECT DISTINCT permission FROM roles_permissions ORDER BY permission');
        return array_values($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function permissionsForUser(?array $user = null): array {
        $u = $user ?? Auth::user();
        if (!$u) {
            return [];
        }

        $role = (string)($u['role'] ?? 'viewer');
        $userId = (int)($u['id'] ?? 0);

        $permissions = [];
        $roleStmt = db()->prepare('SELECT permission FROM roles_permissions WHERE role = ?');
        $roleStmt->execute([$role]);
        foreach ($roleStmt->fetchAll(PDO::FETCH_COLUMN) as $permission) {
            $permissions[$permission] = true;
        }

        if ($userId > 0) {
            $overrideStmt = db()->prepare('SELECT permission, granted FROM user_permissions WHERE user_id = ?');
            $overrideStmt->execute([$userId]);
            foreach ($overrideStmt->fetchAll() as $row) {
                $permissions[(string)$row['permission']] = (bool)$row['granted'];
            }
        }

        return $permissions;
    }

    public static function can(string $permission): bool {
        $u = Auth::user();
        if (!$u) {
            return false;
        }
        if (($u['role'] ?? '') === 'admin') {
            return true;
        }

        $permissions = self::permissionsForUser($u);
        return array_key_exists($permission, $permissions) ? (bool)$permissions[$permission] : false;
    }

    public static function require(string $permission): void {
        Auth::requireLogin();
        if (!self::can($permission)) {
            json_response(['success' => false, 'error' => 'Permissão insuficiente'], 403);
        }
    }
}

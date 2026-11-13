<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/Auth.php';

try {
    $user = Auth::requireLogin();
    if (($user['role'] ?? '') !== 'admin') {
        json_response(['success' => false, 'error' => 'Permissão insuficiente'], 403);
    }

    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'users') {
        $stmt = db()->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY created_at DESC');
        json_response(['success' => true, 'users' => $stmt->fetchAll()]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'approvals') {
        $stmt = db()->query(
            "SELECT a.*, u.name AS requester_name, d.name AS decision_name
             FROM approvals a
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN users d ON d.id = a.decided_by
             ORDER BY a.created_at DESC LIMIT 50"
        );
        json_response(['success' => true, 'approvals' => $stmt->fetchAll()]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'permissions') {
        $targetUserId = (int)($_GET['user_id'] ?? 0);
        $permissionStmt = db()->query('SELECT DISTINCT permission FROM roles_permissions ORDER BY permission');
        $permissions = $permissionStmt->fetchAll(PDO::FETCH_COLUMN);
        $selected = [];

        if ($targetUserId > 0) {
            $stmt = db()->prepare('SELECT permission, granted FROM user_permissions WHERE user_id = ? ORDER BY permission');
            $stmt->execute([$targetUserId]);
            foreach ($stmt->fetchAll() as $row) {
                $selected[$row['permission']] = (bool)$row['granted'];
            }
        }

        $effective = [];
        $targetUser = $targetUserId > 0 ? db()->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1') : null;
        if ($targetUser !== null) {
            $targetUser->execute([$targetUserId]);
            $row = $targetUser->fetch();
            $role = (string)($row['role'] ?? 'viewer');
            $permissionsByRole = [];
            $rolePermissionStmt = db()->prepare('SELECT permission FROM roles_permissions WHERE role = ?');
            $rolePermissionStmt->execute([$role]);
            foreach ($rolePermissionStmt->fetchAll(PDO::FETCH_COLUMN) as $permission) {
                $permissionsByRole[$permission] = true;
            }
            foreach ($permissions as $permission) {
                if (array_key_exists($permission, $selected)) {
                    $effective[$permission] = (bool)$selected[$permission];
                    continue;
                }
                $effective[$permission] = (bool)($permissionsByRole[$permission] ?? false);
            }
        } else {
            foreach ($permissions as $permission) {
                $effective[$permission] = false;
            }
        }

        json_response(['success' => true, 'permissions' => $permissions, 'effective' => $effective, 'selected' => $selected, 'user_id' => $targetUserId]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'audit') {
        $stmt = db()->query(
            "SELECT a.*, actor.name AS actor_name, target.name AS target_name 
             FROM audit_logs a
             LEFT JOIN users actor ON actor.id = a.user_id
             LEFT JOIN users target ON target.id = a.target_user_id
             ORDER BY a.created_at DESC LIMIT 100"
        );
        json_response(['success' => true, 'audit' => $stmt->fetchAll()]);
    }

    require_post();
    Auth::requireCsrf();
    $data = json_decode((string)file_get_contents('php://input'), true) ?? [];

    if ($action === 'create-user') {
        $name = trim((string)($data['name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $role = in_array((string)($data['role'] ?? ''), ['viewer', 'operator', 'manager', 'admin'], true)
            ? (string)$data['role']
            : 'viewer';

        if ($name === '' || $email === '' || strlen($password) < 8) {
            json_response(['success' => false, 'error' => 'Nome, email e palavra-passe válidos são obrigatórios.'], 422);
        }

        $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            json_response(['success' => false, 'error' => 'Já existe um utilizador com este email.'], 409);
        }

        $insert = db()->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
        $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $createdId = (int)db()->lastInsertId();

        $created = db()->prepare('SELECT id, name, email, role, active, created_at FROM users WHERE id = ? LIMIT 1');
        $created->execute([$createdId]);
        $createdUser = $created->fetch();
        Auth::audit('create_user', ['name' => $name, 'email' => $email, 'role' => $role], $createdId);
        json_response(['success' => true, 'user' => $createdUser]);
    }

    if ($action === 'set-role') {
        $userId = (int)($data['user_id'] ?? 0);
        $role = in_array((string)($data['role'] ?? ''), ['viewer', 'operator', 'manager', 'admin'], true)
            ? (string)$data['role']
            : null;

        if ($userId <= 0 || $role === null) {
            json_response(['success' => false, 'error' => 'Dados inválidos para atribuição da role.'], 422);
        }

        $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $userId]);
        Auth::audit('set_role', ['target_user_id' => $userId, 'role' => $role], $userId);
        json_response(['success' => $stmt->rowCount() > 0]);
    }

    if ($action === 'toggle-user') {
        $userId = (int)($data['user_id'] ?? 0);
        $active = (bool)($data['active'] ?? false);

        if ($userId <= 0) {
            json_response(['success' => false, 'error' => 'ID do utilizador inválido.'], 422);
        }

        if ((int)$userId === (int)$user['id']) {
            json_response(['success' => false, 'error' => 'Não podes desativar o teu próprio utilizador.'], 422);
        }

        $stmt = db()->prepare('UPDATE users SET active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $userId]);
        Auth::audit('toggle_user', ['target_user_id' => $userId, 'active' => $active], $userId);
        json_response(['success' => $stmt->rowCount() > 0]);
    }

    if ($action === 'set-user-permission') {
        $userId = (int)($data['user_id'] ?? 0);
        $permission = trim((string)($data['permission'] ?? ''));
        $granted = (bool)($data['granted'] ?? false);

        if ($userId <= 0 || $permission === '') {
            json_response(['success' => false, 'error' => 'Dados inválidos para permissão.'], 422);
        }

        $stmt = db()->prepare(
            'INSERT INTO user_permissions (user_id, permission, granted) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE granted = VALUES(granted)'
        );
        $stmt->execute([$userId, $permission, $granted ? 1 : 0]);
        Auth::audit('set_user_permission', ['target_user_id' => $userId, 'permission' => $permission, 'granted' => $granted], $userId);
        json_response(['success' => true]);
    }

    json_response(['success' => false, 'error' => 'Ação inválida'], 400);
} catch (Throwable $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}

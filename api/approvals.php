<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/middleware/RBAC.php';
require_once dirname(__DIR__) . '/services/tools/ToolRegistry.php';

try {
    $approver = Auth::requireLogin();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        RBAC::require('approvals.read');
        $stmt = db()->query(
            "SELECT a.*, u.name AS requester
             FROM approvals a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.status = 'pending'
             ORDER BY a.created_at DESC"
        );
        json_response(['success' => true, 'approvals' => $stmt->fetchAll()]);
    }

    require_post();
    Auth::requireCsrf();
    RBAC::require('approvals.approve');

    $data = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $id = (int) ($data['id'] ?? 0);
    $decision = (string) ($data['decision'] ?? '');
    if ($id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) {
        json_response(['success' => false, 'error' => 'Pedido ou decisão inválida.'], 422);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM approvals WHERE id = ? AND status = 'pending' FOR UPDATE");
    $stmt->execute([$id]);
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$approval) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Pedido inexistente ou já processado.'], 404);
    }

    $result = null;
    $runStatus = $decision === 'approved' ? 'completed' : 'failed';
    if ($decision === 'approved') {
        $toolName = (string) $approval['tool_name'];
        $arguments = json_decode((string) $approval['arguments_json'], true);
        $requesterId = (int) ($approval['user_id'] ?? 0);
        $registry = new ToolRegistry();

        if (!$registry->isAllowed($toolName) || !$registry->sensitive($toolName) || !is_array($arguments) || $requesterId <= 0) {
            $pdo->rollBack();
            json_response(['success' => false, 'error' => 'A operação aprovada não é válida ou não está autorizada.'], 403);
        }

        $userStmt = $pdo->prepare('SELECT id, role, active FROM users WHERE id = ? LIMIT 1');
        $userStmt->execute([$requesterId]);
        $requester = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$requester || !(bool) $requester['active']) {
            $pdo->rollBack();
            json_response(['success' => false, 'error' => 'O utilizador que solicitou a operação já não está activo.'], 403);
        }

        $permission = $registry->permission($toolName);
        if (($requester['role'] ?? '') !== 'admin') {
            $permissionStmt = $pdo->prepare(
                'SELECT granted FROM user_permissions WHERE user_id = ? AND permission = ? LIMIT 1'
            );
            $permissionStmt->execute([$requesterId, $permission]);
            $override = $permissionStmt->fetchColumn();
            if ($override !== false) {
                $allowed = (bool) $override;
            } else {
                $rolePermission = $pdo->prepare('SELECT 1 FROM roles_permissions WHERE role = ? AND permission = ? LIMIT 1');
                $rolePermission->execute([$requester['role'], $permission]);
                $allowed = (bool) $rolePermission->fetchColumn();
            }
            if (!$allowed) {
                $pdo->rollBack();
                json_response(['success' => false, 'error' => 'O utilizador solicitante já não possui a permissão necessária.'], 403);
            }
        }

        $result = $registry->execute($toolName, $arguments, $requesterId);
        $runStatus = !empty($result['success']) ? 'completed' : 'failed';
    }

    $update = $pdo->prepare(
        'UPDATE approvals SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ? AND status = \'pending\''
    );
    $update->execute([$decision, (int) $approver['id'], $id]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'O pedido foi processado por outro administrador.'], 409);
    }

    if (!empty($approval['agent_run_id'])) {
        $runResult = $decision === 'rejected'
            ? 'A operação foi rejeitada pelo aprovador.'
            : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $run = $pdo->prepare('UPDATE agent_runs SET status = ?, result = ?, completed_at = NOW() WHERE id = ?');
        $run->execute([$runStatus, $runResult, (int) $approval['agent_run_id']]);
    }

    $pdo->commit();
    Auth::audit('approval_decision', [
        'approval_id' => $id,
        'decision' => $decision,
        'tool' => $approval['tool_name'],
        'execution_success' => $decision === 'approved' ? (bool) ($result['success'] ?? false) : null,
    ], (int) $approver['id']);

    json_response([
        'success' => true,
        'decision' => $decision,
        'executed' => $decision === 'approved',
        'result' => $result,
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'error' => $error->getMessage()], 500);
}

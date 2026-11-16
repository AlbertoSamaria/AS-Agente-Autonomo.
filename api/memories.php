<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/middleware/RBAC.php';
require_once dirname(__DIR__) . '/services/memory/Memory.php';

try {
    $user = Auth::requireLogin();
    $memory = new Memory();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        RBAC::require('memory.read');
        json_response([
            'success' => true,
            'memories' => $memory->allForUser((int) $user['id']),
        ]);
    }

    require_post();
    RBAC::require('memory.write');
    Auth::requireCsrf();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_response(['success' => false, 'error' => 'Pedido inválido.'], 400);
    }

    $action = $data['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
        $type = $data['type'] ?? null;
        $content = $data['content'] ?? null;
        $importance = $data['importance'] ?? null;
        if (
            !is_string($type)
            || !is_string($content)
            || !is_int($importance)
        ) {
            json_response(['success' => false, 'error' => 'Os dados da memória são inválidos.'], 422);
        }

        if ($action === 'create') {
            $id = $memory->save((int) $user['id'], $type, $content, $importance);
            json_response(['success' => true, 'id' => $id]);
        }

        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || !$memory->update((int) $id, (int) $user['id'], $type, $content, $importance)) {
            json_response(['success' => false, 'error' => 'Memória não encontrada.'], 404);
        }
        json_response(['success' => true]);
    }

    if ($action === 'delete') {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null || !$memory->delete((int) $id, (int) $user['id'])) {
            json_response(['success' => false, 'error' => 'Memória não encontrada.'], 404);
        }
        json_response(['success' => true]);
    }

    json_response(['success' => false, 'error' => 'Ação inválida.'], 400);
} catch (InvalidArgumentException $error) {
    json_response(['success' => false, 'error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('[SA Memories] ' . $error->getMessage());
    json_response(['success' => false, 'error' => 'Não foi possível gerir as memórias.'], 500);
}

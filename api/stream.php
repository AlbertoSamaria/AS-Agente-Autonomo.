<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/middleware/RBAC.php';
require_once dirname(__DIR__) . '/services/agent/Agent.php';
require_once dirname(__DIR__) . '/services/security/RateLimiter.php';

try {
    require_post();
    Auth::requireCsrf();
    RBAC::require('chat');
    $user = Auth::requireLogin();
    if (!(new RateLimiter())->allow(
        'chat',
        30,
        60,
        RateLimiter::identity((int) $user['id'])
    )) {
        json_response(['success' => false, 'error' => 'Limite de mensagens atingido. Tenta novamente dentro de um minuto.'], 429);
    }
    $data = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($data)) {
        json_response(['success' => false, 'error' => 'Pedido inválido.'], 400);
    }

    $input = trim((string) ($data['message'] ?? ''));
    if ($input === '' || mb_strlen($input) > 12000) {
        json_response(['success' => false, 'error' => 'A mensagem deve ter entre 1 e 12000 caracteres.'], 422);
    }

    $conversationId = (int) ($data['conversation_id'] ?? 0);
    if ($conversationId <= 0) {
        $statement = db()->prepare(
            'INSERT INTO conversations(user_id,title) VALUES(?,?)'
        );
        $statement->execute([(int) $user['id'], mb_substr($input, 0, 60)]);
        $conversationId = (int) db()->lastInsertId();
    } else {
        $owner = db()->prepare(
            'SELECT id FROM conversations WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $owner->execute([$conversationId, (int) $user['id']]);
        if (!$owner->fetchColumn()) {
            json_response(['success' => false, 'error' => 'Conversa não encontrada.'], 404);
        }
    }

    $ids = $data['document_ids'] ?? [];
    if (!is_array($ids) || count($ids) > 20) {
        json_response(['success' => false, 'error' => 'Lista de documentos inválida.'], 422);
    }
    $documentIds = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn(int $id): bool => $id > 0
    )));
    if ($documentIds !== []) {
        RBAC::require('rag.read');
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $documents = db()->prepare(
            "SELECT id
             FROM documents
             WHERE user_id = ? AND status = 'ready' AND id IN ($placeholders)"
        );
        $documents->execute(array_merge([(int) $user['id']], $documentIds));
        $validIds = array_map('intval', $documents->fetchAll(PDO::FETCH_COLUMN));
        if (count($validIds) !== count($documentIds)) {
            json_response(['success' => false, 'error' => 'Um ou mais ficheiros não estão disponíveis na tua biblioteca.'], 403);
        }
        $documentIds = $validIds;
    }

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('X-Content-Type-Options: nosniff');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    $agent = new Agent();
    $agent->saveMessage($conversationId, 'user', $input);
    $streamed = '';
    $result = $agent->run(
        $input,
        $conversationId,
        (int) $user['id'],
        $documentIds,
        static function (string $token) use (&$streamed): void {
            $streamed .= $token;
            echo 'data: ' . json_encode(
                ['content' => $token],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . "\n\n";
            flush();
        }
    );

    if ($result['success'] && !isset($result['approval_id'])) {
        $agent->saveMessage($conversationId, 'assistant', $result['content']);
    }
    if ($streamed === '' && !empty($result['content'])) {
        echo 'data: ' . json_encode(
            ['content' => $result['content']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n\n";
    }
    echo 'data: ' . json_encode([
        'done' => true,
        'success' => (bool) $result['success'],
        'conversation_id' => $conversationId,
        'error' => $result['error'] ?? null,
        'approval_id' => $result['approval_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
} catch (Throwable $error) {
    error_log('[SA Stream] ' . $error->getMessage());
    if (!headers_sent()) {
        json_response(['success' => false, 'error' => 'Não foi possível iniciar o streaming.'], 500);
    }
    echo 'data: ' . json_encode(
        ['error' => 'Não foi possível concluir o streaming.'],
        JSON_UNESCAPED_UNICODE
    ) . "\n\n";
    flush();
}

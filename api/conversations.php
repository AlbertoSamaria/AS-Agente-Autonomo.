<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/middleware/RBAC.php';

try {
    RBAC::require('chat');
    $user = Auth::requireLogin();
    $conversationId = (int) ($_GET['id'] ?? 0);

    if ($conversationId > 0) {
        $conversation = db()->prepare(
            'SELECT id, title FROM conversations WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $conversation->execute([$conversationId, (int) $user['id']]);
        $row = $conversation->fetch();
        if (!$row) {
            json_response(['success' => false, 'error' => 'Conversa não encontrada.'], 404);
        }

        $messages = db()->prepare(
            "SELECT role, content, created_at
             FROM messages
             WHERE conversation_id = ? AND role IN ('user', 'assistant')
             ORDER BY id ASC"
        );
        $messages->execute([$conversationId]);
        json_response([
            'success' => true,
            'conversation' => $row,
            'messages' => $messages->fetchAll(),
        ]);
    }

    $conversations = db()->prepare(
        'SELECT id, title, updated_at
         FROM conversations
         WHERE user_id = ?
         ORDER BY updated_at DESC
         LIMIT 50'
    );
    $conversations->execute([(int) $user['id']]);
    json_response([
        'success' => true,
        'conversations' => $conversations->fetchAll(),
    ]);
} catch (Throwable $error) {
    error_log('[SA Conversations] ' . $error->getMessage());
    json_response(['success' => false, 'error' => 'Não foi possível carregar as conversas.'], 500);
}

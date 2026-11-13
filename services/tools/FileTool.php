<?php
declare(strict_types=1);
final class FileTool {
    public function listUploads(int $userId): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Utilizador inválido para consultar ficheiros.');
        }

        $stmt = db()->prepare(
            "SELECT id, original_name AS name, size_bytes AS size, status, created_at
             FROM documents
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 100"
        );
        $stmt->execute([$userId]);
        return ['success' => true, 'files' => $stmt->fetchAll()];
    }
}

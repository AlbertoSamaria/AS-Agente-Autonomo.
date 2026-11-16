<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/config/config.php';

final class Memory {
    private const TYPES = ['fact', 'preference', 'project', 'episode', 'note'];

    public function allForUser(int $userId): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('É necessário identificar o utilizador das memórias.');
        }

        $stmt = db()->prepare(
            'SELECT id, type, content, importance, created_at, updated_at
             FROM memories
             WHERE user_id = ?
             ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function relevant(string $query, int $userId, int $limit = 8): array {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($query), $matches);
        $terms = array_values(array_unique(array_filter(
            $matches[0] ?? [],
            static fn(string $term): bool => !in_array(
                $term,
                ['que', 'para', 'com', 'uma', 'uns', 'das', 'dos', 'the', 'and', 'for'],
                true
            )
        )));
        $terms = array_slice($terms, 0, 8);
        if ($terms === []) {
            return [];
        }

        $conditions = implode(' OR ', array_fill(0, count($terms), 'content LIKE ?'));
        $pdo = db();
        $stmt = $pdo->prepare(
            "SELECT id,type,content,importance
             FROM memories
             WHERE user_id = ? AND (" . $conditions . ")
             ORDER BY importance DESC, updated_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        foreach ($terms as $index => $term) {
            $stmt->bindValue($index + 2, '%' . $term . '%');
        }
        $stmt->bindValue(count($terms) + 2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function save(int $userId, string $type, string $content, int $importance=5): int {
        if ($userId <= 0) {
            throw new InvalidArgumentException('É necessário identificar o utilizador da memória.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de memória inválido.');
        }

        $content = trim($content);
        $this->validateContent($content);

        $stmt=db()->prepare("INSERT INTO memories(user_id,type,content,importance) VALUES(?,?,?,?)");
        $stmt->execute([$userId,$type,$content,max(1,min(10,$importance))]);
        return (int)db()->lastInsertId();
    }

    public function update(int $memoryId, int $userId, string $type, string $content, int $importance): bool {
        if ($memoryId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Identificação de memória inválida.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Tipo de memória inválido.');
        }

        $content = trim($content);
        $this->validateContent($content);
        if ($importance < 1 || $importance > 10) {
            throw new InvalidArgumentException('A importância deve estar entre 1 e 10.');
        }

        $stmt = db()->prepare(
            'UPDATE memories SET type = ?, content = ?, importance = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$type, $content, $importance, $memoryId, $userId]);

        $check = db()->prepare('SELECT id FROM memories WHERE id = ? AND user_id = ? LIMIT 1');
        $check->execute([$memoryId, $userId]);
        return (bool) $check->fetchColumn();
    }

    public function delete(int $memoryId, int $userId): bool {
        if ($memoryId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('Identificação de memória inválida.');
        }

        $stmt = db()->prepare('DELETE FROM memories WHERE id = ? AND user_id = ?');
        $stmt->execute([$memoryId, $userId]);
        return $stmt->rowCount() > 0;
    }

    private function validateContent(string $content): void {
        if ($content === '') {
            throw new InvalidArgumentException('O conteúdo da memória não pode estar vazio.');
        }
        if (mb_strlen($content) > 1000) {
            throw new InvalidArgumentException('A memória não pode exceder 1000 caracteres.');
        }
    }
}

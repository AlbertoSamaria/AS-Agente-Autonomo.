<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/config/config.php';

final class Memory {
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
        if (!in_array($type, ['fact', 'preference', 'project', 'episode', 'note'], true)) {
            throw new InvalidArgumentException('Tipo de memória inválido.');
        }

        $content = trim($content);
        if ($content === '') {
            throw new InvalidArgumentException('O conteúdo da memória não pode estar vazio.');
        }

        $stmt=db()->prepare("INSERT INTO memories(user_id,type,content,importance) VALUES(?,?,?,?)");
        $stmt->execute([$userId,$type,$content,max(1,min(10,$importance))]);
        return (int)db()->lastInsertId();
    }
}

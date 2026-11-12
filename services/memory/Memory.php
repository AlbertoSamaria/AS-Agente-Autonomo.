<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/config/config.php';

final class Memory {
    public function relevant(string $query, int $limit = 8): array {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id,type,content,importance FROM memories WHERE content LIKE ? ORDER BY importance DESC, updated_at DESC LIMIT ?");
        $like = '%'.mb_substr($query,0,180).'%';
        $stmt->bindValue(1,$like);
        $stmt->bindValue(2,$limit,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function save(string $type, string $content, int $importance=5): int {
        $stmt=db()->prepare("INSERT INTO memories(type,content,importance) VALUES(?,?,?)");
        $stmt->execute([$type,$content,max(1,min(10,$importance))]);
        return (int)db()->lastInsertId();
    }
}

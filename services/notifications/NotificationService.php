<?php
declare(strict_types=1);
final class NotificationService {
    public function send(?int $userId,string $title,string $message,string $type='info'): int {
        $s=db()->prepare("INSERT INTO notifications(user_id,title,message,type) VALUES(?,?,?,?)");
        $s->execute([$userId,$title,$message,$type]); return (int)db()->lastInsertId();
    }
    public function unread(?int $userId): array {
        $s=db()->prepare("SELECT * FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY created_at DESC LIMIT 50");
        $s->execute([$userId]); return $s->fetchAll();
    }
}

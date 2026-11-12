<?php
declare(strict_types=1);
final class Scheduler {
    public function due(): array {
        $s=db()->query("SELECT * FROM schedules WHERE enabled=1 AND (next_run_at IS NULL OR next_run_at<=NOW()) ORDER BY next_run_at LIMIT 50");
        return $s->fetchAll();
    }
    public function markRun(int $id): void {
        db()->prepare("UPDATE schedules SET last_run_at=NOW(), next_run_at=DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE id=?")->execute([$id]);
    }
}

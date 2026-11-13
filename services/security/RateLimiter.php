<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';

final class RateLimiter
{
    public function allow(string $scope, int $limit, int $windowSeconds, string $identity): bool
    {
        if ($limit < 1 || $windowSeconds < 1 || $scope === '' || $identity === '') {
            throw new InvalidArgumentException('Parâmetros inválidos para controlo de frequência.');
        }

        $bucketKey = hash('sha256', $scope . ':' . $identity);
        $now = time();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $statement = db()->prepare(
            'INSERT INTO request_limits(bucket_key,window_start,requests)
             VALUES(?,?,1)
             ON DUPLICATE KEY UPDATE requests = requests + 1'
        );
        $statement->execute([$bucketKey, $windowStart]);

        $count = db()->prepare(
            'SELECT requests FROM request_limits WHERE bucket_key = ? AND window_start = ?'
        );
        $count->execute([$bucketKey, $windowStart]);
        $requests = (int) $count->fetchColumn();

        $cleanup = db()->prepare(
            'DELETE FROM request_limits WHERE bucket_key = ? AND window_start < ?'
        );
        $cleanup->execute([$bucketKey, $windowStart - $windowSeconds]);

        return $requests <= $limit;
    }

    public static function identity(?int $userId = null): string
    {
        if ($userId !== null && $userId > 0) {
            return 'user:' . $userId;
        }

        $address = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return 'ip:' . hash('sha256', $address);
    }
}

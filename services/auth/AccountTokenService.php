<?php
declare(strict_types=1);

final class AccountTokenService
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function issue(PDO $pdo, int $userId, string $purpose, int $hours): string
    {
        if (!in_array($purpose, ['verify_email', 'password_reset'], true)) {
            throw new InvalidArgumentException('Finalidade de token inválida.');
        }

        $pdo->prepare(
            'UPDATE account_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL'
        )->execute([$userId, $purpose]);

        $token = self::generate();
        $expires = (new DateTimeImmutable('now'))
            ->modify('+' . max(1, $hours) . ' hours')
            ->format('Y-m-d H:i:s');

        $statement = $pdo->prepare(
            'INSERT INTO account_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$userId, $purpose, self::hash($token), $expires]);

        return $token;
    }

    /**
     * Must be called inside a transaction. Returns the owner ID or null.
     */
    public static function consume(PDO $pdo, string $token, string $purpose): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT id, user_id FROM account_tokens
             WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([self::hash($token), $purpose]);
        $record = $statement->fetch();

        if (!$record) {
            return null;
        }

        $pdo->prepare('UPDATE account_tokens SET used_at = NOW() WHERE id = ?')
            ->execute([(int)$record['id']]);

        return (int)$record['user_id'];
    }
}

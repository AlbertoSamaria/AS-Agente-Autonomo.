<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';

final class Auth {
    public static function start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }

    public static function csrfToken(): string {
        self::start();
        if (empty($_SESSION['sa_csrf'])) {
            $_SESSION['sa_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['sa_csrf'];
    }

    public static function requireCsrf(?string $token = null): void {
        self::start();
        if (!self::user()) {
            return;
        }

        $provided = $token ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? ''));
        $expected = $_SESSION['sa_csrf'] ?? '';
        if (!is_string($provided) || !hash_equals($expected, $provided)) {
            json_response(['success' => false, 'error' => 'CSRF inválido.'], 403);
        }
    }

    public static function audit(string $action, array $details = [], ?int $targetUserId = null, string $status = 'success'): void {
        try {
            $user = self::user();
            $stmt = db()->prepare(
                'INSERT INTO audit_logs (user_id, target_user_id, action, status, ip_address, user_agent, details_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $user['id'] ?? null,
                $targetUserId,
                $action,
                $status,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null,
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'
            ]);
        } catch (Throwable $e) {
            // Logging failures must not break the request.
        }
    }

    public static function login(string $email,string $password): bool {
        self::start();
        $s=db()->prepare("SELECT * FROM users WHERE email=? AND active=1 LIMIT 1");
        $s->execute([$email]); $u=$s->fetch();
        if(!$u || !password_verify($password,$u['password_hash'])) return false;
        $_SESSION['sa_user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];
        self::csrfToken();
        self::audit('login', ['email' => $u['email']], (int)$u['id']);
        return true;
    }
    public static function logout(): void { self::start(); $u=self::user(); if($u){ self::audit('logout', ['email' => $u['email']], (int)$u['id']); } unset($_SESSION['sa_user']); }
    public static function user(): ?array { self::start(); return $_SESSION['sa_user']??null; }
    public static function requireLogin(): array {
        $u=self::user(); if(!$u) json_response(['success'=>false,'error'=>'Autenticação necessária'],401); return $u;
    }
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';

final class Auth {
    public static function start(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }
    public static function login(string $email,string $password): bool {
        self::start();
        $s=db()->prepare("SELECT * FROM users WHERE email=? AND active=1 LIMIT 1");
        $s->execute([$email]); $u=$s->fetch();
        if(!$u || !password_verify($password,$u['password_hash'])) return false;
        $_SESSION['sa_user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];
        return true;
    }
    public static function logout(): void { self::start(); unset($_SESSION['sa_user']); }
    public static function user(): ?array { self::start(); return $_SESSION['sa_user']??null; }
    public static function requireLogin(): array {
        $u=self::user(); if(!$u) json_response(['success'=>false,'error'=>'Autenticação necessária'],401); return $u;
    }
}

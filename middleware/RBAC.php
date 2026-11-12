<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/auth/Auth.php';

final class RBAC {
    public static function can(string $permission): bool {
        $u=Auth::user(); if(!$u) return false;
        if($u['role']==='admin') return true;
        $s=db()->prepare("SELECT 1 FROM roles_permissions WHERE role=? AND (permission=? OR permission='*') LIMIT 1");
        $s->execute([$u['role'],$permission]); return (bool)$s->fetchColumn();
    }
    public static function require(string $permission): void {
        Auth::requireLogin();
        if(!self::can($permission)) json_response(['success'=>false,'error'=>'Permissão insuficiente'],403);
    }
}

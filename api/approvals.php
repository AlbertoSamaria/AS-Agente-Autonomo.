<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';

try {
    $u=Auth::requireLogin();
    if($_SERVER['REQUEST_METHOD']==='GET'){
        RBAC::require('approvals.read');
        $s=db()->query("SELECT a.*, u.name requester FROM approvals a LEFT JOIN users u ON u.id=a.user_id WHERE a.status='pending' ORDER BY a.created_at DESC");
        json_response(['success'=>true,'approvals'=>$s->fetchAll()]);
    }
    require_post(); RBAC::require('approvals.approve');
    $d=json_decode(file_get_contents('php://input'),true)?:[];
    $id=(int)($d['id']??0); $decision=$d['decision']??'';
    if(!in_array($decision,['approved','rejected'],true)) json_response(['success'=>false,'error'=>'Decisão inválida'],422);
    $s=db()->prepare("UPDATE approvals SET status=?,decided_by=?,decided_at=NOW() WHERE id=? AND status='pending'");
    $s->execute([$decision,$u['id'],$id]);
    json_response(['success'=>$s->rowCount()>0]);
} catch(Throwable $e){ json_response(['success'=>false,'error'=>$e->getMessage()],500); }

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';
require_once dirname(__DIR__).'/services/notifications/NotificationService.php';
try{
    RBAC::require('notifications.read'); $u=Auth::user();
    if($_SERVER['REQUEST_METHOD']==='GET') json_response(['success'=>true,'notifications'=>(new NotificationService())->unread($u['id'])]);
    require_post(); $d=json_decode(file_get_contents('php://input'),true)?:[];
    if(($d['action']??'')==='read'){db()->prepare("UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?")->execute([(int)$d['id'],$u['id']]);json_response(['success'=>true]);}
    json_response(['success'=>false,'error'=>'Ação inválida'],400);
}catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],500);}

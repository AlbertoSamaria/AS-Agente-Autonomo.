<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/middleware/RBAC.php';
require_once dirname(__DIR__) . '/services/tools/TaskTool.php';
try {
    $user = Auth::requireLogin();
    $tool=new TaskTool();
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        RBAC::require('tasks.read');
        json_response($tool->list(['status'=>$_GET['status']??null], (int) $user['id']));
    }
    RBAC::require('tasks.write');
    require_post();
    Auth::requireCsrf();
    $data=json_decode(file_get_contents('php://input'),true)?:[];
    $action=$data['action']??'create';
    json_response(match($action){
        'create'=>$tool->create($data, (int) $user['id']),
        'complete'=>$tool->complete($data, (int) $user['id']),
        default=>['success'=>false,'error'=>'Ação inválida']
    });
} catch(Throwable $e){ json_response(['success'=>false,'error'=>$e->getMessage()],500); }

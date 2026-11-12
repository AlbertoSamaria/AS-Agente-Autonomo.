<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/services/tools/TaskTool.php';
try {
    $tool=new TaskTool();
    if ($_SERVER['REQUEST_METHOD']==='GET') json_response($tool->list(['status'=>$_GET['status']??null]));
    require_post();
    $data=json_decode(file_get_contents('php://input'),true)?:[];
    $action=$data['action']??'create';
    json_response(match($action){
        'create'=>$tool->create($data),
        'complete'=>$tool->complete($data),
        default=>['success'=>false,'error'=>'Ação inválida']
    });
} catch(Throwable $e){ json_response(['success'=>false,'error'=>$e->getMessage()],500); }

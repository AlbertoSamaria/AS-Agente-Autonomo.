<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';
require_once dirname(__DIR__).'/services/planner/Planner.php';
try{
    RBAC::require('tasks.write'); require_post(); $u=Auth::user();
    $d=json_decode(file_get_contents('php://input'),true)?:[];
    $plan=(new Planner())->createPlan(trim((string)($d['goal']??'')));
    $saved=(new Planner())->persist($plan,$u['id']);
    json_response(['success'=>true,'plan'=>$plan,'saved'=>$saved]);
}catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],500);}

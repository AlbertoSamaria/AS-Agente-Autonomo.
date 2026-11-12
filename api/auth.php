<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/auth/Auth.php';

Auth::start();
$action=$_GET['action']??'me';
try {
    if($action==='login'){
        require_post();
        $d=json_decode(file_get_contents('php://input'),true)?:[];
        if(Auth::login(trim((string)($d['email']??'')),(string)($d['password']??''))) json_response(['success'=>true,'user'=>Auth::user()]);
        json_response(['success'=>false,'error'=>'Credenciais inválidas'],401);
    }
    if($action==='logout'){ Auth::logout(); json_response(['success'=>true]); }
    if($action==='me'){ json_response(['success'=>true,'user'=>Auth::user()]); }
    json_response(['success'=>false,'error'=>'Ação inválida'],400);
} catch(Throwable $e){ json_response(['success'=>false,'error'=>$e->getMessage()],500); }

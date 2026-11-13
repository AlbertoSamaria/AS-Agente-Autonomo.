<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';
require_once dirname(__DIR__).'/services/security/RateLimiter.php';

try{
    RBAC::require('rag.write');
    if($_SERVER['REQUEST_METHOD']!=='POST') json_response(['success'=>false,'error'=>'POST necessário'],405);
    Auth::requireCsrf();
    $user=Auth::user();
    if(!(new RateLimiter())->allow('upload',10,3600,RateLimiter::identity((int)$user['id'])))json_response(['success'=>false,'error'=>'Limite de uploads por hora atingido.'],429);
    if(empty($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK) json_response(['success'=>false,'error'=>'Ficheiro inválido'],422);
    $f=$_FILES['file']; $allowed=['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/plain','text/csv'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if(!in_array($mime,$allowed,true)) json_response(['success'=>false,'error'=>'Formato não permitido'],415);
    if($f['size']>20*1024*1024) json_response(['success'=>false,'error'=>'Limite de 20 MB'],413);
    $name=bin2hex(random_bytes(16)).'.'.pathinfo($f['name'],PATHINFO_EXTENSION);
    $uploadDir=dirname(__DIR__).'/uploads';
    if(!is_dir($uploadDir)&&!mkdir($uploadDir,0700,true)&&!is_dir($uploadDir)) {
        throw new RuntimeException('Não foi possível preparar a pasta de uploads.');
    }
    if(!move_uploaded_file($f['tmp_name'],$uploadDir.'/'.$name)) {
        throw new RuntimeException('Não foi possível guardar o ficheiro enviado.');
    }
    $s=db()->prepare("INSERT INTO documents(user_id,original_name,stored_name,mime_type,size_bytes) VALUES(?,?,?,?,?)");
    $s->execute([$user['id'],$f['name'],$name,$mime,$f['size']]);
    $id=(int)db()->lastInsertId();
    json_response(['success'=>true,'document_id'=>$id,'name'=>$f['name'],'status'=>'pending']);
}catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],500);}

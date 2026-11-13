<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';
require_once dirname(__DIR__).'/services/rag/DocumentProcessor.php';
try{
    RBAC::require('rag.write'); require_post(); Auth::requireCsrf();
    $d=json_decode(file_get_contents('php://input'),true)?:[];
    $user=Auth::user();
    $documentId=(int)($d['document_id']??0);
    $owner=db()->prepare('SELECT user_id FROM documents WHERE id=? LIMIT 1');
    $owner->execute([$documentId]);
    $documentUserId=$owner->fetchColumn();
    if($documentUserId===false) json_response(['success'=>false,'error'=>'Documento inexistente.'],404);
    if($user['role']!=='admin'&&(int)$documentUserId!==(int)$user['id']) {
        json_response(['success'=>false,'error'=>'Não tens acesso a este documento.'],403);
    }
    json_response((new DocumentProcessor())->process($documentId));
}catch(Throwable $e){json_response(['success'=>false,'error'=>$e->getMessage()],500);}

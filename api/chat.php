<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';
require_once dirname(__DIR__).'/services/agent/Agent.php';
require_once dirname(__DIR__).'/services/security/RateLimiter.php';

require_post(); Auth::requireCsrf(); $u=Auth::requireLogin(); RBAC::require('chat');
if(!(new RateLimiter())->allow('chat',30,60,RateLimiter::identity((int)$u['id'])))json_response(['success'=>false,'error'=>'Limite de mensagens atingido. Tenta novamente dentro de um minuto.'],429);
$d=json_decode(file_get_contents('php://input'),true)?:[];$input=trim((string)($d['message']??''));$cid=(int)($d['conversation_id']??0);
if($input==='')json_response(['success'=>false,'error'=>'Mensagem vazia'],422);
if(mb_strlen($input)>12000)json_response(['success'=>false,'error'=>'A mensagem excede o limite de 12000 caracteres.'],422);
if($cid<=0){$s=db()->prepare("INSERT INTO conversations(user_id,title) VALUES(?,?)");$s->execute([$u['id'],mb_substr($input,0,60)]);$cid=(int)db()->lastInsertId();}
else{
    $owner=db()->prepare('SELECT id FROM conversations WHERE id=? AND user_id=? LIMIT 1');
    $owner->execute([$cid,(int)$u['id']]);
    if(!$owner->fetchColumn())json_response(['success'=>false,'error'=>'Conversa não encontrada.'],404);
}
$ids=$d['document_ids']??[];
if(!is_array($ids))json_response(['success'=>false,'error'=>'Lista de documentos inválida'],422);
$documentIds=array_values(array_unique(array_filter(array_map('intval',$ids),static fn(int $id): bool=>$id>0)));
if($documentIds!==[]){
    RBAC::require('rag.read');
    $placeholders=implode(',',array_fill(0,count($documentIds),'?'));
    $stmt=db()->prepare("SELECT id FROM documents WHERE user_id=? AND status='ready' AND id IN ($placeholders)");
    $stmt->execute(array_merge([(int)$u['id']],$documentIds));
    $validIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    if(count($validIds)!==count($documentIds))json_response(['success'=>false,'error'=>'Um ou mais ficheiros não estão disponíveis na tua biblioteca.'],403);
    $documentIds=$validIds;
}
$agent=new Agent();$agent->saveMessage($cid,'user',$input);$r=$agent->run($input,$cid,(int)$u['id'],$documentIds);if($r['success']&&!isset($r['approval_id']))$agent->saveMessage($cid,'assistant',$r['content']);
json_response(['success'=>$r['success'],'conversation_id'=>$cid,'message'=>$r['content'],'error'=>$r['error']??null,'approval_id'=>$r['approval_id']??null]);

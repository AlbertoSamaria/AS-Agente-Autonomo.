<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';

try {
    RBAC::require('rag.read');
    $user=Auth::user();
    $stmt=db()->prepare(
        "SELECT id,original_name,status,size_bytes
         FROM documents
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 100"
    );
    $stmt->execute([$user['id']]);
    json_response(['success'=>true,'documents'=>$stmt->fetchAll()]);
} catch(Throwable $e) {
    json_response(['success'=>false,'error'=>$e->getMessage()],500);
}

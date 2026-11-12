<?php
declare(strict_types=1);
final class SqlTool {
    public function read(string $sql): array {
        $sql=trim($sql);
        if(!preg_match('/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i',$sql)) return ['success'=>false,'error'=>'Apenas SQL de leitura é permitido.'];
        if(preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|TRUNCATE|CREATE|GRANT|REVOKE)\b/i',$sql)) return ['success'=>false,'error'=>'Operação bloqueada.'];
        $stmt=db()->query($sql); return ['success'=>true,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
}

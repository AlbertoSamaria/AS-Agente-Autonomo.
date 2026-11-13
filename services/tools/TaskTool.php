<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/config/config.php';

final class TaskTool {
    public function create(array $args, int $userId): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Utilizador inválido para criar tarefa.');
        }
        $title=trim((string)($args['title']??''));
        if ($title==='') return ['success'=>false,'error'=>'Título obrigatório'];
        $priority=(string)($args['priority']??'medium');
        if (!in_array($priority,['low','medium','high','critical'],true)) $priority='medium';
        $due=$args['due_at']??null;
        $stmt=db()->prepare("INSERT INTO tasks(user_id,title,description,priority,due_at) VALUES(?,?,?,?,?)");
        $stmt->execute([$userId,$title,$args['description']??null,$priority,$due]);
        return ['success'=>true,'task_id'=>(int)db()->lastInsertId(),'title'=>$title];
    }

    public function list(array $args, int $userId): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Utilizador inválido para consultar tarefas.');
        }
        $status=$args['status']??null;
        if ($status && in_array($status,['pending','in_progress','completed','cancelled'],true)) {
            $stmt=db()->prepare("SELECT * FROM tasks WHERE user_id=? AND status=? ORDER BY FIELD(priority,'critical','high','medium','low'),due_at IS NULL,due_at LIMIT 30");
            $stmt->execute([$userId,$status]);
        } else {
            $stmt=db()->prepare("SELECT * FROM tasks WHERE user_id=? ORDER BY FIELD(priority,'critical','high','medium','low'),due_at IS NULL,due_at LIMIT 30");
            $stmt->execute([$userId]);
        }
        return ['success'=>true,'tasks'=>$stmt->fetchAll()];
    }

    public function complete(array $args, int $userId): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Utilizador inválido para concluir tarefa.');
        }
        $id=(int)($args['task_id']??0);
        $stmt=db()->prepare("UPDATE tasks SET status='completed' WHERE id=? AND user_id=?");
        $stmt->execute([$id,$userId]);
        return ['success'=>$stmt->rowCount()>0,'task_id'=>$id];
    }
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/config.php';

final class ApprovalService {
    public function create(int $userId,?int $runId,string $action,string $tool,array $args,string $reason): int {
        $s=db()->prepare("INSERT INTO approvals(user_id,agent_run_id,action_type,tool_name,arguments_json,reason) VALUES(?,?,?,?,?,?)");
        $s->execute([$userId,$runId,$action,$tool,json_encode($args,JSON_UNESCAPED_UNICODE),$reason]);
        return (int)db()->lastInsertId();
    }
    public function isApproved(int $id): bool {
        $s=db()->prepare("SELECT status FROM approvals WHERE id=?");$s->execute([$id]);
        return $s->fetchColumn()==='approved';
    }
}

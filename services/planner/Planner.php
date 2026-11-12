<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config/config.php';
require_once dirname(__DIR__).'/router/ModelRouter.php';

final class Planner {
    public function createPlan(string $goal): array {
        $prompt='Cria um plano executável em JSON para este objetivo. Retorna SOMENTE JSON com {"goal":"...","tasks":[{"title":"...","description":"...","priority":"low|medium|high|critical","depends_on":[]}]}. Objetivo: '.$goal;
        $r=(new ModelRouter())->chat([['role'=>'system','content'=>'És um planner de agente. Nunca inventes dados.'],['role'=>'user','content'=>$prompt]],'reasoning');
        $data=json_decode(trim((string)($r['message']['content']??'')),true);
        if(!is_array($data)||!isset($data['tasks'])) throw new RuntimeException('Planner não devolveu JSON válido.');
        return $data;
    }
    public function persist(array $plan,?int $userId=null): array {
        $pdo=db(); $ids=[];
        foreach($plan['tasks'] as $i=>$t){
            $s=$pdo->prepare("INSERT INTO tasks(user_id,title,description,priority) VALUES(?,?,?,?)");
            $s->execute([$userId,$t['title'],$t['description']??null,$t['priority']??'medium']);
            $ids[$i]=(int)$pdo->lastInsertId();
        }
        foreach($plan['tasks'] as $i=>$t){
            foreach(($t['depends_on']??[]) as $depIndex){
                if(isset($ids[(int)$depIndex]) && $ids[$i]!==$ids[(int)$depIndex]){
                    $pdo->prepare("INSERT IGNORE INTO task_dependencies(task_id,depends_on_task_id) VALUES(?,?)")->execute([$ids[$i],$ids[(int)$depIndex]]);
                }
            }
        }
        return ['task_ids'=>$ids];
    }
}

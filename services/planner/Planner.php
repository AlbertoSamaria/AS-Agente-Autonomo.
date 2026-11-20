<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/router/ModelRouter.php';

final class Planner
{
    public function createPlan(string $goal): array
    {
        $goal = trim($goal);
        if ($goal === '' || mb_strlen($goal) > 4000) {
            throw new InvalidArgumentException('O objetivo deve ter entre 1 e 4000 caracteres.');
        }

        $prompt = 'Cria um plano executável em JSON. Retorna SOMENTE JSON com {"goal":"...","tasks":[{"title":"...","description":"...","priority":"low|medium|high|critical","depends_on":[]}]}. depends_on usa índices começando em 0. Máximo 30 tarefas. Objetivo: ' . $goal;
        $response = (new ModelRouter())->chat([
            ['role' => 'system', 'content' => 'És um planner. Produz tarefas concretas, ordenadas e sem dependências circulares.'],
            ['role' => 'user', 'content' => $prompt],
        ], 'reasoning');

        $content = trim((string) ($response['message']['content'] ?? ''));
        if (preg_match('/^\x60{3}(?:json)?\s*(.*?)\s*\x60{3}$/is', $content, $match)) {
            $content = trim($match[1]);
        }
        $plan = json_decode($content, true);
        if (!is_array($plan) || !is_array($plan['tasks'] ?? null)) {
            throw new RuntimeException('Planner não devolveu um plano JSON válido.');
        }
        $plan['goal'] = trim((string) ($plan['goal'] ?? $goal));
        $plan['tasks'] = self::validateTasks($plan['tasks']);
        return $plan;
    }

    public function persist(array $plan, ?int $userId = null): array
    {
        if ($userId === null || $userId <= 0) {
            throw new InvalidArgumentException('É necessário identificar o utilizador do plano.');
        }
        $goal = trim((string) ($plan['goal'] ?? ''));
        if ($goal === '' || mb_strlen($goal) > 4000) {
            throw new InvalidArgumentException('O objetivo do plano é inválido.');
        }
        $tasks = self::validateTasks($plan['tasks'] ?? null);
        $pdo = db();
        $taskIds = [];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO agent_plans(user_id,goal,status) VALUES(?,?,?)');
            $stmt->execute([$userId, $goal, 'pending']);
            $planId = (int) $pdo->lastInsertId();

            $insertTask = $pdo->prepare('INSERT INTO tasks(user_id,title,description,priority) VALUES(?,?,?,?)');
            foreach ($tasks as $index => $task) {
                $insertTask->execute([$userId, $task['title'], $task['description'], $task['priority']]);
                $taskIds[$index] = (int) $pdo->lastInsertId();
            }

            $insertStep = $pdo->prepare('INSERT INTO agent_plan_steps(plan_id,task_id,step_index,status,max_attempts) VALUES(?,?,?,?,?)');
            $insertDependency = $pdo->prepare('INSERT INTO task_dependencies(task_id,depends_on_task_id) VALUES(?,?)');
            foreach ($tasks as $index => $task) {
                $insertStep->execute([$planId, $taskIds[$index], $index, 'pending', 3]);
                foreach ($task['depends_on'] as $dependencyIndex) {
                    $insertDependency->execute([$taskIds[$index], $taskIds[$dependencyIndex]]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        return ['plan_id' => $planId, 'task_ids' => $taskIds, 'status' => 'pending'];
    }

    public function createAndPersist(string $goal, int $userId): array
    {
        $plan = $this->createPlan($goal);
        return ['success' => true, 'plan' => $plan, 'saved' => $this->persist($plan, $userId)];
    }

    public function listPlans(int $userId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = db()->prepare('SELECT p.id,p.goal,p.status,p.created_at,p.updated_at,COUNT(s.id) AS total_steps,SUM(s.status = "completed") AS completed_steps,SUM(s.status = "failed") AS failed_steps FROM agent_plans p LEFT JOIN agent_plan_steps s ON s.plan_id=p.id WHERE p.user_id=? GROUP BY p.id ORDER BY p.updated_at DESC LIMIT ' . $limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function getPlan(int $planId, int $userId): ?array
    {
        $stmt = db()->prepare('SELECT id,goal,status,created_at,updated_at FROM agent_plans WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$planId, $userId]);
        $plan = $stmt->fetch();
        if (!$plan) return null;

        $steps = db()->prepare('SELECT s.id,s.task_id,s.step_index,s.status,s.attempt_count,s.max_attempts,s.output,s.error_message,s.started_at,s.completed_at,t.title,t.description,t.priority FROM agent_plan_steps s INNER JOIN tasks t ON t.id=s.task_id WHERE s.plan_id=? ORDER BY s.step_index');
        $steps->execute([$planId]);
        $plan['steps'] = $steps->fetchAll();
        foreach ($plan['steps'] as &$step) {
            $deps = db()->prepare('SELECT d.depends_on_task_id FROM task_dependencies d WHERE d.task_id=? ORDER BY d.depends_on_task_id');
            $deps->execute([(int) $step['task_id']]);
            $step['depends_on_task_ids'] = array_map('intval', $deps->fetchAll(PDO::FETCH_COLUMN));
        }
        unset($step);
        return $plan;
    }

    public function changeStatus(int $planId, int $userId, string $action): bool
    {
        $allowed = ['pause' => ['paused', ['pending','running']], 'resume' => ['pending', ['paused','failed']], 'cancel' => ['cancelled', ['pending','running','paused','failed']]];
        if (!isset($allowed[$action])) throw new InvalidArgumentException('Acção de plano inválida.');
        [$newStatus, $from] = $allowed[$action];
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $stmt = db()->prepare('UPDATE agent_plans SET status=? WHERE id=? AND user_id=? AND status IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$newStatus, $planId, $userId], $from));
        return $stmt->rowCount() === 1;
    }

    public function retryStep(int $stepId, int $userId): bool
    {
        $stmt = db()->prepare("UPDATE agent_plan_steps s INNER JOIN agent_plans p ON p.id=s.plan_id SET s.status='pending',s.error_message=NULL,s.output=NULL,s.started_at=NULL,s.completed_at=NULL,p.status='pending' WHERE s.id=? AND p.user_id=? AND s.status='failed' AND s.attempt_count < s.max_attempts AND p.status NOT IN ('cancelled','paused')");
        $stmt->execute([$stepId, $userId]);
        return $stmt->rowCount() === 1;
    }

    public static function validateTasks(mixed $tasks): array
    {
        if (!is_array($tasks) || $tasks === [] || count($tasks) > 30) {
            throw new InvalidArgumentException('O plano deve conter entre 1 e 30 tarefas.');
        }
        $validated = [];
        foreach (array_values($tasks) as $index => $task) {
            if (!is_array($task)) throw new InvalidArgumentException('O plano contém uma tarefa inválida.');
            $title = trim((string) ($task['title'] ?? ''));
            $description = trim((string) ($task['description'] ?? ''));
            $priority = (string) ($task['priority'] ?? 'medium');
            $dependencies = $task['depends_on'] ?? [];
            if ($title === '' || mb_strlen($title) > 255 || mb_strlen($description) > 4000) throw new InvalidArgumentException('Título ou descrição de tarefa inválidos.');
            if (!in_array($priority, ['low','medium','high','critical'], true)) throw new InvalidArgumentException('Prioridade de tarefa inválida.');
            if (!is_array($dependencies)) throw new InvalidArgumentException('Dependências de tarefa inválidas.');
            $deps = [];
            foreach ($dependencies as $dependency) {
                if ((!is_int($dependency) && !(is_string($dependency) && ctype_digit($dependency))) || (int) $dependency < 0 || (int) $dependency >= count($tasks) || (int) $dependency === $index) {
                    throw new InvalidArgumentException('O plano contém uma dependência inválida.');
                }
                $deps[] = (int) $dependency;
            }
            $validated[] = ['title'=>$title,'description'=>$description,'priority'=>$priority,'depends_on'=>array_values(array_unique($deps))];
        }
        self::assertAcyclic($validated);
        return $validated;
    }

    private static function assertAcyclic(array $tasks): void
    {
        $states = array_fill(0, count($tasks), 0);
        $visit = function (int $index) use (&$visit, &$states, $tasks): void {
            if ($states[$index] === 1) throw new InvalidArgumentException('O plano contém dependências circulares.');
            if ($states[$index] === 2) return;
            $states[$index] = 1;
            foreach ($tasks[$index]['depends_on'] as $dependency) $visit($dependency);
            $states[$index] = 2;
        };
        foreach (array_keys($tasks) as $index) $visit($index);
    }
}

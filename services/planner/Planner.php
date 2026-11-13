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

        $prompt = 'Cria um plano executável em JSON para este objetivo. ' .
            'Retorna SOMENTE JSON com {"goal":"...","tasks":' .
            '[{"title":"...","description":"...","priority":"low|medium|high|critical",' .
            '"depends_on":[]}]}. depends_on usa índices de tarefas começando em 0. ' .
            'Máximo 30 tarefas. Objetivo: ' . $goal;
        $response = (new ModelRouter())->chat([
            ['role' => 'system', 'content' => 'És um planner. Produz tarefas concretas, ordenadas e sem dependências circulares.'],
            ['role' => 'user', 'content' => $prompt],
        ], 'reasoning');

        $content = trim((string) ($response['message']['content'] ?? ''));
        if (str_starts_with($content, '```') && str_ends_with($content, '```')) {
            $content = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content));
        }

        $plan = json_decode($content, true);
        if (!is_array($plan) || !is_array($plan['tasks'] ?? null)) {
            throw new RuntimeException('Planner não devolveu um plano JSON válido.');
        }
        $plan['goal'] = trim((string) ($plan['goal'] ?? $goal));
        $plan['tasks'] = $this->validateTasks($plan['tasks']);
        return $plan;
    }

    public function persist(array $plan, ?int $userId = null): array
    {
        if ($userId === null || $userId <= 0) {
            throw new InvalidArgumentException('É necessário identificar o utilizador do plano.');
        }
        $tasks = $this->validateTasks($plan['tasks'] ?? null);
        $pdo = db();
        $ids = [];

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO tasks(user_id,title,description,priority) VALUES(?,?,?,?)'
            );
            foreach ($tasks as $index => $task) {
                $insert->execute([
                    $userId,
                    $task['title'],
                    $task['description'],
                    $task['priority'],
                ]);
                $ids[$index] = (int) $pdo->lastInsertId();
            }

            $dependency = $pdo->prepare(
                'INSERT INTO task_dependencies(task_id,depends_on_task_id) VALUES(?,?)'
            );
            foreach ($tasks as $index => $task) {
                foreach ($task['depends_on'] as $dependencyIndex) {
                    $dependency->execute([
                        $ids[$index],
                        $ids[$dependencyIndex],
                    ]);
                }
            }

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return ['task_ids' => $ids];
    }

    public function createAndPersist(string $goal, int $userId): array
    {
        $plan = $this->createPlan($goal);
        $saved = $this->persist($plan, $userId);
        return ['success' => true, 'plan' => $plan, 'saved' => $saved];
    }

    private function validateTasks(mixed $tasks): array
    {
        if (!is_array($tasks) || $tasks === [] || count($tasks) > 30) {
            throw new InvalidArgumentException('O plano deve conter entre 1 e 30 tarefas.');
        }

        $validated = [];
        foreach (array_values($tasks) as $index => $task) {
            if (!is_array($task)) {
                throw new InvalidArgumentException('O plano contém uma tarefa inválida.');
            }

            $title = trim((string) ($task['title'] ?? ''));
            $description = trim((string) ($task['description'] ?? ''));
            $priority = (string) ($task['priority'] ?? 'medium');
            $dependencies = $task['depends_on'] ?? [];
            if ($title === '' || mb_strlen($title) > 255 || mb_strlen($description) > 4000) {
                throw new InvalidArgumentException('Título ou descrição de tarefa inválidos.');
            }
            if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
                throw new InvalidArgumentException('Prioridade de tarefa inválida.');
            }
            if (!is_array($dependencies)) {
                throw new InvalidArgumentException('Dependências de tarefa inválidas.');
            }

            $validatedDependencies = [];
            foreach ($dependencies as $dependency) {
                if (
                    !is_int($dependency)
                    && !(is_string($dependency) && ctype_digit($dependency))
                ) {
                    throw new InvalidArgumentException('Índice de dependência inválido.');
                }
                $dependencyIndex = (int) $dependency;
                if (
                    $dependencyIndex < 0
                    || $dependencyIndex >= count($tasks)
                    || $dependencyIndex === $index
                ) {
                    throw new InvalidArgumentException('O plano contém uma dependência inválida.');
                }
                $validatedDependencies[] = $dependencyIndex;
            }

            $validated[] = [
                'title' => $title,
                'description' => $description,
                'priority' => $priority,
                'depends_on' => array_values(array_unique($validatedDependencies)),
            ];
        }

        $this->assertAcyclic($validated);
        return $validated;
    }

    private function assertAcyclic(array $tasks): void
    {
        $states = array_fill(0, count($tasks), 0);
        $visit = function (int $index) use (&$visit, &$states, $tasks): void {
            if ($states[$index] === 1) {
                throw new InvalidArgumentException('O plano contém dependências circulares.');
            }
            if ($states[$index] === 2) {
                return;
            }

            $states[$index] = 1;
            foreach ($tasks[$index]['depends_on'] as $dependency) {
                $visit($dependency);
            }
            $states[$index] = 2;
        };

        foreach (array_keys($tasks) as $index) {
            $visit($index);
        }
    }
}

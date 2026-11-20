<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/middleware/RBAC.php';
require_once dirname(__DIR__).'/services/planner/Planner.php';
require_once dirname(__DIR__).'/services/security/RateLimiter.php';

try {
    $user = Auth::requireLogin();
    $planner = new Planner();
    $userId = (int) $user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        RBAC::require('tasks.read');
        if (isset($_GET['id'])) {
            $plan = $planner->getPlan((int) $_GET['id'], $userId);
            if (!$plan) json_response(['success'=>false,'error'=>'Plano não encontrado.'],404);
            json_response(['success'=>true,'plan'=>$plan]);
        }
        json_response(['success'=>true,'plans'=>$planner->listPlans($userId,(int)($_GET['limit']??30))]);
    }

    RBAC::require('tasks.write');
    require_post();
    Auth::requireCsrf();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($data['action'] ?? 'create');

    if ($action === 'create') {
        if (!(new RateLimiter())->allow('planner',10,60,RateLimiter::identity($userId))) {
            json_response(['success'=>false,'error'=>'Limite de pedidos ao planner atingido. Tenta novamente dentro de um minuto.'],429);
        }
        $goal = trim((string)($data['goal'] ?? ''));
        $result = $planner->createAndPersist($goal, $userId);
        json_response($result,201);
    }
    if ($action === 'pause' || $action === 'resume' || $action === 'cancel') {
        $changed = $planner->changeStatus((int)($data['plan_id']??0),$userId,$action);
        if (!$changed) json_response(['success'=>false,'error'=>'Plano não encontrado ou transição de estado não permitida.'],409);
        json_response(['success'=>true,'action'=>$action]);
    }
    if ($action === 'retry_step') {
        $changed = $planner->retryStep((int)($data['step_id']??0),$userId);
        if (!$changed) json_response(['success'=>false,'error'=>'Etapa não pode ser retomada (verifica o estado e o limite de tentativas).'],409);
        json_response(['success'=>true,'status'=>'pending']);
    }
    json_response(['success'=>false,'error'=>'Acção inválida.'],400);
} catch (InvalidArgumentException $e) {
    json_response(['success'=>false,'error'=>$e->getMessage()],422);
} catch (Throwable $e) {
    error_log('[SA Planner] '.$e->getMessage());
    json_response(['success'=>false,'error'=>'Não foi possível processar o plano. Verifica a configuração e a migração da base de dados.'],500);
}

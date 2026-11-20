<?php
declare(strict_types=1);

// Worker exclusivamente CLI: não pode ser invocado por um pedido HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once dirname(__DIR__) . '/services/planner/PlanWorker.php';

try {
    $maxSteps = isset($argv[1]) ? (int) $argv[1] : 3;
    $result = (new PlanWorker())->runBatch($maxSteps);
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    error_log('[SA PlanWorker CLI] ' . $error->getMessage());
    fwrite(STDERR, "Worker falhou. Consulta o log do PHP.\n");
    exit(1);
}

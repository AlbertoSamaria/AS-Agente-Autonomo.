<?php
declare(strict_types=1);

/**
 * Lightweight regression tests for the SA core.
 * These tests do not connect to MySQL or external AI providers.
 */

require_once dirname(__DIR__) . '/services/agent/ContextManager.php';
require_once dirname(__DIR__) . '/services/tools/ToolRegistry.php';
require_once dirname(__DIR__) . '/services/agent/Orchestrator.php';
require_once dirname(__DIR__) . '/services/security/RateLimiter.php';

$passed = 0;
$failed = 0;

function test(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;
        fwrite(STDOUT, "[PASS] {$name}\n");
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "[FAIL] {$name}: {$error->getMessage()}\n");
    }
}

function same(mixed $expected, mixed $actual, string $message = 'Valores diferentes'): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '; esperado ' . var_export($expected, true) .
            ', recebido ' . var_export($actual, true));
    }
}

function truthy(bool $condition, string $message = 'Condição não satisfeita'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throws(callable $callback, string $class): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new RuntimeException('Excepção inesperada: ' . get_class($error));
    }
    throw new RuntimeException('Era esperada a excepção ' . $class);
}

test('ContextManager mantém a mensagem de sistema e a mensagem mais recente', function (): void {
    $result = (new ContextManager())->fit([
        ['role' => 'system', 'content' => 'Instruções principais'],
        ['role' => 'user', 'content' => 'Mensagem antiga'],
        ['role' => 'assistant', 'content' => 'Resposta antiga'],
        ['role' => 'user', 'content' => 'Pedido actual'],
    ]);

    same('system', $result[0]['role']);
    same('Pedido actual', $result[count($result) - 1]['content']);
});

test('ContextManager remove mensagens com estrutura ou papel inválido', function (): void {
    $result = (new ContextManager())->fit([
        ['role' => 'system', 'content' => 'Sistema'],
        ['role' => 'developer', 'content' => 'Papel não suportado'],
        ['role' => 'user', 'content' => 'Pedido válido'],
        ['role' => 'assistant'],
    ]);

    same(2, count($result));
    same('Pedido válido', $result[1]['content']);
});

test('ContextManager rejeita uma conversa sem mensagens utilizáveis', function (): void {
    throws(
        static fn() => (new ContextManager())->fit([
            ['role' => 'developer', 'content' => 'Inválida'],
        ]),
        InvalidArgumentException::class
    );
});

test('ToolRegistry permite apenas ferramentas registadas', function (): void {
    $registry = new ToolRegistry();
    truthy($registry->isAllowed('task.list'));
    truthy($registry->isAllowed('system.health'));
    same(false, $registry->isAllowed('system.shell'));
});

test('ToolRegistry aplica permissões e classificação de operações sensíveis', function (): void {
    $registry = new ToolRegistry();
    same('admin.system', $registry->permission('system.health'));
    same('tools.files', $registry->permission('code.file.write'));
    same(true, $registry->sensitive('code.workspace.restore'));
    same(false, $registry->sensitive('task.list'));
});

test('Orchestrator aceita pedidos JSON válidos de ferramentas autorizadas', function (): void {
    $orchestrator = new Orchestrator(new ToolRegistry());
    $method = new ReflectionMethod(Orchestrator::class, 'parseRequest');
    $result = $method->invoke($orchestrator, '{"tool":"task.list","arguments":{"limit":5}}');

    same('task.list', $result['tool'] ?? null);
    same(['limit' => 5], $result['arguments'] ?? null);
});

test('Orchestrator rejeita ferramentas não autorizadas e JSON inválido', function (): void {
    $orchestrator = new Orchestrator(new ToolRegistry());
    $method = new ReflectionMethod(Orchestrator::class, 'parseRequest');

    same(null, $method->invoke($orchestrator, '{"tool":"system.shell","arguments":{}}'));
    same(null, $method->invoke($orchestrator, 'isto não é JSON'));
    same(null, $method->invoke($orchestrator, " ` ` `json\n{\"tool\":\"task.list\",\"arguments\":{}}\n` ` `"));
});

test('RateLimiter cria identidades estáveis por utilizador', function (): void {
    same('user:42', RateLimiter::identity(42));
    same('user:42', RateLimiter::identity(42));
});

test('RateLimiter usa identidade de IP anonimizada para visitantes', function (): void {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $identity = RateLimiter::identity(null);

    truthy(str_starts_with($identity, 'ip:'));
    same(67, strlen($identity));
    truthy(!str_contains($identity, '203.0.113.10'));
});


require_once dirname(__DIR__) . '/services/planner/Planner.php';
require_once dirname(__DIR__) . '/services/planner/PlanWorker.php';

test('Planner valida tarefas e preserva dependências válidas', function (): void {
    $tasks = Planner::validateTasks([
        ['title'=>'Preparar ambiente','description'=>'','priority'=>'high','depends_on'=>[]],
        ['title'=>'Executar testes','description'=>'','priority'=>'medium','depends_on'=>[0]],
    ]);
    same([0], $tasks[1]['depends_on']);
    same('Preparar ambiente', $tasks[0]['title']);
});

test('Planner rejeita dependências circulares e referências inválidas', function (): void {
    throws(static fn() => Planner::validateTasks([
        ['title'=>'A','depends_on'=>[1]],
        ['title'=>'B','depends_on'=>[0]],
    ]), InvalidArgumentException::class);
    throws(static fn() => Planner::validateTasks([
        ['title'=>'A','depends_on'=>[4]],
    ]), InvalidArgumentException::class);
});

test('Planner rejeita planos vazios e tarefas sem título', function (): void {
    throws(static fn() => Planner::validateTasks([]), InvalidArgumentException::class);
    throws(static fn() => Planner::validateTasks([['title'=>'']]), InvalidArgumentException::class);
});

test('PlanWorker limita o tamanho dos resultados', function (): void {
    $output = PlanWorker::limitOutput(str_repeat('x', 21000));
    truthy(mb_strlen($output) < 20500);
    truthy(str_contains($output, 'truncado'));
});

fwrite(STDOUT, "\nResultado: {$passed} passaram; {$failed} falharam.\n");
exit($failed === 0 ? 0 : 1);

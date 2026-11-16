<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/TaskTool.php';
require_once __DIR__ . '/WebTool.php';
require_once __DIR__ . '/FileTool.php';
require_once __DIR__ . '/SqlTool.php';
require_once __DIR__ . '/CodeWorkspaceTool.php';
require_once __DIR__ . '/ProjectAnalyzer.php';
require_once dirname(__DIR__) . '/planner/Planner.php';

final class ToolRegistry
{
    private const ALLOWED_TOOLS = [
        'task.create',
        'task.list',
        'task.complete',
        'plan.create',
        'web.get',
        'web.search',
        'files.list',
        'sql.read',
        'code.project.create',
        'code.workspace.list',
        'code.file.read',
        'code.file.write',
        'code.php.lint',
        'code.project.analyze',
        'code.workspace.snapshot',
        'code.workspace.diff',
        'code.workspace.snapshots',
        'code.workspace.restore',
    ];

    public function isAllowed(string $tool): bool
    {
        return in_array($tool, self::ALLOWED_TOOLS, true);
    }

    public function execute(string $tool, array $args, int $userId): array
    {
        if (!$this->isAllowed($tool)) {
            return ['success' => false, 'error' => 'Ferramenta não autorizada.'];
        }

        $code = new CodeWorkspaceTool();

        return match ($tool) {
            'task.create' => (new TaskTool())->create($args, $userId),
            'task.list' => (new TaskTool())->list($args, $userId),
            'task.complete' => (new TaskTool())->complete($args, $userId),
            'plan.create' => (new Planner())->createAndPersist((string) ($args['goal'] ?? ''), $userId),
            'web.get' => (new WebTool())->get((string) ($args['url'] ?? '')),
            'web.search' => (new WebTool())->search((string) ($args['query'] ?? '')),
            'files.list' => (new FileTool())->listUploads($userId),
            'sql.read' => (new SqlTool())->read((string) ($args['sql'] ?? '')),
            'code.project.create' => $code->createProject($args, $userId),
            'code.workspace.list' => $code->list($args, $userId),
            'code.file.read' => $code->read($args, $userId),
            'code.file.write' => $code->write($args, $userId),
            'code.php.lint' => $code->phpLint($args, $userId),
            'code.project.analyze' => (new ProjectAnalyzer())->analyze($args, $userId),
            'code.workspace.snapshot' => $code->snapshot($args, $userId),
            'code.workspace.diff' => $code->diffSnapshot($args, $userId),
            'code.workspace.snapshots' => $code->listSnapshots($args, $userId),
            'code.workspace.restore' => $code->restoreSnapshot($args, $userId),
            default => ['success' => false, 'error' => 'Ferramenta não autorizada.'],
        };
    }

    public function permission(string $tool): string
    {
        return match ($tool) {
            'task.create', 'task.complete', 'plan.create' => 'tasks.write',
            'task.list' => 'tasks.read',
            'web.get', 'web.search' => 'tools.web',
            'files.list' => 'tools.files',
            'sql.read' => 'tools.sql.read',
            'code.project.create', 'code.workspace.list', 'code.file.read',
            'code.file.write', 'code.php.lint', 'code.project.analyze',
            'code.workspace.snapshot', 'code.workspace.diff', 'code.workspace.snapshots', 'code.workspace.restore' => 'tools.files',
            default => 'chat',
        };
    }

    public function sensitive(string $tool): bool
    {
        // SQL permanece sujeito a aprovação. Escritas de código ficam confinadas
        // ao workspace privado do utilizador, fora da raiz pública do Apache.
        return in_array($tool, ['sql.read', 'code.workspace.restore'], true);
    }
}

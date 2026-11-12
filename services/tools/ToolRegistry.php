<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/TaskTool.php';
require_once __DIR__ . '/WebTool.php';
require_once __DIR__ . '/FileTool.php';
require_once __DIR__ . '/SqlTool.php';

final class ToolRegistry
{
    private const ALLOWED_TOOLS = [
        'task.create',
        'task.list',
        'task.complete',
        'web.get',
        'web.search',
        'files.list',
        'sql.read',
    ];

    public function isAllowed(string $tool): bool
    {
        return in_array($tool, self::ALLOWED_TOOLS, true);
    }

    public function execute(string $tool, array $args): array
    {
        if (!$this->isAllowed($tool)) {
            return [
                'success' => false,
                'error' => 'Ferramenta não autorizada.',
            ];
        }

        return match ($tool) {
            'task.create' => (new TaskTool())->create($args),
            'task.list' => (new TaskTool())->list($args),
            'task.complete' => (new TaskTool())->complete($args),
            'web.get' => (new WebTool())->get((string) ($args['url'] ?? '')),
            'web.search' => (new WebTool())->search((string) ($args['query'] ?? '')),
            'files.list' => (new FileTool())->listUploads(),
            'sql.read' => (new SqlTool())->read((string) ($args['sql'] ?? '')),
            default => [
                'success' => false,
                'error' => 'Ferramenta não autorizada.',
            ],
        };
    }

    public function permission(string $tool): string
    {
        return match ($tool) {
            'task.create', 'task.complete' => 'tasks.write',
            'task.list' => 'tasks.read',
            'web.get', 'web.search' => 'tools.web',
            'files.list' => 'tools.files',
            'sql.read' => 'tools.sql.read',
            default => 'chat',
        };
    }

    public function sensitive(string $tool): bool
    {
        /*
         * A pesquisa na Internet é uma operação de leitura.
         * Não exige aprovação humana por defeito.
         *
         * A leitura directa da base de dados continua protegida
         * porque pode expor informação privada.
         */
        return in_array($tool, [
            'sql.read',
        ], true);
    }
}

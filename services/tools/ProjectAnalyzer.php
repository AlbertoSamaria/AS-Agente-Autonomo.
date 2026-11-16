<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

/** Análise estática, read-only, de projectos no workspace privado do utilizador. */
final class ProjectAnalyzer
{
    private const MAX_FILES = 300;
    private const MAX_FILE_BYTES = 262144;
    private const MAX_TOTAL_BYTES = 5242880;

    private function root(int $userId): string
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Utilizador inválido.');
        }
        $root = dirname(__DIR__, 2) . '/storage/workspaces/' . $userId;
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('Workspace não encontrado. Cria primeiro ou envia um projecto para o workspace.');
        }
        return $real;
    }

    public function analyze(array $args, int $userId): array
    {
        $project = trim(str_replace('\\', '/', (string)($args['path'] ?? '')));
        if ($project === '' || str_starts_with($project, '/') || preg_match('/^[A-Za-z]:/', $project) || preg_match('#(^|/)\.\.(/|$)#', $project)) {
            return ['success' => false, 'error' => 'Indica um caminho relativo válido dentro do workspace.'];
        }
        $root = $this->root($userId);
        $target = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $project));
        if ($target === false || !is_dir($target) || !str_starts_with($target . DIRECTORY_SEPARATOR, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return ['success' => false, 'error' => 'Projecto não encontrado ou fora do workspace.'];
        }

        $extensions = [];
        $files = [];
        $frameworks = [];
        $findings = [];
        $totalBytes = 0;
        $scanned = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) continue;
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($target) + 1));
            $parts = explode('/', $relative);
            if (array_intersect(array_map('strtolower', $parts), ['.git', 'node_modules', 'vendor', 'storage', 'uploads', 'dist', 'build'])) continue;
            if (++$scanned > self::MAX_FILES) {
                $findings[] = ['severity' => 'warning', 'file' => $relative, 'message' => 'Limite de 300 ficheiros atingido; análise parcial.'];
                break;
            }
            $size = $item->getSize();
            if ($size > self::MAX_FILE_BYTES || ($totalBytes + $size) > self::MAX_TOTAL_BYTES) continue;
            $totalBytes += $size;
            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION) ?: '[sem extensão]');
            $extensions[$ext] = ($extensions[$ext] ?? 0) + 1;
            $files[] = $relative;
            $base = strtolower(basename($relative));
            if (in_array($base, ['composer.json', 'artisan'], true)) $frameworks['Laravel/PHP Composer'] = true;
            if ($base === 'package.json') $frameworks['Node.js'] = true;
            if ($base === 'vite.config.js' || $base === 'vite.config.ts') $frameworks['Vite'] = true;
            if ($base === 'next.config.js' || $base === 'next.config.ts' || $base === 'next.config.mjs') $frameworks['Next.js'] = true;
            if ($base === 'angular.json') $frameworks['Angular'] = true;
            if ($base === 'vue.config.js') $frameworks['Vue CLI'] = true;
            if ($base === 'manage.py') $frameworks['Django/Python'] = true;
            if ($base === 'requirements.txt' || $base === 'pyproject.toml') $frameworks['Python'] = true;

            $content = @file_get_contents($item->getPathname());
            if (!is_string($content) || str_contains($content, "\0")) continue;
            $rules = [
                '/\beval\s*\(/i' => ['high', 'Uso de eval(); rever execução dinâmica de código.'],
                '/\b(?:shell_exec|system|passthru|popen)\s*\(/i' => ['high', 'Chamada a execução de comandos do sistema; confirmar validação e autorização.'],
                '/\b(?:mysql_query|mysqli_query)\s*\(/i' => ['medium', 'Rever consultas SQL e utilização de parâmetros preparados.'],
                '/\b(?:TODO|FIXME|XXX)\b/i' => ['info', 'Marcador TODO/FIXME/XXX encontrado.'],
            ];
            foreach ($rules as $pattern => [$severity, $message]) {
                if (preg_match($pattern, $content)) $findings[] = ['severity' => $severity, 'file' => $relative, 'message' => $message];
            }
        }
        sort($files);
        arsort($extensions);
        $frameworkList = array_keys($frameworks);
        if ($frameworkList === []) $frameworkList[] = 'Não identificado automaticamente';
        return [
            'success' => true,
            'project' => $project,
            'summary' => ['files_scanned' => count($files), 'bytes_scanned' => $totalBytes, 'extensions' => $extensions, 'technologies' => $frameworkList],
            'findings' => array_slice($findings, 0, 100),
            'files' => array_slice($files, 0, 100),
            'limitations' => ['Análise heurística e read-only; não substitui SAST profissional nem revisão humana.', 'Não executa código nem altera ficheiros.', 'Dependências são inferidas pelos manifests encontrados; não são instaladas nem auditadas online.'],
        ];
    }
}

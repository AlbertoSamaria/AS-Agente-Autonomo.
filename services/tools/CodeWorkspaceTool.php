<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

/**
 * Workspace isolado por utilizador. Nunca permite caminhos absolutos,
 * traversal, ficheiros de configuração sensíveis ou escrita fora do workspace.
 */
final class CodeWorkspaceTool
{
    private const MAX_FILE_BYTES = 262144;
    private const MAX_PROJECT_BYTES = 2097152;
    private const MAX_PROJECT_FILES = 40;

    private function workspace(int $userId): string
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Utilizador inválido para aceder ao workspace.');
        }

        $storage = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            throw new RuntimeException('Não foi possível criar a área privada de trabalho.');
        }

        $deny = $storage . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($deny)) {
            file_put_contents($deny, "Require all denied\n", LOCK_EX);
        }

        $base = $storage . DIRECTORY_SEPARATOR . 'workspaces' . DIRECTORY_SEPARATOR . $userId;
        if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException('Não foi possível preparar o workspace.');
        }

        return $base;
    }

    private function relativePath(string $path, bool $allowEmpty = false): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' && $allowEmpty) {
            return '';
        }
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:/', $path) === 1
        ) {
            throw new InvalidArgumentException('Caminho inválido.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            $lower = strtolower($segment);
            $windowsNormalized = rtrim($segment, " .");
            if (
                $segment === ''
                || $windowsNormalized === ''
                || $windowsNormalized === '.'
                || $windowsNormalized === '..'
                || preg_match('/[<>:"|?*]/', $segment) === 1
                || str_starts_with($segment, '.')
                || in_array($lower, ['.git', 'node_modules', 'vendor', 'uploads', 'storage'], true)
                || $lower === 'web.config'
                || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\\.|$)/i', $segment) === 1
            ) {
                throw new InvalidArgumentException('O caminho contém um segmento não permitido.');
            }
        }

        $filename = strtolower((string) end($segments));
        if ($filename === '.env' || str_starts_with($filename, '.env.')) {
            throw new InvalidArgumentException('Não é permitido criar ou ler ficheiros .env.');
        }

        return implode('/', $segments);
    }

    private function resolve(int $userId, string $relative, bool $forWrite = false): string
    {
        $base = $this->workspace($userId);
        $path = $this->relativePath($relative);
        $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $parent = $forWrite ? dirname($full) : $full;
        $realParent = realpath($parent);
        $realBase = realpath($base);

        if ($realBase === false) {
            throw new RuntimeException('Workspace indisponível.');
        }
        if ($realParent !== false) {
            $prefix = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if ($realParent !== $realBase && !str_starts_with($realParent . DIRECTORY_SEPARATOR, $prefix)) {
                throw new RuntimeException('Acesso fora do workspace bloqueado.');
            }
        } elseif (!$forWrite) {
            throw new RuntimeException('Ficheiro ou pasta não encontrado.');
        }

        if (is_link($full)) {
            throw new RuntimeException('Acesso a links simbólicos está bloqueado.');
        }
        $realTarget = realpath($full);
        if ($realTarget !== false && $realTarget !== $realBase &&
            !str_starts_with($realTarget . DIRECTORY_SEPARATOR, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Acesso fora do workspace bloqueado.');
        }

        return $full;
    }

    public function list(array $args, int $userId): array
    {
        $relative = $this->relativePath((string) ($args['path'] ?? ''), true);
        $base = $this->workspace($userId);
        $directory = $relative === ''
            ? $base
            : $this->resolve($userId, $relative);

        if (!is_dir($directory)) {
            return ['success' => false, 'error' => 'A pasta indicada não existe.'];
        }

        $items = [];
        foreach (new DirectoryIterator($directory) as $item) {
            if ($item->isDot() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            $items[] = [
                'name' => $item->getFilename(),
                'type' => $item->isDir() ? 'directory' : 'file',
                'size' => $item->isFile() ? $item->getSize() : null,
            ];
            if (count($items) >= 100) {
                break;
            }
        }

        return ['success' => true, 'path' => $relative, 'items' => $items];
    }

    public function read(array $args, int $userId): array
    {
        $relative = (string) ($args['path'] ?? '');
        $file = $this->resolve($userId, $relative);
        if (!is_file($file)) {
            return ['success' => false, 'error' => 'Ficheiro não encontrado.'];
        }
        $size = filesize($file);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            return ['success' => false, 'error' => 'O ficheiro excede o limite de leitura de 256 KB.'];
        }

        return [
            'success' => true,
            'path' => $this->relativePath($relative),
            'content' => (string) file_get_contents($file),
            'size' => $size,
        ];
    }

    public function write(array $args, int $userId): array
    {
        $relative = $this->relativePath((string) ($args['path'] ?? ''));
        $content = $args['content'] ?? null;
        if (!is_string($content)) {
            return ['success' => false, 'error' => 'O conteúdo do ficheiro deve ser texto.'];
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            return ['success' => false, 'error' => 'O ficheiro excede o limite de 256 KB.'];
        }

        $full = $this->resolve($userId, $relative, true);
        $directory = dirname($full);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta do ficheiro.');
        }
        $realBase = realpath($this->workspace($userId));
        $realDirectory = realpath($directory);
        if ($realBase === false || $realDirectory === false ||
            ($realDirectory !== $realBase && !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('A escrita fora do workspace foi bloqueada.');
        }

        $written = file_put_contents($full, $content, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Não foi possível escrever o ficheiro.');
        }

        return ['success' => true, 'path' => $relative, 'bytes_written' => $written];
    }

    public function createProject(array $args, int $userId): array
    {
        $name = trim((string) ($args['name'] ?? ''));
        if (
            !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{1,49}$/', $name)
            || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $name) === 1
        ) {
            return ['success' => false, 'error' => 'Nome do projecto inválido. Usa letras, números, hífen ou underscore.'];
        }
        $files = $args['files'] ?? [];
        if (!is_array($files) || $files === [] || count($files) > self::MAX_PROJECT_FILES) {
            return ['success' => false, 'error' => 'O projecto deve conter entre 1 e 40 ficheiros.'];
        }

        $base = $this->workspace($userId);
        $project = $base . DIRECTORY_SEPARATOR . $name;
        if (file_exists($project)) {
            return ['success' => false, 'error' => 'Já existe um projecto com esse nome no workspace.'];
        }
        if (!mkdir($project, 0700, true)) {
            throw new RuntimeException('Não foi possível criar a pasta do projecto.');
        }

        $total = 0;
        $created = [];
        try {
            foreach ($files as $path => $content) {
                if (!is_string($path) || !is_string($content)) {
                    throw new InvalidArgumentException('A lista de ficheiros do projecto é inválida.');
                }
                $relative = $this->relativePath($path);
                $bytes = strlen($content);
                if ($bytes > self::MAX_FILE_BYTES || ($total + $bytes) > self::MAX_PROJECT_BYTES) {
                    throw new InvalidArgumentException('O projecto excede os limites de tamanho permitidos.');
                }
                $target = $project . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('Não foi possível criar uma pasta do projecto.');
                }
                if (file_put_contents($target, $content, LOCK_EX) === false) {
                    throw new RuntimeException('Falha ao escrever ' . $relative . '.');
                }
                $total += $bytes;
                $created[] = $relative;
            }
        } catch (Throwable $error) {
            $this->removeDirectory($project);
            throw $error;
        }

        return [
            'success' => true,
            'project' => $name,
            'workspace_path' => 'storage/workspaces/' . $userId . '/' . $name,
            'files_created' => $created,
            'total_bytes' => $total,
            'note' => 'Os ficheiros foram criados numa área privada. Ainda não foram executados.',
        ];
    }

    public function phpLint(array $args, int $userId): array
    {
        $relative = $this->relativePath((string) ($args['path'] ?? ''));
        if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'php') {
            return ['success' => false, 'error' => 'A validação de sintaxe está disponível apenas para ficheiros PHP.'];
        }
        $file = $this->resolve($userId, $relative);
        if (!is_file($file)) {
            return ['success' => false, 'error' => 'Ficheiro PHP não encontrado.'];
        }
        if (!function_exists('proc_open')) {
            return ['success' => false, 'error' => 'A validação PHP requer proc_open activo no PHP.'];
        }

        $command = [PHP_BINARY, '-l', $file];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível iniciar o verificador de sintaxe PHP.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'success' => $exitCode === 0,
            'path' => $relative,
            'exit_code' => $exitCode,
            'output' => trim((string) $stdout . "\n" . (string) $stderr),
            'execution' => 'Apenas validação de sintaxe; o código não foi executado.',
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }
        foreach (new DirectoryIterator($directory) as $item) {
            if ($item->isDot()) {
                continue;
            }
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}

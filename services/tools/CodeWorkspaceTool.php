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

    /** Cria uma versão imutável de uma pasta do workspace. */
    public function snapshot(array $args, int $userId): array
    {
        $relative = $this->relativePath((string) ($args['path'] ?? ''), true);
        $base = $this->workspace($userId);
        $source = $relative === '' ? $base : $this->resolve($userId, $relative);
        if (!is_dir($source) || is_link($source)) {
            return ['success' => false, 'error' => 'A pasta do workspace não existe ou não é válida.'];
        }

        $snapshotRoot = $this->snapshotRoot($userId);
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $destination = $snapshotRoot . DIRECTORY_SEPARATOR . $id;
        $payload = $destination . DIRECTORY_SEPARATOR . 'payload';
        if (!mkdir($payload, 0700, true)) {
            throw new RuntimeException('Não foi possível criar a cópia de segurança.');
        }

        $stats = ['files' => 0, 'bytes' => 0];
        try {
            $this->copyTree($source, $payload, $stats);
            $metadata = [
                'id' => $id,
                'path' => $relative,
                'created_at' => gmdate('c'),
                'files' => $stats['files'],
                'bytes' => $stats['bytes'],
            ];
            file_put_contents($destination . DIRECTORY_SEPARATOR . 'metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        } catch (Throwable $error) {
            $this->removeDirectory($destination);
            throw $error;
        }

        return ['success' => true, 'snapshot' => $metadata, 'message' => 'Cópia de segurança criada.'];
    }

    /** Lista apenas versões pertencentes ao utilizador autenticado. */
    public function listSnapshots(array $args, int $userId): array
    {
        $root = $this->snapshotRoot($userId);
        $items = [];
        foreach (new DirectoryIterator($root) as $item) {
            if ($item->isDot() || !$item->isDir() || $item->isLink()) {
                continue;
            }
            $file = $item->getPathname() . DIRECTORY_SEPARATOR . 'metadata.json';
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', (string) ($data['id'] ?? ''))) {
                $items[] = $data;
            }
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return ['success' => true, 'snapshots' => array_slice($items, 0, 100)];
    }

    /** Compara o conteúdo actual de uma pasta com uma versão guardada. */
    public function diffSnapshot(array $args, int $userId): array
    {
        $id = (string) ($args['snapshot_id'] ?? '');
        if (preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $id) !== 1) {
            return ['success' => false, 'error' => 'Identificador de versão inválido.'];
        }
        $snapshot = $this->snapshotRoot($userId) . DIRECTORY_SEPARATOR . $id;
        $metadataFile = $snapshot . DIRECTORY_SEPARATOR . 'metadata.json';
        $payload = $snapshot . DIRECTORY_SEPARATOR . 'payload';
        if (!is_file($metadataFile) || !is_dir($payload) || is_link($snapshot)) {
            return ['success' => false, 'error' => 'A versão indicada não existe.'];
        }
        $metadata = json_decode((string) file_get_contents($metadataFile), true);
        if (!is_array($metadata) || ($metadata['id'] ?? '') !== $id) {
            return ['success' => false, 'error' => 'Metadados da versão inválidos.'];
        }
        $relative = (string) ($metadata['path'] ?? '');
        $current = $relative === '' ? $this->workspace($userId) : $this->resolve($userId, $relative, true);
        $oldFiles = $this->collectFiles($payload);
        $newFiles = is_dir($current) ? $this->collectFiles($current) : [];
        $paths = array_unique(array_merge(array_keys($oldFiles), array_keys($newFiles)));
        sort($paths, SORT_STRING);
        $changes = [];
        foreach ($paths as $path) {
            $old = $oldFiles[$path] ?? null;
            $new = $newFiles[$path] ?? null;
            if ($old === null) {
                $changes[] = ['path' => $path, 'status' => 'added', 'new_bytes' => strlen($new)];
            } elseif ($new === null) {
                $changes[] = ['path' => $path, 'status' => 'deleted', 'old_bytes' => strlen($old)];
            } elseif (!hash_equals(hash('sha256', $old), hash('sha256', $new))) {
                $changes[] = [
                    'path' => $path,
                    'status' => 'modified',
                    'old_bytes' => strlen($old),
                    'new_bytes' => strlen($new),
                    'diff' => $this->lineDiff($old, $new),
                ];
            }
            if (count($changes) >= 300) break;
        }
        return [
            'success' => true,
            'snapshot_id' => $id,
            'path' => $relative,
            'summary' => [
                'added' => count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'added')),
                'modified' => count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'modified')),
                'deleted' => count(array_filter($changes, static fn(array $c): bool => $c['status'] === 'deleted')),
            ],
            'changes' => $changes,
        ];
    }

    private function collectFiles(string $directory): array
    {
        $files = [];
        $bytes = 0;
        $walk = function (string $base, string $relative = '') use (&$walk, &$files, &$bytes): void {
            foreach (new DirectoryIterator($base) as $item) {
                if ($item->isDot()) continue;
                $name = $item->getFilename();
                $lower = strtolower($name);
                if (str_starts_with($name, '.') || in_array($lower, ['.git', 'node_modules', 'vendor', 'uploads', 'storage'], true) || $lower === 'web.config' || str_starts_with($lower, '.env') || $item->isLink()) continue;
                $rel = $relative === '' ? $name : $relative . '/' . $name;
                if ($item->isDir()) {
                    $walk($item->getPathname(), $rel);
                } elseif ($item->isFile()) {
                    $size = $item->getSize();
                    if ($size > 262144 || $bytes + $size > 10485760 || count($files) >= 300) throw new RuntimeException('O diff excede os limites de 300 ficheiros ou 10 MB.');
                    $content = file_get_contents($item->getPathname());
                    if ($content === false) throw new RuntimeException('Não foi possível ler um ficheiro para comparação.');
                    $files[$rel] = $content;
                    $bytes += $size;
                }
            }
        };
        $walk($directory);
        return $files;
    }

    private function lineDiff(string $old, string $new): array
    {
        $a = preg_split('/\r\n|\r|\n/', $old) ?: [];
        $b = preg_split('/\r\n|\r|\n/', $new) ?: [];
        if (count($a) > 500 || count($b) > 500 || count($a) * count($b) > 200000) {
            return ['summary_only' => true, 'message' => 'Ficheiro demasiado grande para diff linha a linha; tamanhos e hash foram comparados.'];
        }
        $n = count($a); $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? 1 + $dp[$i + 1][$j + 1] : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $out = [];
        $i = 0; $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $out[] = ' ' . $a[$i]; $i++; $j++;
            } elseif ($j < $m && ($i >= $n || $dp[$i][$j + 1] >= $dp[$i + 1][$j])) {
                $out[] = '+' . $b[$j++];
            } else {
                $out[] = '-' . $a[$i++];
            }
            if (count($out) >= 400) { $out[] = '… diff truncado …'; break; }
        }
        return $out;
    }

    /** Restaura uma versão após guardar automaticamente o estado actual. */
    public function restoreSnapshot(array $args, int $userId): array
    {
        $id = (string) ($args['snapshot_id'] ?? '');
        if (preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $id) !== 1) {
            return ['success' => false, 'error' => 'Identificador de versão inválido.'];
        }
        $root = $this->snapshotRoot($userId);
        $snapshot = $root . DIRECTORY_SEPARATOR . $id;
        $metadataFile = $snapshot . DIRECTORY_SEPARATOR . 'metadata.json';
        $payload = $snapshot . DIRECTORY_SEPARATOR . 'payload';
        if (!is_file($metadataFile) || !is_dir($payload) || is_link($snapshot)) {
            return ['success' => false, 'error' => 'A versão indicada não existe.'];
        }
        $metadata = json_decode((string) file_get_contents($metadataFile), true);
        if (!is_array($metadata) || ($metadata['id'] ?? '') !== $id) {
            return ['success' => false, 'error' => 'Os metadados da versão são inválidos.'];
        }
        $relative = (string) ($metadata['path'] ?? '');
        $target = $relative === '' ? $this->workspace($userId) : $this->resolve($userId, $relative, true);
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('Não foi possível preparar a pasta de destino.');
        }

        // Salvaguarda automática: nunca substituir sem preservar o estado actual.
        if (is_dir($target)) {
            $this->snapshot(['path' => $relative], $userId);
            $this->removeDirectory($target);
        } elseif (file_exists($target)) {
            return ['success' => false, 'error' => 'O destino não é uma pasta; restauro cancelado.'];
        }
        if (!mkdir($target, 0700, true)) {
            throw new RuntimeException('Não foi possível preparar o destino do restauro.');
        }
        $stats = ['files' => 0, 'bytes' => 0];
        try {
            $this->copyTree($payload, $target, $stats);
        } catch (Throwable $error) {
            throw new RuntimeException('O restauro falhou. A cópia de segurança anterior foi preservada. ' . $error->getMessage(), 0, $error);
        }
        return ['success' => true, 'restored_snapshot' => $id, 'path' => $relative, 'files_restored' => $stats['files'], 'bytes_restored' => $stats['bytes'], 'message' => 'Versão restaurada. O estado anterior foi guardado automaticamente como outra versão.'];
    }

    private function snapshotRoot(int $userId): string
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'workspace_snapshots' . DIRECTORY_SEPARATOR . $userId;
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Não foi possível preparar o arquivo de versões.');
        }
        $deny = dirname($root) . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($deny)) {
            file_put_contents($deny, "Require all denied\n", LOCK_EX);
        }
        return $root;
    }

    private function copyTree(string $source, string $destination, array &$stats): void
    {
        if ($stats['files'] > 300 || $stats['bytes'] > 10485760) {
            throw new RuntimeException('A operação excede o limite de 300 ficheiros ou 10 MB.');
        }
        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) continue;
            $name = $item->getFilename();
            $lower = strtolower($name);
            if (str_starts_with($name, '.') || in_array($lower, ['.git', 'node_modules', 'vendor', 'uploads', 'storage'], true) || $lower === 'web.config' || str_starts_with($lower, '.env')) continue;
            $from = $item->getPathname();
            $to = $destination . DIRECTORY_SEPARATOR . $name;
            if ($item->isLink()) continue;
            if ($item->isDir()) {
                if (!mkdir($to, 0700, true)) throw new RuntimeException('Falha ao copiar pasta ' . $name . '.');
                $this->copyTree($from, $to, $stats);
            } elseif ($item->isFile()) {
                $size = $item->getSize();
                if ($size > 262144 || $stats['bytes'] + $size > 10485760 || $stats['files'] >= 300) throw new RuntimeException('A cópia excede os limites de segurança.');
                if (!copy($from, $to)) throw new RuntimeException('Falha ao copiar o ficheiro ' . $name . '.');
                $stats['files']++;
                $stats['bytes'] += $size;
            }
        }
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

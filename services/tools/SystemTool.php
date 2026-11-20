<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

final class SystemTool
{
    public function health(): array
    {
        $checks = [];
        $checks['php'] = [
            'status' => PHP_VERSION_ID >= 80200 ? 'ok' : 'warning',
            'version' => PHP_VERSION,
        ];

        $extensions = ['curl', 'dom', 'json', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'zip'];
        $missing = [];
        foreach ($extensions as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        $checks['extensions'] = [
            'status' => $missing === [] ? 'ok' : 'error',
            'required' => $extensions,
            'missing' => $missing,
        ];

        try {
            $started = microtime(true);
            db()->query('SELECT 1')->fetchColumn();
            $checks['database'] = [
                'status' => 'ok',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable $error) {
            $checks['database'] = [
                'status' => 'error',
                'error' => $this->safeError($error),
            ];
        }

        $checks['storage'] = $this->directoryCheck(dirname(__DIR__, 2) . '/storage');
        $checks['uploads'] = $this->directoryCheck(dirname(__DIR__, 2) . '/uploads');

        $diskFree = @disk_free_space(dirname(__DIR__, 2));
        $diskTotal = @disk_total_space(dirname(__DIR__, 2));
        if (is_float($diskFree) && is_float($diskTotal) && $diskTotal > 0) {
            $checks['disk'] = [
                'status' => ($diskFree / $diskTotal) >= 0.10 ? 'ok' : 'warning',
                'free_bytes' => (int) $diskFree,
                'total_bytes' => (int) $diskTotal,
                'free_percent' => round(($diskFree / $diskTotal) * 100, 1),
            ];
        } else {
            $checks['disk'] = ['status' => 'unknown'];
        }

        $checks['ai'] = $this->aiConfig();

        $errors = [];
        foreach ($checks as $name => $check) {
            if (($check['status'] ?? 'unknown') === 'error') {
                $errors[] = $name;
            }
        }

        return [
            'success' => $errors === [],
            'status' => $errors === [] ? 'healthy' : 'degraded',
            'version' => SA_VERSION,
            'timezone' => SA_TIMEZONE,
            'checked_at' => date('c'),
            'checks' => $checks,
            'errors' => $errors,
        ];
    }

    private function directoryCheck(string $path): array
    {
        if (!is_dir($path)) {
            return ['status' => 'warning', 'exists' => false, 'writable' => false];
        }

        return [
            'status' => is_writable($path) ? 'ok' : 'error',
            'exists' => true,
            'writable' => is_writable($path),
        ];
    }

    private function aiConfig(): array
    {
        $provider = strtolower((string) env('LLM_PROVIDER', 'ollama'));
        $fallback = filter_var(
            env('LLM_FALLBACK_ENABLED', 'true'),
            FILTER_VALIDATE_BOOLEAN
        );

        $configured = [];
        foreach (['GEMINI_MODEL', 'GROQ_MODEL', 'OLLAMA_MODEL', 'LLAMACPP_MODEL'] as $key) {
            $value = trim((string) env($key, ''));
            if ($value !== '') {
                $configured[$key] = $value;
            }
        }

        return [
            'status' => $provider !== '' ? 'ok' : 'warning',
            'primary_provider' => $provider,
            'fallback_enabled' => $fallback,
            'models' => $configured,
        ];
    }

    private function safeError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/(?:password|passwd|secret|api[_-]?key|token)=?[^\s&]+/iu', '$1=[redacted]', $message) ?? $message;
        return mb_substr($message, 0, 300);
    }
}

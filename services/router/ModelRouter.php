<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/ai/Ollama.php';
require_once dirname(__DIR__) . '/ai/LlamaCpp.php';
require_once dirname(__DIR__) . '/ai/Gemini.php';
require_once dirname(__DIR__) . '/ai/Groq.php';

final class ModelRouter
{
    public function chat(array $messages, string $purpose = 'general'): array
    {
        $providers = $this->providers();
        $failures = [];

        foreach ($providers as $providerIndex => $provider) {
            foreach ($this->models($provider, $purpose) as $model) {
                $startedAt = hrtime(true);
                try {
                    $response = $this->client($provider)->chat($messages, $model);
                    $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1000000);
                    error_log('[SA ModelRouter] success provider=' . $provider .
                        ' model=' . $model . ' purpose=' . $purpose .
                        ' fallback=' . ($providerIndex > 0 ? 'yes' : 'no') .
                        ' elapsed_ms=' . $elapsedMs);
                    return $response;
                } catch (Throwable $error) {
                    $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1000000);
                    $failures[] = $provider . '/' . $model . ': ' . $error->getMessage();
                    error_log('[SA ModelRouter] failure elapsed_ms=' . $elapsedMs . ' ' . end($failures));
                }
            }
        }

        throw new RuntimeException(
            'Todos os fornecedores e modelos configurados falharam: ' . implode(' | ', $failures)
        );
    }

    public function chatStream(array $messages, callable $onToken, string $purpose = 'general'): array
    {
        $providers = $this->providers();
        $failures = [];

        foreach ($providers as $providerIndex => $provider) {
            foreach ($this->models($provider, $purpose) as $model) {
                $emitted = '';
                $startedAt = hrtime(true);
                try {
                    $response = $this->client($provider)->chatStream(
                        $messages,
                        static function (string $token) use (&$emitted, $onToken): void {
                            $emitted .= $token;
                            $onToken($token);
                        },
                        $model
                    );
                    $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1000000);
                    error_log('[SA ModelRouter] stream_success provider=' . $provider .
                        ' model=' . $model . ' purpose=' . $purpose .
                        ' fallback=' . ($providerIndex > 0 ? 'yes' : 'no') .
                        ' elapsed_ms=' . $elapsedMs);
                    return $response;
                } catch (Throwable $error) {
                    $elapsedMs = (int) round((hrtime(true) - $startedAt) / 1000000);
                    $failure = $provider . '/' . $model . ': ' . $error->getMessage();
                    error_log('[SA ModelRouter] stream_failure elapsed_ms=' . $elapsedMs . ' ' . $failure);
                    if ($emitted !== '') {
                        throw new RuntimeException(
                            'O streaming foi interrompido depois de comecar; nao e seguro repetir a resposta. ' .
                            $error->getMessage(),
                            0,
                            $error
                        );
                    }
                    $failures[] = $failure;
                }
            }
        }

        throw new RuntimeException(
            'Todos os fornecedores e modelos configurados falharam: ' . implode(' | ', $failures)
        );
    }

    private function client(string $provider): object
    {
        return match ($provider) {
            'ollama' => new Ollama(),
            'llama.cpp' => new LlamaCpp(),
            'gemini' => new Gemini(),
            'groq' => new Groq(),
            default => throw new RuntimeException('Fornecedor nao suportado: ' . $provider),
        };
    }

    private function providers(): array
    {
        $allowed = ['ollama', 'llama.cpp', 'gemini', 'groq'];
        $primary = strtolower((string) env('LLM_PROVIDER', 'ollama'));
        if (!in_array($primary, $allowed, true)) {
            throw new RuntimeException('Fornecedor de modelos nao suportado: ' . $primary);
        }

        if (!filter_var(env('LLM_FALLBACK_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            return [$primary];
        }

        $defaultFallback = match ($primary) {
            'ollama' => 'llama.cpp',
            'llama.cpp' => 'ollama',
            'gemini' => 'groq',
            'groq' => 'gemini',
            default => 'ollama',
        };

        $fallback = strtolower((string) env('LLM_FALLBACK_PROVIDER', $defaultFallback));
        if (!in_array($fallback, $allowed, true)) {
            throw new RuntimeException('Fornecedor de fallback nao suportado: ' . $fallback);
        }

        return array_values(array_unique([$primary, $fallback]));
    }

    private function models(string $provider, string $purpose): array
    {
        $statement = db()->prepare(
            "SELECT model_name
             FROM model_registry
             WHERE provider = ?
               AND purpose IN (?, 'general')
               AND enabled = 1
             ORDER BY priority ASC"
        );
        $statement->execute([$provider, $purpose]);
        $models = array_values(array_unique(array_map(
            'strval',
            $statement->fetchAll(PDO::FETCH_COLUMN)
        )));

        $settings = [
            'ollama' => ['OLLAMA_MODEL', 'qwen2.5:1.5b'],
            'llama.cpp' => ['LLAMACPP_MODEL', 'local-model'],
            'gemini' => ['GEMINI_MODEL', 'gemini-3.8-flash'],
            'groq' => ['GROQ_MODEL', 'openai/gpt-oss-20b'],
        ];
        [$setting, $default] = $settings[$provider];
        $configured = (string) env($setting, $default);

        if ($models === []) {
            return [$configured];
        }
        if (!in_array($configured, $models, true)) {
            $models[] = $configured;
        }
        return $models;
    }
}

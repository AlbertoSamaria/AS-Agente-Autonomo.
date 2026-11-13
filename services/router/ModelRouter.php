<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/ai/Ollama.php';
require_once dirname(__DIR__) . '/ai/LlamaCpp.php';

final class ModelRouter
{
    public function chat(array $messages, string $purpose = 'general'): array
    {
        $providers = $this->providers();
        $failures = [];

        foreach ($providers as $providerIndex => $provider) {
            foreach ($this->models($provider, $purpose) as $model) {
                try {
                    $client = $provider === 'llama.cpp'
                        ? new LlamaCpp()
                        : new Ollama();
                    $response = $client->chat($messages, $model);
                    if ($providerIndex > 0) {
                        error_log('[SA ModelRouter] Fallback succeeded: ' . $provider . '/' . $model);
                    }
                    return $response;
                } catch (Throwable $error) {
                    $failures[] = $provider . '/' . $model . ': ' . $error->getMessage();
                    error_log('[SA ModelRouter] ' . end($failures));
                }
            }
        }

        throw new RuntimeException(
            'Todos os fornecedores e modelos configurados falharam: ' .
            implode(' | ', $failures)
        );
    }

    public function chatStream(
        array $messages,
        callable $onToken,
        string $purpose = 'general'
    ): array {
        $providers = $this->providers();
        $failures = [];

        foreach ($providers as $providerIndex => $provider) {
            foreach ($this->models($provider, $purpose) as $model) {
                $emitted = '';
                try {
                    $client = $provider === 'llama.cpp'
                        ? new LlamaCpp()
                        : new Ollama();
                    $response = $client->chatStream(
                        $messages,
                        static function (string $token) use (&$emitted, $onToken): void {
                            $emitted .= $token;
                            $onToken($token);
                        },
                        $model
                    );
                    if ($providerIndex > 0) {
                        error_log('[SA ModelRouter] Streaming fallback succeeded: ' . $provider . '/' . $model);
                    }
                    return $response;
                } catch (Throwable $error) {
                    $failure = $provider . '/' . $model . ': ' . $error->getMessage();
                    error_log('[SA ModelRouter] ' . $failure);
                    if ($emitted !== '') {
                        throw new RuntimeException(
                            'O streaming foi interrompido depois de começar; não é seguro repetir a resposta. ' .
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
            'Todos os fornecedores e modelos configurados falharam: ' .
            implode(' | ', $failures)
        );
    }

    private function providers(): array
    {
        $primary = strtolower((string) env('LLM_PROVIDER', 'ollama'));
        if (!in_array($primary, ['ollama', 'llama.cpp'], true)) {
            throw new RuntimeException('Fornecedor de modelos não suportado: ' . $primary);
        }

        if (!filter_var(env('LLM_FALLBACK_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
            return [$primary];
        }

        $fallback = strtolower((string) env(
            'LLM_FALLBACK_PROVIDER',
            $primary === 'ollama' ? 'llama.cpp' : 'ollama'
        ));
        if (!in_array($fallback, ['ollama', 'llama.cpp'], true)) {
            throw new RuntimeException('Fornecedor de fallback não suportado: ' . $fallback);
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

        $modelSetting = $provider === 'llama.cpp'
            ? 'LLAMACPP_MODEL'
            : 'OLLAMA_MODEL';
        $defaultModel = $provider === 'llama.cpp'
            ? 'local-model'
            : 'qwen2.5:1.5b';
        $configuredModel = (string) env($modelSetting, $defaultModel);

        if ($models === []) {
            return [$configuredModel];
        }

        if (!in_array($configuredModel, $models, true)) {
            $models[] = $configuredModel;
        }

        return $models;
    }
}

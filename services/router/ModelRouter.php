<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once dirname(__DIR__) . '/ai/Ollama.php';
require_once dirname(__DIR__) . '/ai/LlamaCpp.php';

final class ModelRouter
{
    public function chat(array $messages, string $purpose = 'general'): array
    {
        $provider = strtolower((string) env('LLM_PROVIDER', 'ollama'));
        if (!in_array($provider, ['ollama', 'llama.cpp'], true)) {
            throw new RuntimeException('Fornecedor de modelos não suportado: ' . $provider);
        }

        $statement = db()->prepare(
            "SELECT model_name FROM model_registry WHERE provider=? AND purpose IN (?, 'general') AND enabled=1 ORDER BY priority ASC"
        );
        $statement->execute([$provider, $purpose]);
        $models = $statement->fetchAll(PDO::FETCH_COLUMN);

        if (!$models) {
            $modelSetting = $provider === 'llama.cpp' ? 'LLAMACPP_MODEL' : 'OLLAMA_MODEL';
            $defaultModel = $provider === 'llama.cpp' ? 'local-model' : 'qwen2.5:1.5b';
            $models = [(string) env($modelSetting, $defaultModel)];
        }

        $lastError = null;
        foreach ($models as $model) {
            try {
                $client = $provider === 'llama.cpp' ? new LlamaCpp() : new Ollama();
                return $client->chat($messages, (string) $model);
            } catch (Throwable $error) {
                $lastError = $error;
            }
        }

        throw new RuntimeException(
            'Todos os modelos configurados falharam: ' . ($lastError?->getMessage() ?? 'erro desconhecido')
        );
    }
}

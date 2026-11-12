<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

final class LlamaCpp
{
    public function chat(array $messages, ?string $model = null): array
    {
        $baseUrl = rtrim((string) env('LLAMACPP_BASE_URL', 'http://127.0.0.1:8080'), '/');
        $model = $model ?: (string) env('LLAMACPP_MODEL', 'local-model');
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'temperature' => 0.35,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('Não foi possível preparar o pedido para o llama.cpp.');
        }

        $response = $this->request(
            $baseUrl . '/v1/chat/completions',
            $payload,
            (int) env('LLAMACPP_TIMEOUT', 120)
        );
        $data = json_decode($response['body'], true);

        if ($response['status'] >= 400 || !is_array($data)) {
            throw new RuntimeException(
                'llama.cpp retornou HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500)
            );
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new RuntimeException('llama.cpp devolveu uma resposta sem conteúdo.');
        }

        return ['message' => ['content' => $content]];
    }

    public function health(): array
    {
        $baseUrl = rtrim((string) env('LLAMACPP_BASE_URL', 'http://127.0.0.1:8080'), '/');

        try {
            $health = $this->request($baseUrl . '/health', null, 5);
            if ($health['status'] !== 200) {
                return ['online' => false, 'models' => []];
            }

            $modelsResponse = $this->request($baseUrl . '/v1/models', null, 5);
            $models = json_decode($modelsResponse['body'], true);

            return [
                'online' => true,
                'models' => is_array($models) ? ($models['data'] ?? []) : [],
            ];
        } catch (RuntimeException) {
            return ['online' => false, 'models' => []];
        }
    }

    private function request(string $url, ?string $payload, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar o cliente HTTP do llama.cpp.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $payload;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("llama.cpp não respondeu: {$error}");
        }

        return ['body' => (string) $body, 'status' => $status];
    }
}

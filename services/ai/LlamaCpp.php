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

    public function chatStream(
        array $messages,
        callable $onToken,
        ?string $model = null
    ): array {
        $baseUrl = rtrim((string) env('LLAMACPP_BASE_URL', 'http://127.0.0.1:8080'), '/');
        $model = $model ?: (string) env('LLAMACPP_MODEL', 'local-model');
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'temperature' => 0.35,
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Não foi possível preparar o streaming para llama.cpp.');
        }

        $ch = curl_init($baseUrl . '/v1/chat/completions');
        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar o streaming de llama.cpp.');
        }

        $buffer = '';
        $content = '';
        $streamError = null;
        $processLine = static function (string $line) use (&$content, &$streamError, $onToken): void {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                return;
            }
            $line = trim(substr($line, 5));
            if ($line === '' || $line === '[DONE]') {
                return;
            }

            $data = json_decode($line, true);
            if (!is_array($data)) {
                return;
            }
            if (isset($data['error'])) {
                $streamError = is_array($data['error'])
                    ? (string) ($data['error']['message'] ?? 'Erro no streaming')
                    : (string) $data['error'];
                return;
            }

            $token = $data['choices'][0]['delta']['content'] ?? null;
            if (is_string($token) && $token !== '') {
                $content .= $token;
                $onToken($token);
            }
        };

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) env('LLAMACPP_TIMEOUT', 120),
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, $processLine): int {
                $buffer .= $chunk;
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $processLine(substr($buffer, 0, $newline));
                    $buffer = substr($buffer, $newline + 1);
                }
                return strlen($chunk);
            },
        ]);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($buffer !== '') {
            $processLine($buffer);
        }
        if ($errno !== 0) {
            throw new RuntimeException('llama.cpp não respondeu durante o streaming: ' . $error);
        }
        if ($status >= 400 || $streamError !== null) {
            throw new RuntimeException(
                'llama.cpp retornou HTTP ' . $status . ': ' .
                ($streamError ?? substr((string) $result, 0, 500))
            );
        }
        if ($content === '') {
            throw new RuntimeException('llama.cpp terminou o streaming sem conteúdo.');
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

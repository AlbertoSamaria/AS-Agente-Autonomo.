<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

final class Ollama
{
    /**
     * Envia uma conversa para o Ollama.
     */
    public function chat(array $messages, ?string $model = null): array
    {
        $url = (string) env('OLLAMA_API_URL');

        $model = $model ?: (string) env(
            'OLLAMA_MODEL',
            'qwen2.5:1.5b'
        );

        $payload = json_encode(
            [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'options' => [
                    'temperature' => 0.35,
                ],
            ],
            JSON_UNESCAPED_UNICODE
        );

        if ($payload === false) {
            throw new RuntimeException(
                'Não foi possível preparar o pedido para o Ollama.'
            );
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException(
                'Não foi possível inicializar o cliente HTTP do Ollama.'
            );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => (int) env(
                    'OLLAMA_TIMEOUT',
                    120
                ),
            ]
        );

        $raw = curl_exec($ch);

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException(
                "Ollama não respondeu: {$error}"
            );
        }

        $data = json_decode(
            (string) $raw,
            true
        );

        if (
            $code >= 400 ||
            !is_array($data)
        ) {
            throw new RuntimeException(
                'Ollama retornou HTTP '
                . $code
                . ': '
                . substr((string) $raw, 0, 500)
            );
        }

        return $data;
    }

    public function chatStream(
        array $messages,
        callable $onToken,
        ?string $model = null
    ): array {
        $url = (string) env('OLLAMA_API_URL');
        $model = $model ?: (string) env('OLLAMA_MODEL', 'qwen2.5:1.5b');
        $payload = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'options' => ['temperature' => 0.35],
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Não foi possível preparar o streaming para o Ollama.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar o streaming do Ollama.');
        }
        $buffer = '';
        $content = '';
        $streamError = null;
        $processLine = static function (string $line) use (&$content, &$streamError, $onToken): void {
            $data = json_decode(trim($line), true);
            if (!is_array($data)) {
                return;
            }
            if (isset($data['error'])) {
                $streamError = (string) $data['error'];
                return;
            }
            $token = $data['message']['content'] ?? null;
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
            CURLOPT_TIMEOUT => (int) env('OLLAMA_TIMEOUT', 120),
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
            throw new RuntimeException('Ollama não respondeu durante o streaming: ' . $error);
        }
        if ($status >= 400 || $streamError !== null) {
            throw new RuntimeException(
                'Ollama retornou HTTP ' . $status . ': ' .
                ($streamError ?? substr((string) $result, 0, 500))
            );
        }
        if ($content === '') {
            throw new RuntimeException('Ollama terminou o streaming sem conteúdo.');
        }

        return ['message' => ['content' => $content]];
    }


    /**
     * Verifica se o Ollama está disponível.
     */
    public function health(): array
    {
        $url = (string) env('OLLAMA_TAGS_URL');

        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'online' => false,
                'models' => [],
            ];
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]
        );

        $raw = curl_exec($ch);

        $code = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        curl_close($ch);

        $data = json_decode(
            (string) $raw,
            true
        );

        return [
            'online' => $code === 200 && is_array($data),
            'models' => is_array($data)
                ? ($data['models'] ?? [])
                : [],
        ];
    }
}
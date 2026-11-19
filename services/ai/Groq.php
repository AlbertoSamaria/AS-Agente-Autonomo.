<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

/**
 * Cliente HTTP para Groq Chat Completions.
 * Compatível com o contrato usado pelo ModelRouter: chat() e chatStream().
 */
final class Groq
{
    private const API_URL = 'https://api.groq.com/openai/v1/chat/completions';

    private function apiKey(): string
    {
        $key = trim((string) env('GROQ_API_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('GROQ_API_KEY nao esta configurada no ambiente.');
        }
        return $key;
    }

    private function model(?string $model): string
    {
        return $model ?: (string) env('GROQ_MODEL', 'openai/gpt-oss-20b');
    }

    private function messages(array $messages): array
    {
        $normalised = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = strtolower((string) ($message['role'] ?? 'user'));
            $content = $message['content'] ?? '';

            if (is_array($content)) {
                $parts = [];
                foreach ($content as $part) {
                    if (is_string($part)) {
                        $parts[] = $part;
                    } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                        $parts[] = $part['text'];
                    }
                }
                $content = $parts !== []
                    ? implode("\n", $parts)
                    : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $content = trim((string) $content);
            if ($content === '') {
                continue;
            }

            if ($role === 'developer') {
                $role = 'system';
            } elseif (!in_array($role, ['system', 'assistant', 'user'], true)) {
                $role = 'user';
            }

            $normalised[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        if ($normalised === []) {
            throw new InvalidArgumentException('A conversa nao contem mensagens validas para a Groq.');
        }

        return $normalised;
    }

    private function payload(array $messages, string $model, bool $stream): string
    {
        $data = [
            'model' => $model,
            'messages' => $this->messages($messages),
            'stream' => $stream,
            'temperature' => (float) env('GROQ_TEMPERATURE', 0.55),
            'top_p' => (float) env('GROQ_TOP_P', 0.9),
            'max_completion_tokens' => (int) env('GROQ_MAX_COMPLETION_TOKENS', 2048),
        ];

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Nao foi possivel preparar o pedido para a Groq.');
        }
        return $json;
    }

    private function request(string $payload): array
    {
        $ch = curl_init(self::API_URL);
        if ($ch === false) {
            throw new RuntimeException('Nao foi possivel iniciar a ligacao a Groq.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey(),
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int) env('GROQ_TIMEOUT', 90),
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'body' => is_string($body) ? $body : '',
            'errno' => $errno,
            'error' => $error,
            'status' => $status,
        ];
    }

    private function retryable(int $status): bool
    {
        return in_array($status, [408, 409, 425, 429, 500, 502, 503, 504], true);
    }

    private function retryDelay(int $attempt): void
    {
        usleep(min(400000 * $attempt, 1200000));
    }

    private function errorMessage(string $body): string
    {
        $data = json_decode($body, true);
        $message = $data['error']['message'] ?? $data['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return substr($message, 0, 1200);
        }

        $body = trim($body);
        return $body !== '' ? substr($body, 0, 1200) : 'A API Groq nao devolveu detalhes do erro.';
    }

    public function chat(array $messages, ?string $model = null): array
    {
        $model = $this->model($model);
        $payload = $this->payload($messages, $model, false);
        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->request($payload);

            if ($response['errno'] !== 0) {
                $lastError = 'Groq nao respondeu: ' . $response['error'];
                if ($attempt < 3) {
                    $this->retryDelay($attempt);
                    continue;
                }
                throw new RuntimeException($lastError);
            }

            if ($response['status'] >= 400) {
                $lastError = 'Groq HTTP ' . $response['status'] . ': ' .
                    $this->errorMessage($response['body']);

                if ($this->retryable($response['status']) && $attempt < 3) {
                    $this->retryDelay($attempt);
                    continue;
                }
                throw new RuntimeException($lastError);
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                throw new RuntimeException('A Groq devolveu uma resposta JSON invalida.');
            }

            $content = $data['choices'][0]['message']['content'] ?? null;
            if (is_array($content)) {
                $content = implode("\n", array_filter(array_map(
                    static fn ($part) => is_array($part) && is_string($part['text'] ?? null)
                        ? $part['text'] : '',
                    $content
                )));
            }

            if (!is_string($content) || trim($content) === '') {
                $finishReason = (string) ($data['choices'][0]['finish_reason'] ?? 'sem conteudo');
                throw new RuntimeException('A Groq devolveu uma resposta vazia (' . $finishReason . ').');
            }

            return [
                'message' => ['content' => $content],
                'provider' => 'groq',
                'model' => (string) ($data['model'] ?? $model),
                'usage' => $data['usage'] ?? [],
            ];
        }

        throw new RuntimeException($lastError ?? 'Falha na comunicacao com a Groq.');
    }

    private function processStreamEvent(
        string $event,
        string &$content,
        ?string &$streamError,
        array &$usage,
        callable $onToken
    ): void {
        $dataLines = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($event)) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, 5));
            }
        }

        if ($dataLines === []) {
            return;
        }

        $raw = implode("\n", $dataLines);
        if ($raw === '' || $raw === '[DONE]') {
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        if (isset($data['error'])) {
            $streamError = is_array($data['error'])
                ? (string) ($data['error']['message'] ?? 'Erro no streaming Groq.')
                : (string) $data['error'];
            return;
        }

        if (isset($data['usage']) && is_array($data['usage'])) {
            $usage = $data['usage'];
        }

        $token = $data['choices'][0]['delta']['content'] ?? null;
        if (is_string($token) && $token !== '') {
            $content .= $token;
            $onToken($token);
        }
    }

    public function chatStream(
        array $messages,
        callable $onToken,
        ?string $model = null
    ): array {
        $model = $this->model($model);
        $payload = $this->payload($messages, $model, true);
        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $buffer = '';
            $content = '';
            $streamError = null;
            $usage = [];
            $rawResponse = '';

            $ch = curl_init(self::API_URL);
            if ($ch === false) {
                throw new RuntimeException('Nao foi possivel iniciar o streaming Groq.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                    'Authorization: Bearer ' . $this->apiKey(),
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => (int) env('GROQ_TIMEOUT', 90),
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (
                    &$buffer,
                    &$content,
                    &$streamError,
                    &$usage,
                    &$rawResponse,
                    $onToken
                ): int {
                    if (strlen($rawResponse) < 65536) {
                        $rawResponse .= substr($chunk, 0, 65536 - strlen($rawResponse));
                    }

                    $buffer .= $chunk;
                    while (preg_match('/\r\n\r\n|\n\n|\r\r/', $buffer, $matches, PREG_OFFSET_CAPTURE)) {
                        $separator = $matches[0][0];
                        $position = $matches[0][1];
                        $event = substr($buffer, 0, $position);
                        $buffer = substr($buffer, $position + strlen($separator));

                        $this->processStreamEvent($event, $content, $streamError, $usage, $onToken);
                    }

                    return strlen($chunk);
                },
            ]);

            curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status < 400 && $buffer !== '') {
                $this->processStreamEvent($buffer, $content, $streamError, $usage, $onToken);
            }

            if ($errno !== 0) {
                $lastError = 'Groq nao respondeu durante o streaming: ' . $error;
                if ($content === '' && $attempt < 3) {
                    $this->retryDelay($attempt);
                    continue;
                }
                throw new RuntimeException($lastError);
            }

            if ($status >= 400) {
                $lastError = 'Groq streaming HTTP ' . $status . ': ' .
                    $this->errorMessage($rawResponse);

                if ($this->retryable($status) && $content === '' && $attempt < 3) {
                    $this->retryDelay($attempt);
                    continue;
                }
                throw new RuntimeException($lastError);
            }

            if ($streamError !== null) {
                throw new RuntimeException('Groq streaming: ' . $streamError);
            }

            if ($content === '') {
                throw new RuntimeException('A Groq terminou o streaming sem conteudo.');
            }

            return [
                'message' => ['content' => $content],
                'provider' => 'groq',
                'model' => $model,
                'usage' => $usage,
            ];
        }

        throw new RuntimeException($lastError ?? 'Falha no streaming Groq.');
    }
}

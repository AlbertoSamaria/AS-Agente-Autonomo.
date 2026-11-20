<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

final class Gemini
{
    private function apiKey(): string
    {
        $key = trim((string) env('GEMINI_API_KEY', ''));

        if ($key === '') {
            throw new RuntimeException(
                'GEMINI_API_KEY nao esta configurada no ambiente.'
            );
        }

        return $key;
    }

    private function model(?string $model): string
    {
        $configured = trim((string) ($model ?: env(
            'GEMINI_MODEL',
            'gemini-3.8-flash'
        )));

        // Migração defensiva para projetos que ainda possuem Gemini 2.5
        // configurado no .env ou num registo antigo de modelos.
        $configured = preg_replace('#^models/#', '', $configured) ?: 'gemini-3.8-flash';

        if (in_array(strtolower($configured), [
            'gemini-2.5-flash',
            'gemini-2.5-flash-latest',
        ], true)) {
            return 'gemini-3.8-flash';
        }

        return $configured;
    }

    private function requestUrl(
        string $model,
        string $operation,
        bool $stream = false
    ): string {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' .
            rawurlencode($model) . ':' . $operation;

        if ($stream) {
            $url .= '?alt=sse';
        }

        return $url;
    }

    private function prepareMessages(array $messages): array
    {
        $system = [];
        $contents = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';

            if (is_array($content)) {
                $content = json_encode(
                    $content,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }

            $content = (string) $content;

            if ($content === '') {
                continue;
            }

            if ($role === 'system' || $role === 'developer') {
                $system[] = $content;
                continue;
            }

            $geminiRole = (
                $role === 'assistant' || $role === 'model'
            ) ? 'model' : 'user';

            $lastIndex = count($contents) - 1;

            if (
                $lastIndex >= 0 &&
                $contents[$lastIndex]['role'] === $geminiRole
            ) {
                $contents[$lastIndex]['parts'][] = [
                    'text' => $content,
                ];
            } else {
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [
                        ['text' => $content],
                    ],
                ];
            }
        }

        if ($contents === []) {
            throw new InvalidArgumentException(
                'A conversa nao contem mensagens validas para o Gemini.'
            );
        }

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => (float) env(
                    'GEMINI_TEMPERATURE',
                    0.55
                ),
                'topP' => (float) env(
                    'GEMINI_TOP_P',
                    0.9
                ),
                'maxOutputTokens' => (int) env(
                    'GEMINI_MAX_OUTPUT_TOKENS',
                    2048
                ),
            ],
        ];

        if ($system !== []) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => implode("\n\n", $system)],
                ],
            ];
        }

        return $payload;
    }

    /**
     * Extrai uma mensagem legivel de uma resposta de erro.
     */
    private function extractErrorMessage(string $raw): string
    {
        $data = json_decode($raw, true);

        if (is_array($data)) {
            $message = $data['error']['message'] ?? null;

            if (is_string($message) && $message !== '') {
                $status = $data['error']['status'] ?? null;

                if (is_string($status) && $status !== '') {
                    return $message . ' (' . $status . ')';
                }

                return $message;
            }
        }

        $raw = trim($raw);

        if ($raw === '') {
            return 'O servidor Gemini nao devolveu detalhes do erro.';
        }

        return substr($raw, 0, 1200);
    }

    /**
     * Determina se um erro HTTP pode ser temporario.
     */
    private function isRetryableStatus(int $status): bool
    {
        return in_array(
            $status,
            [429, 500, 502, 503, 504],
            true
        );
    }

    /**
     * Pequena pausa entre tentativas.
     */
    private function retryDelay(int $attempt): void
    {
        $delay = min(500000 * $attempt, 1500000);

        usleep($delay);
    }

    /**
     * Pedido normal sem streaming.
     */
    public function chat(
        array $messages,
        ?string $model = null
    ): array {
        $model = $this->model($model);

        $payload = json_encode(
            $this->prepareMessages($messages),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            throw new RuntimeException(
                'Nao foi possivel preparar o pedido Gemini.'
            );
        }

        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $ch = curl_init(
                $this->requestUrl($model, 'generateContent')
            );

            if ($ch === false) {
                throw new RuntimeException(
                    'Nao foi possivel iniciar a ligacao ao Gemini.'
                );
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $this->apiKey(),
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => (int) env(
                    'GEMINI_TIMEOUT',
                    90
                ),
            ]);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            curl_close($ch);

            if ($errno !== 0) {
                $lastError = 'Gemini nao respondeu: ' . $error;

                if ($attempt < 3) {
                    $this->retryDelay($attempt);
                    continue;
                }

                throw new RuntimeException($lastError);
            }

            $raw = is_string($raw) ? $raw : '';

            $data = json_decode($raw, true);

            if ($status >= 400) {
                $message = $this->extractErrorMessage($raw);

                $lastError = 'Gemini HTTP ' .
                    $status . ': ' . $message;

                if (
                    $this->isRetryableStatus($status) &&
                    $attempt < 3
                ) {
                    $this->retryDelay($attempt);
                    continue;
                }

                throw new RuntimeException($lastError);
            }

            if (!is_array($data)) {
                throw new RuntimeException(
                    'Gemini devolveu uma resposta JSON invalida.'
                );
            }

            $parts = $data['candidates'][0]['content']['parts'] ?? [];

            $content = '';

            foreach ($parts as $part) {
                if (
                    isset($part['text']) &&
                    is_string($part['text'])
                ) {
                    $content .= $part['text'];
                }
            }

            if ($content === '') {
                $reason = $data['candidates'][0]['finishReason']
                    ?? 'sem conteudo';

                throw new RuntimeException(
                    'Gemini devolveu uma resposta vazia (' .
                    $reason . ').'
                );
            }

            return [
                'message' => [
                    'content' => $content,
                ],
                'provider' => 'gemini',
                'model' => $model,
                'usage' => $data['usageMetadata'] ?? [],
            ];
        }

        throw new RuntimeException(
            $lastError ?? 'Falha na comunicacao com o Gemini.'
        );
    }

    /**
     * Processa um evento SSE recebido do Gemini.
     */
    private function processStreamEvent(
        string $event,
        string &$content,
        ?string &$streamError,
        callable $onToken
    ): void {
        $event = trim($event);

        if ($event === '') {
            return;
        }

        $dataLines = [];

        foreach (
            preg_split('/\r\n|\r|\n/', $event) as $line
        ) {
            $line = trim($line);

            if (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, 5));
            }
        }

        if ($dataLines === []) {
            return;
        }

        $decoded = json_decode(
            implode("\n", $dataLines),
            true
        );

        if (!is_array($decoded)) {
            return;
        }

        if (isset($decoded['error'])) {
            $streamError = (string) (
                $decoded['error']['message']
                ?? 'Erro no streaming Gemini.'
            );

            return;
        }

        $parts = $decoded['candidates'][0]['content']['parts']
            ?? [];

        foreach ($parts as $part) {
            $token = $part['text'] ?? null;

            if (
                is_string($token) &&
                $token !== ''
            ) {
                $content .= $token;

                $onToken($token);
            }
        }
    }

    /**
     * Streaming com captura de erros e retry seguro.
     */
    public function chatStream(
        array $messages,
        callable $onToken,
        ?string $model = null
    ): array {
        $model = $this->model($model);

        $payload = json_encode(
            $this->prepareMessages($messages),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            throw new RuntimeException(
                'Nao foi possivel preparar o streaming Gemini.'
            );
        }

        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $buffer = '';
            $content = '';
            $streamError = null;

            // Captura o corpo HTTP para diagnosticar erros.
            $rawResponse = '';

            $ch = curl_init(
                $this->requestUrl(
                    $model,
                    'streamGenerateContent',
                    true
                )
            );

            if ($ch === false) {
                throw new RuntimeException(
                    'Nao foi possivel iniciar o streaming Gemini.'
                );
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,

                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                    'x-goog-api-key: ' . $this->apiKey(),
                ],

                CURLOPT_POSTFIELDS => $payload,

                CURLOPT_CONNECTTIMEOUT => 10,

                CURLOPT_TIMEOUT => (int) env(
                    'GEMINI_TIMEOUT',
                    90
                ),

                CURLOPT_WRITEFUNCTION =>
                    function (
                        $handle,
                        string $chunk
                    ) use (
                        &$buffer,
                        &$rawResponse,
                        &$content,
                        &$streamError,
                        $onToken
                    ): int {
                        // Guarda uma amostra do corpo HTTP.
                        if (strlen($rawResponse) < 65536) {
                            $remaining = 65536 -
                                strlen($rawResponse);

                            $rawResponse .= substr(
                                $chunk,
                                0,
                                $remaining
                            );
                        }

                        $buffer .= $chunk;

                        // Suporta LF, CRLF e CR.
                        while (
                            preg_match(
                                '/\r\n\r\n|\n\n|\r\r/',
                                $buffer,
                                $matches,
                                PREG_OFFSET_CAPTURE
                            )
                        ) {
                            $separator = $matches[0][0];
                            $position = $matches[0][1];

                            $event = substr(
                                $buffer,
                                0,
                                $position
                            );

                            $buffer = substr(
                                $buffer,
                                $position + strlen($separator)
                            );

                            $this->processStreamEvent(
                                $event,
                                $content,
                                $streamError,
                                $onToken
                            );
                        }

                        return strlen($chunk);
                    },
            ]);

            $result = curl_exec($ch);

            $errno = curl_errno($ch);
            $error = curl_error($ch);

            $status = (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            curl_close($ch);

            // Processa o ultimo evento incompleto.
            if ($buffer !== '' && $status < 400) {
                $this->processStreamEvent(
                    $buffer,
                    $content,
                    $streamError,
                    $onToken
                );
            }

            // Erros de transporte.
            if ($errno !== 0) {
                $lastError =
                    'Gemini nao respondeu durante o streaming: ' .
                    $error;

                // Nunca repetir se ja foram enviados tokens.
                if (
                    $content === '' &&
                    $attempt < 3
                ) {
                    $this->retryDelay($attempt);
                    continue;
                }

                throw new RuntimeException($lastError);
            }

            // Erros HTTP com corpo real.
            if ($status >= 400) {
                $message = $this->extractErrorMessage(
                    $rawResponse
                );

                $lastError = 'Gemini streaming HTTP ' .
                    $status . ': ' . $message;

                if (
                    $this->isRetryableStatus($status) &&
                    $content === '' &&
                    $attempt < 3
                ) {
                    $this->retryDelay($attempt);
                    continue;
                }

                throw new RuntimeException($lastError);
            }

            // Erro comunicado dentro do SSE.
            if ($streamError !== null) {
                throw new RuntimeException(
                    'Gemini streaming: ' . $streamError
                );
            }

            if ($content === '') {
                throw new RuntimeException(
                    'Gemini terminou o streaming sem conteudo.'
                );
            }

            return [
                'message' => [
                    'content' => $content,
                ],
                'provider' => 'gemini',
                'model' => $model,
            ];
        }

        throw new RuntimeException(
            $lastError ?? 'Falha no streaming Gemini.'
        );
    }
}

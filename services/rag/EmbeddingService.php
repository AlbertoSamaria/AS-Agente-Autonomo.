<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config/config.php';

final class EmbeddingService
{
    public function embed(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Não é possível gerar embedding para texto vazio.');
        }

        $url = (string) env('OLLAMA_EMBEDDING_URL', '');
        if ($url === '') {
            $chatUrl = (string) env(
                'OLLAMA_API_URL',
                'http://127.0.0.1:11434/api/chat'
            );
            $url = str_ends_with($chatUrl, '/api/chat')
                ? substr($chatUrl, 0, -strlen('/api/chat')) . '/api/embed'
                : rtrim($chatUrl, '/') . '/api/embed';
        }

        $model = (string) env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text');
        $payload = json_encode(
            ['model' => $model, 'input' => $text],
            JSON_UNESCAPED_UNICODE
        );
        if ($payload === false) {
            throw new RuntimeException('Não foi possível preparar o pedido de embedding.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar o cliente de embeddings.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) env('OLLAMA_EMBEDDING_TIMEOUT', 60),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('O serviço de embeddings não respondeu: ' . $error);
        }

        $data = json_decode((string) $raw, true);
        $embedding = $data['embeddings'][0] ?? $data['embedding'] ?? null;
        if ($code >= 400 || !is_array($embedding) || $embedding === []) {
            throw new RuntimeException(
                'Não foi possível gerar embeddings com o modelo ' . $model .
                ' (HTTP ' . $code . '). Confirma o serviço Ollama e instala o modelo.'
            );
        }

        foreach ($embedding as $value) {
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new RuntimeException('O serviço devolveu um embedding inválido.');
            }
        }

        return array_map('floatval', array_values($embedding));
    }
}

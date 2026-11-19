<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

final class EmbeddingService
{
    public function embed(string $text, string $taskType = 'RETRIEVAL_QUERY'): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Nao e possivel gerar embedding para texto vazio.');
        }

        // Em produção, se o fornecedor principal do agente for Gemini e a chave existir,
        // usar Gemini Embedding por omissão. Ollama só fica como fallback local explícito.
        $defaultProvider = strtolower((string) env('LLM_PROVIDER', 'ollama')) === 'gemini'
            && trim((string) env('GEMINI_API_KEY', '')) !== ''
            ? 'gemini'
            : 'ollama';
        $provider = strtolower(trim((string) env('EMBEDDING_PROVIDER', $defaultProvider)));
        if ($provider === 'gemini') {
            return $this->geminiEmbedding($text, $taskType);
        }
        if ($provider !== 'ollama') {
            throw new RuntimeException('Fornecedor de embeddings nao suportado: ' . $provider);
        }

        $url = (string) env('OLLAMA_EMBEDDING_URL', '');
        if ($url === '') {
            $chatUrl = (string) env('OLLAMA_API_URL', 'http://127.0.0.1:11434/api/chat');
            $url = str_ends_with($chatUrl, '/api/chat')
                ? substr($chatUrl, 0, -strlen('/api/chat')) . '/api/embed'
                : rtrim($chatUrl, '/') . '/api/embed';
        }

        $model = (string) env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text');
        $payload = json_encode(['model' => $model, 'input' => $text], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new RuntimeException('Nao foi possivel preparar o pedido de embedding.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nao foi possivel inicializar o cliente de embeddings.');
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
            throw new RuntimeException('O servico de embeddings nao respondeu: ' . $error);
        }

        $data = json_decode((string) $raw, true);
        $embedding = $data['embeddings'][0] ?? $data['embedding'] ?? null;
        if ($code >= 400 || !is_array($embedding) || $embedding === []) {
            throw new RuntimeException(
                'Nao foi possivel gerar embeddings com o modelo ' . $model . ' (HTTP ' . $code . ').'
            );
        }

        return $this->validateVector($embedding);
    }

    private function geminiEmbedding(string $text, string $taskType): array
    {
        $key = trim((string) env('GEMINI_API_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('GEMINI_API_KEY nao esta configurada.');
        }

        $model = (string) env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001');
        $dimensions = (int) env('GEMINI_EMBEDDING_DIMENSIONS', 768);
        if (!in_array($dimensions, [768, 1536, 3072], true)) {
            throw new RuntimeException('GEMINI_EMBEDDING_DIMENSIONS deve ser 768, 1536 ou 3072.');
        }
        if (!in_array($taskType, ['RETRIEVAL_QUERY', 'RETRIEVAL_DOCUMENT', 'SEMANTIC_SIMILARITY'], true)) {
            $taskType = 'RETRIEVAL_QUERY';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' .
            rawurlencode($model) . ':embedContent';
        $payload = json_encode([
            'model' => 'models/' . $model,
            'content' => ['parts' => [['text' => $text]]],
            'taskType' => $taskType,
            'outputDimensionality' => $dimensions,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Nao foi possivel preparar o pedido Gemini Embedding.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Nao foi possivel iniciar o cliente Gemini Embedding.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $key,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => (int) env('GEMINI_EMBEDDING_TIMEOUT', 60),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('Gemini Embedding nao respondeu: ' . $error);
        }

        $data = json_decode((string) $raw, true);
        $vector = $data['embedding']['values'] ?? null;
        if ($status >= 400 || !is_array($vector) || $vector === []) {
            $message = $data['error']['message'] ?? substr((string) $raw, 0, 500);
            throw new RuntimeException('Gemini Embedding HTTP ' . $status . ': ' . $message);
        }

        if (count($vector) !== $dimensions) {
            throw new RuntimeException(
                'Dimensao inesperada do embedding: recebidos ' . count($vector) .
                ', esperados ' . $dimensions . '.'
            );
        }

        return $this->validateVector($vector);
    }

    private function validateVector(array $vector): array
    {
        foreach ($vector as $value) {
            if (!is_numeric($value) || !is_finite((float) $value)) {
                throw new RuntimeException('O servico devolveu um embedding invalido.');
            }
        }
        return array_map('floatval', array_values($vector));
    }
}
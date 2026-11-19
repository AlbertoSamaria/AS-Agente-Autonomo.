<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/EmbeddingService.php';

final class DocumentProcessor
{
    public function process(int $documentId): array
    {
        $initialPdo = db(true);
        $statement = $initialPdo->prepare('SELECT * FROM documents WHERE id = ?');
        $statement->execute([$documentId]);
        $document = $statement->fetch();
        $statement->closeCursor();
        unset($statement, $initialPdo);

        if (!$document) {
            throw new RuntimeException('Documento inexistente.');
        }

        db(true)->prepare("UPDATE documents SET status = 'processing', error_message = NULL WHERE id = ?")
            ->execute([$documentId]);

        try {
            $path = dirname(__DIR__, 2) . '/uploads/' . $document['stored_name'];

            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('O ficheiro do documento não existe ou não pode ser lido.');
            }

            $extractedText = $this->extract($path, (string)($document['mime_type'] ?? ''));

            if (!is_string($extractedText)) {
                throw new RuntimeException('A extracção do documento não devolveu texto válido.');
            }

            $normalizedText = $this->normalizeText($extractedText);

            if ($normalizedText === '') {
                throw new RuntimeException(
                    'Não foi possível extrair texto deste documento. Se for um PDF digitalizado, poderá necessitar de OCR.'
                );
            }

            $chunks = $this->chunk($normalizedText, 1200, 180);

            if ($chunks === []) {
                throw new RuntimeException('Não foi possível dividir o conteúdo do documento em blocos.');
            }

            // Gerar todos os embeddings antes de substituir os blocos existentes.
            // Se a IA falhar, os chunks anteriores permanecem intactos.
            $embeddingService = new EmbeddingService();
            $preparedChunks = [];

            foreach ($chunks as $index => $chunk) {
                $vector = $embeddingService->embed($chunk, 'RETRIEVAL_DOCUMENT');
                $preparedChunks[] = [
                    $index,
                    $chunk,
                    json_encode($vector, JSON_THROW_ON_ERROR)
                ];
            }

            // As chamadas externas de embeddings podem deixar a ligação MySQL
            // inactiva até o alojamento a encerrar. Persistimos com ligação nova
            // e repetimos uma vez apenas em erros de ligação perdida.
            $persisted = false;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $pdo = db(true);
                try {
                    $pdo->beginTransaction();

                    $pdo->prepare('DELETE FROM document_chunks WHERE document_id = ?')
                        ->execute([$documentId]);

                    $insert = $pdo->prepare(
                        'INSERT INTO document_chunks (document_id, chunk_index, content, embedding_json) VALUES (?, ?, ?, ?)'
                    );

                    foreach ($preparedChunks as [$index, $chunk, $embeddingJson]) {
                        $insert->execute([$documentId, $index, $chunk, $embeddingJson]);
                    }

                    $pdo->commit();
                    $persisted = true;
                    break;
                } catch (Throwable $databaseError) {
                    try {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                    } catch (Throwable $rollbackError) {
                        error_log('SA DocumentProcessor rollback: ' . $rollbackError->getMessage());
                    }

                    if ($attempt === 0 && $this->isLostDatabaseConnection($databaseError)) {
                        continue;
                    }
                    throw $databaseError;
                }
            }

            if (!$persisted) {
                throw new RuntimeException('Não foi possível guardar os blocos do documento na base de dados.');
            }

            db(true)->prepare("UPDATE documents SET status = 'ready', error_message = NULL WHERE id = ?")
                ->execute([$documentId]);

            return ['success' => true, 'chunks' => count($chunks)];
        } catch (Throwable $error) {
            // Reconectar: o MySQL pode ter fechado a ligação durante chamadas externas de IA.
            // A falha ao actualizar o estado não deve ocultar a excepção original.
            try {
                db(true)->prepare("UPDATE documents SET status = 'failed', error_message = ? WHERE id = ?")
                    ->execute([mb_substr($error->getMessage(), 0, 2000), $documentId]);
            } catch (Throwable $statusError) {
                error_log('SA DocumentProcessor: não foi possível actualizar o estado do documento: ' . $statusError->getMessage());
            }

            throw $error;
        }
    }

    private function isLostDatabaseConnection(Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection')
            || str_contains($message, 'connection was killed')) {
            return true;
        }

        if ($error instanceof PDOException && isset($error->errorInfo[1])) {
            return in_array((int)$error->errorInfo[1], [2006, 2013], true);
        }

        return false;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
        $text = trim($text);

        // PDFs podem conter bytes binários ou sequências que não são UTF-8.
        if ($text !== '' && preg_match('//u', $text) !== 1) {
            if (function_exists('iconv')) {
                $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                } else {
                    $text = preg_replace('/[\x80-\xFF]/', '', $text) ?? '';
                }
            } elseif (function_exists('mb_convert_encoding')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            } else {
                $text = preg_replace('/[\x80-\xFF]/', '', $text) ?? '';
            }
        }

        // Sem o modificador /u, a limpeza não falha por causa de bytes inválidos.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? '';
        $text = preg_replace('/ *\n */', "\n", $text) ?? '';

        return trim($text);
    }

    private function extract(string $path, string $mime): string
    {
        if (str_contains($mime, 'text/plain') || str_contains($mime, 'text/csv')) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Não foi possível ler o ficheiro de texto.');
            }
            return $content;
        }

        if (str_contains($mime, 'wordprocessingml.document')) {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('O servidor não tem suporte ZIP necessário para ler DOCX.');
            }

            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('DOCX inválido ou danificado.');
            }

            try {
                $xmlContent = $zip->getFromName('word/document.xml');
            } finally {
                $zip->close();
            }

            if ($xmlContent === false) {
                throw new RuntimeException('Não foi possível localizar o conteúdo interno do DOCX.');
            }

            $xmlContent = str_replace(['</w:p>', '</w:tr>'], "\n", $xmlContent);
            return html_entity_decode(strip_tags($xmlContent), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        if (str_contains($mime, 'spreadsheetml.sheet')) {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('O servidor não tem suporte ZIP necessário para ler XLSX.');
            }

            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('XLSX inválido ou danificado.');
            }

            try {
                $sharedStrings = [];
                $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

                if ($sharedXml !== false) {
                    $shared = simplexml_load_string($sharedXml);
                    if ($shared !== false) {
                        foreach ($shared->si as $item) {
                            $sharedStrings[] = (string)($item->t ?? '');
                        }
                    }
                }

                $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
                if ($sheetContent === false) {
                    throw new RuntimeException('Não foi possível localizar a primeira folha do XLSX.');
                }

                $sheet = simplexml_load_string($sheetContent);
                if ($sheet === false) {
                    throw new RuntimeException('A estrutura da folha XLSX é inválida.');
                }

                $rows = [];
                foreach ($sheet->sheetData->row as $row) {
                    $values = [];
                    foreach ($row->c as $cell) {
                        $value = (string)($cell->v ?? '');
                        $type = (string)($cell['t'] ?? '');

                        if ($type === 's' && isset($sharedStrings[(int)$value])) {
                            $value = $sharedStrings[(int)$value];
                        }

                        $values[] = $value;
                    }
                    $rows[] = implode(' | ', $values);
                }

                return implode("\n", $rows);
            } finally {
                $zip->close();
            }
        }

        if ($mime === 'application/pdf') {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('Não foi possível ler o PDF.');
            }

            $text = $this->extractPdfText($raw);

            // O extractor nativo cobre PDFs simples. Para PDFs com object streams,
            // fontes/CMaps complexos ou páginas digitalizadas, usar Gemini como
            // extractor de documento quando a chave cloud está configurada.
            if (trim($text) === '' && trim((string)env('GEMINI_API_KEY', '')) !== '') {
                $text = $this->extractPdfWithGemini($raw);
            }

            if (trim($text) === '') {
                throw new RuntimeException(
                    'Não foi possível extrair texto deste PDF. O PDF pode estar protegido por palavra-passe ou não conter texto legível. Se for digitalizado, confirma que a chave Gemini está configurada para permitir a leitura assistida.'
                );
            }

            return $text;
        }

        throw new RuntimeException('Formato não suportado: ' . $mime);
    }

    /**
     * Extrai texto de PDFs complexos ou digitalizados através da API Gemini.
     * O ficheiro não é guardado pelo fornecedor; é enviado apenas no pedido.
     */
    private function extractPdfWithGemini(string $pdfBytes): string
    {
        $key = trim((string)env('GEMINI_API_KEY', ''));
        if ($key === '') {
            return '';
        }

        // O limite da aplicação é 20 MB; o limite de PDF inline do Gemini
        // é superior a esse valor nos modelos Gemini 3.
        if (strlen($pdfBytes) > 20 * 1024 * 1024) {
            throw new RuntimeException('Este PDF excede 20 MB e não pode ser enviado ao serviço de extracção Gemini.');
        }

        // A extracção de PDFs usa um modelo próprio. Não herdar GEMINI_MODEL,
        // pois essa variável pode continuar configurada com modelos descontinuados.
        $model = trim((string)env('GEMINI_PDF_MODEL', 'gemini-3.8-flash'));
        $model = preg_replace('#^models/#', '', $model) ?: 'gemini-3.8-flash';

        // Corrigir automaticamente configurações antigas que já não aceitam novos pedidos.
        if (in_array(strtolower($model), ['gemini-2.5-flash', 'gemini-2.5-flash-latest'], true)) {
            $model = 'gemini-3.8-flash';
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' .
            rawurlencode($model) . ':generateContent';

        $payload = json_encode([
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => 'Extrai integralmente o texto legível deste PDF. Mantém a ordem original das páginas, títulos, parágrafos, listas, datas, números, tabelas e contactos. Não resumas, não inventes conteúdo e não omitas texto legível. Se uma página for digitalizada ou contiver texto numa imagem, faz OCR visual e transcreve o que conseguires ler. Se não conseguires ler uma parte, indica [texto ilegível]. Devolve apenas o texto extraído.'],
                    ['inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data' => base64_encode($pdfBytes)
                    ]]
                ]
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 8192
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Não foi possível preparar o PDF para extracção Gemini.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar o serviço de extracção Gemini.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $key
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int)env('GEMINI_PDF_TIMEOUT', 180)
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException('O serviço Gemini de leitura de PDF não respondeu: ' . $error);
        }

        $response = json_decode((string)$raw, true);
        if ($status >= 400) {
            $message = $response['error']['message'] ?? ('HTTP ' . $status);
            throw new RuntimeException('Gemini não conseguiu ler o PDF: ' . $message);
        }

        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $textParts = [];
        foreach ($parts as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $textParts[] = $part['text'];
            }
        }

        $text = trim(implode("\n", $textParts));
        if ($text === '') {
            $reason = $response['promptFeedback']['blockReason'] ?? 'resposta vazia';
            throw new RuntimeException('Gemini não devolveu texto extraído do PDF (' . $reason . ').');
        }

        return $text;
    }

    /**
     * Extrai texto de PDFs com streams PDF, incluindo FlateDecode.
     */
    private function extractPdfText(string $raw): string
    {
        $output = [];

        if (preg_match_all('/<<(.*?)>>\\s*stream\\r?\\n(.*?)\\r?\\nendstream/s', $raw, $streams, PREG_SET_ORDER)) {
            foreach ($streams as $stream) {
                $dictionary = $stream[1];
                $data = $stream[2];

                if (str_contains($dictionary, '/FlateDecode')) {
                    $decoded = @gzuncompress($data);

                    if ($decoded === false) {
                        $decoded = @gzinflate($data);
                    }

                    if ($decoded === false) {
                        $decoded = @gzdecode($data);
                    }

                    if ($decoded === false) {
                        continue;
                    }

                    $data = $decoded;
                }

                if (!str_contains($data, 'Tj') && !str_contains($data, 'TJ')) {
                    continue;
                }

                $output[] = $this->decodePdfTextStream($data);
            }
        }

        return trim(implode("\n", $output));
    }

    private function decodePdfTextStream(string $stream): string
    {
        $parts = [];

        if (preg_match_all('/\\((?:\\\\.|[^\\\\()])*\\)\\s*Tj/s', $stream, $matches)) {
            foreach ($matches[0] as $match) {
                $value = preg_replace('/\\s*Tj$/s', '', $match);
                $value = $this->decodePdfLiteralString($value);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        if (preg_match_all('/\\[(.*?)\\]\\s*TJ/s', $stream, $arrays, PREG_SET_ORDER)) {
            foreach ($arrays as $array) {
                if (preg_match_all('/\\((?:\\\\.|[^\\\\()])*\\)/s', $array[1], $strings)) {
                    $line = '';
                    foreach ($strings[0] as $string) {
                        $line .= $this->decodePdfLiteralString($string);
                    }
                    if (trim($line) !== '') {
                        $parts[] = $line;
                    }
                }
            }
        }

        return implode("\n", $parts);
    }

    private function decodePdfLiteralString(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '(' && $value[strlen($value) - 1] === ')') {
            $value = substr($value, 1, -1);
        }

        $value = preg_replace_callback(
            '/\\\\([0-7]{1,3}|n|r|t|b|f|\\\\|\\(|\\))/',
            static function (array $match): string {
                return match ($match[1]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\b",
                    'f' => "\f",
                    '\\\\' => "\\\\",
                    '\\(' => '(',
                    '\\)' => ')',
                    default => chr(octdec($match[1])),
                };
            },
            $value
        ) ?? $value;

        if (str_starts_with($value, "\xFE\xFF") && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding(substr($value, 2), 'UTF-8', 'UTF-16BE');
            if ($converted !== false) {
                return $converted;
            }
        }

        if (preg_match('//u', $value) !== 1 && function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $value;
    }

    private function chunk(string $text, int $size, int $overlap): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $output = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $chunk = mb_substr($text, $start, $size);
            $chunkLength = mb_strlen($chunk);
            $end = $start + $chunkLength;

            if ($end < $length) {
                $boundary = mb_strrpos($chunk, "\n");

                if ($boundary === false || $boundary < (int)($size * 0.65)) {
                    $boundary = mb_strrpos($chunk, ' ');
                }

                if ($boundary !== false && $boundary >= (int)($size * 0.65)) {
                    $chunk = mb_substr($chunk, 0, $boundary);
                    $chunkLength = mb_strlen($chunk);
                }
            }

            $chunk = trim($chunk);
            if ($chunk !== '') {
                $output[] = $chunk;
            }

            $start += max(1, $chunkLength - $overlap);
        }

        return $output;
    }
}

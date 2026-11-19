<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/EmbeddingService.php';

final class DocumentProcessor
{
    public function process(int $documentId): array
    {
        $statement = db()->prepare('SELECT * FROM documents WHERE id = ?');
        $statement->execute([$documentId]);
        $document = $statement->fetch();

        if (!$document) {
            throw new RuntimeException('Documento inexistente.');
        }

        db()->prepare("UPDATE documents SET status = 'processing', error_message = NULL WHERE id = ?")
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

            $pdo = db();
            $pdo->beginTransaction();

            try {
                $pdo->prepare('DELETE FROM document_chunks WHERE document_id = ?')
                    ->execute([$documentId]);

                $insert = $pdo->prepare(
                    'INSERT INTO document_chunks (document_id, chunk_index, content, embedding_json) VALUES (?, ?, ?, ?)'
                );

                foreach ($preparedChunks as [$index, $chunk, $embeddingJson]) {
                    $insert->execute([$documentId, $index, $chunk, $embeddingJson]);
                }

                $pdo->commit();
            } catch (Throwable $databaseError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $databaseError;
            }

            $pdo->prepare("UPDATE documents SET status = 'ready', error_message = NULL WHERE id = ?")
                ->execute([$documentId]);

            return ['success' => true, 'chunks' => count($chunks)];
        } catch (Throwable $error) {
            // Não apagar chunks anteriores: uma falha de reprocessamento não deve destruir dados existentes.
            db()->prepare("UPDATE documents SET status = 'failed', error_message = ? WHERE id = ?")
                ->execute([mb_substr($error->getMessage(), 0, 2000), $documentId]);

            throw $error;
        }
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

            if (trim($text) === '') {
                throw new RuntimeException(
                    'Não foi possível extrair texto deste PDF. O ficheiro pode ser digitalizado, protegido ou usar uma estrutura de texto não suportada.'
                );
            }

            return $text;
        }

        throw new RuntimeException('Formato não suportado: ' . $mime);
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

<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/middleware/RBAC.php';
require_once dirname(__DIR__) . '/services/security/RateLimiter.php';

function upload_error(string $message, int $status): never
{
    json_response(['success' => false, 'error' => $message], $status);
}

function upload_php_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O ficheiro excede o limite de envio configurado no servidor. Tenta um ficheiro menor.',
        UPLOAD_ERR_PARTIAL => 'O envio foi interrompido. Tenta novamente.',
        UPLOAD_ERR_NO_FILE => 'Selecciona um ficheiro antes de enviar.',
        UPLOAD_ERR_NO_TMP_DIR => 'O servidor não tem uma pasta temporária configurada para uploads.',
        UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o ficheiro temporário. Contacta o alojamento.',
        UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP bloqueou o envio do ficheiro.',
        default => 'Ocorreu um erro durante o envio do ficheiro.'
    };
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        upload_error('Método não permitido. Utiliza POST.', 405);
    }

    RBAC::require('rag.write');
    Auth::requireCsrf();

    $user = Auth::user();
    if (!$user) {
        upload_error('Sessão expirada. Inicia sessão novamente.', 401);
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        upload_error('Nenhum ficheiro foi recebido. Confirma o formulário e tenta novamente.', 422);
    }

    $file = $_FILES['file'];
    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $status = in_array($uploadError, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422;
        upload_error(upload_php_error_message($uploadError), $status);
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $originalName = trim((string)($file['name'] ?? ''));
    $size = (int)($file['size'] ?? 0);

    if ($tmpName === '' || !is_uploaded_file($tmpName) || $originalName === '' || $size < 1) {
        upload_error('O ficheiro recebido é inválido ou está vazio.', 422);
    }

    $maxBytes = 20 * 1024 * 1024;
    if ($size > $maxBytes) {
        upload_error('O limite da aplicação é 20 MB por ficheiro. Reduz o tamanho e tenta novamente.', 413);
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
    ];

    if (!isset($allowed[$extension])) {
        upload_error('Formato não permitido. Formatos aceites: PDF, DOCX, XLSX, TXT e CSV.', 415);
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('A extensão Fileinfo do PHP não está activa no alojamento.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpName);
    if (!is_string($mime) || !in_array($mime, $allowed[$extension], true)) {
        upload_error('O conteúdo do ficheiro não corresponde à extensão .' . $extension . '.', 415);
    }

    if (!(new RateLimiter())->allow('upload', 10, 3600, RateLimiter::identity((int)$user['id']))) {
        upload_error('Atingiste o limite de 10 uploads por hora. Tenta novamente mais tarde.', 429);
    }

    $uploadDir = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Não foi possível preparar a pasta de armazenamento dos ficheiros.');
    }
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('A pasta de uploads não tem permissões de escrita no servidor.');
    }

    $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException('O servidor não conseguiu guardar o ficheiro. Verifica o espaço e as permissões do alojamento.');
    }

    try {
        $statement = db()->prepare(
            'INSERT INTO documents (user_id, original_name, stored_name, mime_type, size_bytes, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            (int)$user['id'],
            mb_substr(basename(str_replace('\\', '/', $originalName)), 0, 255),
            $storedName,
            $mime,
            $size,
            'pending'
        ]);
        $documentId = (int)db()->lastInsertId();
    } catch (Throwable $databaseError) {
        if (is_file($destination)) {
            @unlink($destination);
        }
        throw $databaseError;
    }

    json_response([
        'success' => true,
        'document_id' => $documentId,
        'name' => basename(str_replace('\\', '/', $originalName)),
        'size_bytes' => $size,
        'mime_type' => $mime,
        'status' => 'pending'
    ]);
} catch (Throwable $error) {
    error_log('[SA upload] ' . $error->getMessage());
    json_response([
        'success' => false,
        'error' => 'Não foi possível concluir o upload. ' . $error->getMessage()
    ], 500);
}

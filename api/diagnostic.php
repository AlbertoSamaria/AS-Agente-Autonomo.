<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/middleware/RBAC.php';

$user = Auth::requireLogin();
if (($user['role'] ?? '') !== 'admin') {
    json_response(['success' => false, 'error' => 'Acesso reservado ao administrador.'], 403);
}

header('Content-Type: text/plain; charset=utf-8');
echo "SA " . SA_VERSION . "\n";
echo "PHP: " . PHP_VERSION . "\n";
try {
    db()->query('SELECT 1');
    echo "MySQL: OK\n";
} catch (Throwable $error) {
    error_log('[SA Diagnostic] ' . $error->getMessage());
    echo "MySQL: ERRO (consulta o log do servidor).\n";
}

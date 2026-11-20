<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/services/auth/AccountTokenService.php';

$success = false;
$message = 'Este link de confirmação é inválido, expirou ou já foi utilizado.';

$token = trim((string)($_GET['token'] ?? ''));
if ($token !== '') {
    try {
        $pdo = db();
        $pdo->beginTransaction();
        $userId = AccountTokenService::consume($pdo, $token, 'verify_email');
        if ($userId) {
            $statement = $pdo->prepare('SELECT id FROM users WHERE id = ? AND active = 1 LIMIT 1 FOR UPDATE');
            $statement->execute([$userId]);
            if ($statement->fetch()) {
                $pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?')
                    ->execute([$userId]);
                $pdo->prepare(
                    "UPDATE account_tokens SET used_at = NOW() WHERE user_id = ? AND purpose = 'verify_email' AND used_at IS NULL"
                )->execute([$userId]);
                $pdo->commit();
                $success = true;
                $message = 'O teu e-mail foi confirmado. Já podes iniciar sessão no SA.';
            } else {
                $pdo->rollBack();
            }
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $error) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        error_log('SA email verification: ' . $error->getMessage());
        $message = 'Não foi possível confirmar o e-mail neste momento. Tenta novamente mais tarde.';
    }
}
?><!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirmação de e-mail — SA</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:#080c18;color:#eaf0ff;font-family:Arial,sans-serif}.card{width:min(460px,100%);padding:32px;border:1px solid #27314a;border-radius:18px;background:#10172a;text-align:center}.logo{display:grid;place-items:center;width:64px;height:64px;margin:auto;border-radius:14px;background:#eef3ff;color:#0b1020;font-weight:900;font-size:24px}.state{font-size:34px;margin:22px 0 8px;color:<?= $success ? '#39d98a' : '#ff9d96' ?>}.card p{color:#aebbd3;line-height:1.6}.card a{display:inline-block;margin-top:16px;padding:12px 20px;border-radius:10px;background:#eef3ff;color:#0b1020;text-decoration:none;font-weight:700}
</style>
</head>
<body><main class="card"><div class="logo">SA</div><div class="state"><?= $success ? '✓' : '!' ?></div><h1><?= $success ? 'E-mail confirmado' : 'Não foi possível confirmar' ?></h1><p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><a href="index.php">Ir para o login</a></main></body>
</html>

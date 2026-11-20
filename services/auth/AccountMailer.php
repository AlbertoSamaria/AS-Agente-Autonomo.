<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

final class AccountMailer
{
    private static function baseUrl(): string
    {
        $base = rtrim((string)env('APP_BASE_URL', ''), '/');
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            throw new RuntimeException('APP_BASE_URL não está configurado correctamente.');
        }
        return $base;
    }

    private static function send(string $to, string $subject, string $html, string $text): bool
    {
        $from = trim((string)env('MAIL_FROM', ''));
        if ($from === '') {
            return false;
        }

        $provider = strtolower((string)env('MAIL_PROVIDER', 'resend'));
        if ($provider === 'php_mail') {
            $headers = [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . $from,
            ];
            return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
        }

        if ($provider !== 'resend' || !function_exists('curl_init')) {
            return false;
        }

        $apiKey = trim((string)env('RESEND_API_KEY', ''));
        if ($apiKey === '') {
            return false;
        }

        $payload = json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $curl = curl_init('https://api.resend.com/emails');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return $response !== false && $status >= 200 && $status < 300;
    }

    public static function sendVerification(string $email, string $name, string $token): bool
    {
        $url = self::baseUrl() . '/verify.php?token=' . rawurlencode($token);
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#172033">'
            . '<h2>Confirma o teu e-mail no SA</h2><p>Olá ' . $safeName . ',</p>'
            . '<p>Para activar a tua conta, confirma o endereço de e-mail através do botão abaixo.</p>'
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;background:#243b67;color:#fff;text-decoration:none;border-radius:8px">Confirmar e-mail</a></p>'
            . '<p>Este link expira em 24 horas e só pode ser utilizado uma vez.</p>'
            . '<p>Se não criaste esta conta, ignora esta mensagem.</p></div>';
        $text = "Olá {$name}, confirma o teu e-mail no SA: {$url}\nEste link expira em 24 horas.";
        return self::send($email, 'Confirma o teu e-mail — SA', $html, $text);
    }

    public static function sendPasswordReset(string $email, string $name, string $token): bool
    {
        $url = self::baseUrl() . '/index.php?reset=' . rawurlencode($token);
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#172033">'
            . '<h2>Recuperação da palavra-passe do SA</h2><p>Olá ' . $safeName . ',</p>'
            . '<p>Recebemos um pedido para alterar a palavra-passe da tua conta.</p>'
            . '<p><a href="' . $safeUrl . '" style="display:inline-block;padding:12px 18px;background:#243b67;color:#fff;text-decoration:none;border-radius:8px">Definir nova palavra-passe</a></p>'
            . '<p>Este link expira em 1 hora e só pode ser utilizado uma vez. Se não fizeste este pedido, ignora esta mensagem.</p></div>';
        $text = "Olá {$name}, define uma nova palavra-passe do SA: {$url}\nEste link expira em 1 hora.";
        return self::send($email, 'Recuperar palavra-passe — SA', $html, $text);
    }
}

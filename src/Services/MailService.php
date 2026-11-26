<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

final class MailService
{
    /**
     * Send an email with an optional binary attachment (e.g., GST Tax Invoice PDF).
     * Supports SMTP (TLS/SSL/Auth) configured via .env and falls back cleanly when unconfigured or in test mode.
     *
     * @param array<int, array{filename: string, content: string, mime?: string}> $attachments
     * @return array{success: bool, status: string, error?: string}
     */
    public function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        array $attachments = []
    ): array {
        $toEmail = trim($toEmail);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'status' => 'skipped', 'error' => 'Valid recipient email address not available.'];
        }

        if (defined('DOODHBEE_TEST_RUNNER') && DOODHBEE_TEST_RUNNER === true) {
            return ['success' => true, 'status' => 'sent'];
        }

        $fromAddress = (string)Env::get('MAIL_FROM_ADDRESS', 'hello@doodhbee.com');
        $fromName = (string)Env::get('MAIL_FROM_NAME', 'Shri Ram Open Source Technologies');
        $driver = strtolower((string)Env::get('MAIL_DRIVER', 'smtp'));
        $smtpHost = trim((string)Env::get('SMTP_HOST', ''));
        $smtpUser = trim((string)Env::get('SMTP_USERNAME', ''));
        $smtpPass = (string)Env::get('SMTP_PASSWORD', '');
        $smtpPort = (int)Env::get('SMTP_PORT', 587);
        $smtpEnc = strtolower((string)Env::get('SMTP_ENCRYPTION', 'tls'));

        $rawMessage = $this->buildMimeMessage($fromAddress, $fromName, $toEmail, $toName, $subject, $htmlBody, $attachments);

        if ($driver === 'smtp' && $smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
            return $this->sendViaSmtp(
                $smtpHost,
                $smtpPort,
                $smtpEnc,
                $smtpUser,
                $smtpPass,
                $fromAddress,
                $toEmail,
                $rawMessage['headers'],
                $rawMessage['body']
            );
        }

        if ($driver === 'mail' && function_exists('mail')) {
            $headerStr = implode("\r\n", $rawMessage['headers']);
            $ok = @mail($toEmail, $subject, $rawMessage['body'], $headerStr);
            return $ok
                ? ['success' => true, 'status' => 'sent']
                : ['success' => false, 'status' => 'failed', 'error' => 'PHP mail() failed or local MTA is not configured.'];
        }

        return [
            'success' => false,
            'status' => 'skipped',
            'error' => 'SMTP credentials not configured (set SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD in .env).',
        ];
    }

    /**
     * @param array<int, array{filename: string, content: string, mime?: string}> $attachments
     * @return array{headers: array<int, string>, body: string}
     */
    private function buildMimeMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        array $attachments
    ): array {
        $boundary = '=_DB_' . bin2hex(random_bytes(12));
        $safeFromName = str_replace(["\r", "\n"], '', $fromName);
        $safeToName = str_replace(["\r", "\n"], '', $toName);
        $safeSubject = str_replace(["\r", "\n"], '', $subject);

        $headers = [
            "From: \"{$safeFromName}\" <{$fromEmail}>",
            "To: \"{$safeToName}\" <{$toEmail}>",
            "Subject: {$safeSubject}",
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"{$boundary}\"",
        ];

        $parts = [];
        $parts[] = "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($htmlBody));

        foreach ($attachments as $att) {
            $filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)($att['filename'] ?? 'invoice.pdf'));
            $mime = (string)($att['mime'] ?? 'application/pdf');
            $content = (string)($att['content'] ?? '');
            $parts[] = "--{$boundary}\r\n"
                . "Content-Type: {$mime}; name=\"{$filename}\"\r\n"
                . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($content));
        }

        $body = implode("\r\n", $parts) . "\r\n--{$boundary}--\r\n";
        return ['headers' => $headers, 'body' => $body];
    }

    /**
     * @param array<int, string> $headers
     * @return array{success: bool, status: string, error?: string}
     */
    private function sendViaSmtp(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        string $fromEmail,
        string $toEmail,
        array $headers,
        string $body
    ): array {
        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 12);
        if (!$socket) {
            return ['success' => false, 'status' => 'failed', 'error' => "SMTP connect failed: {$errstr} ({$errno})"];
        }

        stream_set_timeout($socket, 12);

        $readResponse = static function ($sock): string {
            $resp = '';
            while (($line = fgets($sock, 512)) !== false) {
                $resp .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $resp;
        };

        $expect = static function ($sock, string $prefix) use ($readResponse): array {
            $resp = $readResponse($sock);
            if (!str_starts_with($resp, $prefix)) {
                return [false, trim($resp)];
            }
            return [true, trim($resp)];
        };

        try {
            [$ok, $msg] = $expect($socket, '220');
            if (!$ok) {
                throw new \RuntimeException("SMTP greeting error: {$msg}");
            }

            fwrite($socket, "EHLO localhost\r\n");
            [$ok, $msg] = $expect($socket, '250');
            if (!$ok) {
                throw new \RuntimeException("SMTP EHLO error: {$msg}");
            }

            if ($encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                [$ok, $msg] = $expect($socket, '220');
                if (!$ok) {
                    throw new \RuntimeException("SMTP STARTTLS error: {$msg}");
                }
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Failed to enable TLS encryption on SMTP socket.');
                }
                fwrite($socket, "EHLO localhost\r\n");
                [$ok, $msg] = $expect($socket, '250');
                if (!$ok) {
                    throw new \RuntimeException("SMTP post-TLS EHLO error: {$msg}");
                }
            }

            fwrite($socket, "AUTH LOGIN\r\n");
            [$ok, $msg] = $expect($socket, '334');
            if (!$ok) {
                throw new \RuntimeException("SMTP AUTH LOGIN error: {$msg}");
            }
            fwrite($socket, base64_encode($username) . "\r\n");
            [$ok, $msg] = $expect($socket, '334');
            if (!$ok) {
                throw new \RuntimeException("SMTP username error: {$msg}");
            }
            fwrite($socket, base64_encode($password) . "\r\n");
            [$ok, $msg] = $expect($socket, '235');
            if (!$ok) {
                throw new \RuntimeException("SMTP authentication error: {$msg}");
            }

            fwrite($socket, "MAIL FROM:<{$fromEmail}>\r\n");
            [$ok, $msg] = $expect($socket, '250');
            if (!$ok) {
                throw new \RuntimeException("SMTP MAIL FROM error: {$msg}");
            }

            fwrite($socket, "RCPT TO:<{$toEmail}>\r\n");
            [$ok, $msg] = $expect($socket, '250');
            if (!$ok) {
                throw new \RuntimeException("SMTP RCPT TO error: {$msg}");
            }

            fwrite($socket, "DATA\r\n");
            [$ok, $msg] = $expect($socket, '354');
            if (!$ok) {
                throw new \RuntimeException("SMTP DATA error: {$msg}");
            }

            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
            fwrite($socket, $payload);
            [$ok, $msg] = $expect($socket, '250');
            if (!$ok) {
                throw new \RuntimeException("SMTP message send error: {$msg}");
            }

            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            return ['success' => true, 'status' => 'sent'];
        } catch (\Throwable $e) {
            if (is_resource($socket)) {
                fclose($socket);
            }
            return ['success' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }
}

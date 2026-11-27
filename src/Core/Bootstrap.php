<?php
declare(strict_types=1);

namespace App\Core;

final class Bootstrap
{
    private static bool $booted = false;

    public static function init(): void
    {
        if (self::$booted) {
            return;
        }

        // 1. Load Environment Configuration
        Env::load();

        // 2. Storage & Logs Directory
        $storageDir = dirname(__DIR__, 2) . '/storage';
        $logDir = "{$storageDir}/logs";
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $logFile = "{$logDir}/error.log";

        // 3. Error Handling and Display Settings
        $isDebug = Env::isDebug();
        $isProduction = Env::isProduction();

        if ($isDebug) {
            ini_set('display_errors', '1');
            ini_set('display_startup_errors', '1');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
            ini_set('error_log', $logFile);
            error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
        }

        // 4. Default Timezone
        $tz = trim((string)Env::get('APP_TIMEZONE', 'Asia/Kolkata'));
        if ($tz === '' || !@date_default_timezone_set($tz)) {
            date_default_timezone_set('Asia/Kolkata');
        }

        // 5. Secure Session Defaults
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

            if ($isHttps) {
                ini_set('session.cookie_secure', '1');
            }
        }

        // 6. Security Headers (Only when not in CLI)
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }

        // 7. Global Exception Handler
        set_exception_handler(function (\Throwable $e) use ($isDebug, $logFile) {
            $msg = sprintf(
                "[%s] Uncaught %s: %s in %s:%d\nStack trace:\n%s\n",
                date('Y-m-d H:i:s'),
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );

            @error_log($msg, 3, $logFile);

            $isApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api')
                || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

            if ($isApi) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'error' => $isDebug ? $e->getMessage() : 'An internal server error occurred.',
                    'code' => 'INTERNAL_ERROR',
                    'trace' => $isDebug ? explode("\n", $e->getTraceAsString()) : null,
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            http_response_code(500);
            if ($isDebug) {
                echo "<div style='font-family: monospace; background: #fff1f2; border: 2px solid #e11d48; padding: 20px; margin: 20px; border-radius: 8px;'>";
                echo "<h2 style='color: #9f1239; margin-top: 0;'>Application Error: " . htmlspecialchars($e->getMessage()) . "</h2>";
                echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
                echo "<pre style='background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #fecdd3; overflow: auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
                echo "</div>";
            } else {
                View::render('errors/500', ['error' => 'An internal server error occurred.'], 'main');
            }
            exit;
        });

        self::$booted = true;
    }
}

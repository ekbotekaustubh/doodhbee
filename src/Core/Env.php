<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static bool $loaded = false;
    private static array $cache = [];

    /**
     * Load environment variables from a .env file.
     * System environment variables take precedence over .env file entries.
     */
    public static function load(?string $dir = null): void
    {
        if (self::$loaded && $dir === null) {
            return;
        }

        $baseDir = $dir ?? dirname(__DIR__, 2);
        $phpEnvPath = rtrim($baseDir, '/') . '/.env.php';

        // 1. Prefer secure PHP-guarded environment file (.env.php) if present
        if (file_exists($phpEnvPath) && is_readable($phpEnvPath)) {
            if (!defined('DOODHBEE_INTERNAL')) {
                define('DOODHBEE_INTERNAL', true);
            }
            $phpVars = require $phpEnvPath;
            if (is_array($phpVars)) {
                foreach ($phpVars as $key => $val) {
                    self::$cache[$key] = $val;
                    $strVal = is_bool($val) ? ($val ? 'true' : 'false') : (string)$val;
                    if (getenv($key) === false) {
                        putenv("{$key}={$strVal}");
                    }
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $val;
                    }
                    if (!isset($_SERVER[$key])) {
                        $_SERVER[$key] = $val;
                    }
                }
                self::$loaded = true;
                return;
            }
        }

        $filePath = rtrim($baseDir, '/') . '/.env';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            self::$loaded = true;
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            self::$loaded = true;
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split into key and value
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);

            // Strip enclosing quotes if present
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            } else {
                // Strip inline comment if unquoted
                $commentPos = strpos($val, '#');
                if ($commentPos !== false) {
                    $val = trim(substr($val, 0, $commentPos));
                }
            }

            self::$cache[$key] = $val;

            // Only set in $_ENV / $_SERVER / getenv if not already set by system/container
            if (getenv($key) === false) {
                putenv("{$key}={$val}");
            }
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
            }
            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = $val;
            }
        }

        self::$loaded = true;
    }

    /**
     * Retrieve an environment variable value with type conversion and default fallback.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        // Normalize string representation of primitives
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return match ($lower) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)' => null,
                'empty', '(empty)' => '',
                default => $value,
            };
        }

        return $value;
    }

    public static function isProduction(): bool
    {
        return strtolower((string)self::get('APP_ENV', 'production')) === 'production';
    }

    public static function isDebug(): bool
    {
        return filter_var(self::get('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
    }
}

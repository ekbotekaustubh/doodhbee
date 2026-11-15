<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    private static string $cacheDir = '';

    private static function initDir(): string
    {
        if (empty(self::$cacheDir)) {
            self::$cacheDir = dirname(__DIR__, 2) . '/storage/cache/rate_limit';
            if (!is_dir(self::$cacheDir)) {
                @mkdir(self::$cacheDir, 0755, true);
            }
        }
        return self::$cacheDir;
    }

    private static function getFilePath(string $key): string
    {
        $hash = hash('sha256', $key);
        return self::initDir() . "/throttle_{$hash}.json";
    }

    /**
     * Check if key is currently rate limited.
     *
     * @param string $key Unique identifier (e.g. "login:127.0.0.1:user@example.com")
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $decaySeconds Window duration in seconds (default: 15 minutes)
     * @return array{limited: bool, remaining: int, retry_after: int}
     */
    public static function check(string $key, int $maxAttempts = 5, int $decaySeconds = 900): array
    {
        $file = self::getFilePath($key);
        if (!file_exists($file)) {
            return [
                'limited' => false,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
            ];
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !isset($data['expires_at'], $data['attempts'])) {
            @unlink($file);
            return [
                'limited' => false,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
            ];
        }

        $now = time();
        if ($now >= $data['expires_at']) {
            @unlink($file);
            return [
                'limited' => false,
                'remaining' => $maxAttempts,
                'retry_after' => 0,
            ];
        }

        $attempts = (int)$data['attempts'];
        $retryAfter = (int)($data['expires_at'] - $now);

        if ($attempts >= $maxAttempts) {
            return [
                'limited' => true,
                'remaining' => 0,
                'retry_after' => max(1, $retryAfter),
            ];
        }

        return [
            'limited' => false,
            'remaining' => max(0, $maxAttempts - $attempts),
            'retry_after' => max(1, $retryAfter),
        ];
    }

    /**
     * Record a failed attempt.
     */
    public static function hit(string $key, int $decaySeconds = 900): int
    {
        $file = self::getFilePath($key);
        $now = time();
        $attempts = 1;
        $expiresAt = $now + $decaySeconds;

        if (file_exists($file)) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data) && isset($data['expires_at']) && $now < $data['expires_at']) {
                $attempts = ((int)($data['attempts'] ?? 0)) + 1;
                $expiresAt = (int)$data['expires_at'];
            }
        }

        file_put_contents($file, json_encode([
            'attempts' => $attempts,
            'expires_at' => $expiresAt,
            'last_hit' => $now,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);

        return $attempts;
    }

    /**
     * Reset attempts on successful authentication.
     */
    public static function reset(string $key): void
    {
        $file = self::getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}

<?php

declare(strict_types=1);

namespace Integrity\Auth;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rate Limiter
 *
 * Implements fixed-window rate limiting for API requests.
 *
 * Uses an atomic check-and-increment query to avoid the TOCTOU race
 * condition that would occur if checking and incrementing were separate
 * operations. Under concurrent load, the previous two-step approach
 * allowed multiple requests to read the same count before any of them
 * incremented it, letting burst traffic exceed the limit.
 */
class RateLimiter
{
    private const WINDOW_SIZE_SECONDS = 3600; // 1 hour window

    /**
     * Atomically check and increment the rate limit counter.
     *
     * Performs a single INSERT … ON DUPLICATE KEY UPDATE query that both
     * increments the counter and returns the new value via LAST_INSERT_ID().
     * This eliminates the TOCTOU race between checking and incrementing.
     *
     * @param int $apiKeyId The API key ID
     * @param int $limit The rate limit (requests per hour)
     * @return array{allowed: bool, remaining: int, reset: int}
     */
    public function checkAndIncrement(int $apiKeyId, int $limit): array
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'integrity_rate_limits';
        $windowStart = $this->getCurrentWindow();
        $resetTime = strtotime($windowStart) + self::WINDOW_SIZE_SECONDS;

        // Atomic upsert: inserts with count=1 or increments the existing
        // count. LAST_INSERT_ID(expr) stores the new count so we can
        // retrieve it without a second round-trip.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $tableName (api_key_id, window_start, request_count)
             VALUES (%d, %s, 1)
             ON DUPLICATE KEY UPDATE request_count = LAST_INSERT_ID(request_count + 1)",
            $apiKeyId,
            $windowStart
        ));

        // For a fresh INSERT, LAST_INSERT_ID() returns the auto-increment
        // id (or 0 if there is no auto-increment column). Detect this by
        // also reading the actual count. This single SELECT is safe
        // because the counter has already been incremented atomically.
        $newCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT request_count FROM $tableName WHERE api_key_id = %d AND window_start = %s",
            $apiKeyId,
            $windowStart
        ));

        if ($newCount > $limit) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $resetTime,
            ];
        }

        return [
            'allowed' => true,
            'remaining' => max(0, $limit - $newCount),
            'reset' => $resetTime,
        ];
    }

    /**
     * Check if a request is allowed under rate limiting (read-only).
     *
     * Does NOT increment the counter. Use checkAndIncrement() for the
     * primary rate-limit flow. This method exists for cases where you
     * need to inspect the current state without consuming a request
     * (e.g., displaying remaining quota).
     *
     * @param int $apiKeyId The API key ID
     * @param int $limit The rate limit (requests per hour)
     * @return array{allowed: bool, remaining: int, reset: int}
     */
    public function checkLimit(int $apiKeyId, int $limit): array
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'integrity_rate_limits';
        $windowStart = $this->getCurrentWindow();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT request_count FROM $tableName WHERE api_key_id = %d AND window_start = %s",
            $apiKeyId,
            $windowStart
        ), ARRAY_A);

        $currentCount = $row ? (int) $row['request_count'] : 0;
        $remaining = max(0, $limit - $currentCount);
        $resetTime = strtotime($windowStart) + self::WINDOW_SIZE_SECONDS;

        if ($currentCount >= $limit) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $resetTime,
            ];
        }

        return [
            'allowed' => true,
            'remaining' => $remaining,
            'reset' => $resetTime,
        ];
    }

    /**
     * Get the current rate limit window start time
     *
     * @return string The window start timestamp (Y-m-d H:00:00)
     */
    private function getCurrentWindow(): string
    {
        return gmdate('Y-m-d H:00:00');
    }

    /**
     * Get rate limit headers for a response
     *
     * @param int $limit The rate limit
     * @param int $remaining The remaining requests
     * @param int $reset The reset timestamp
     * @return array Headers array
     */
    public function getHeaders(int $limit, int $remaining, int $reset): array
    {
        return [
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => max(0, $remaining),
            'X-RateLimit-Reset' => $reset,
        ];
    }
}
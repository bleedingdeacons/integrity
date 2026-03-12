<?php

declare(strict_types=1);

namespace Integrity\Auth;

/**
 * Rate Limiter
 *
 * Implements fixed-window rate limiting for API requests.
 */
class RateLimiter
{
    private const WINDOW_SIZE_SECONDS = 3600; // 1 hour window

    /**
     * Check if a request is allowed under rate limiting
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
            'remaining' => $remaining - 1,
            'reset' => $resetTime,
        ];
    }

    /**
     * Increment the request count for an API key
     *
     * @param int $apiKeyId The API key ID
     * @return void
     */
    public function incrementCount(int $apiKeyId): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . 'integrity_rate_limits';
        $windowStart = $this->getCurrentWindow();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO $tableName (api_key_id, window_start, request_count)
             VALUES (%d, %s, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1",
            $apiKeyId,
            $windowStart
        ));
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
<?php

declare(strict_types=1);

namespace Integrity\Auth;

/**
 * Audit Logger
 *
 * Logs API requests for security auditing and monitoring.
 */
class AuditLogger
{
    /**
     * Log an API request
     *
     * @param int|null $apiKeyId The API key ID (null for failed auth)
     * @param string $endpoint The requested endpoint
     * @param string $method The HTTP method
     * @param array|null $requestParams Sanitized request parameters
     * @param int $responseCode The HTTP response code
     * @param float $responseTime Response time in seconds
     * @return void
     */
    public static function log(
        ?int $apiKeyId,
        string $endpoint,
        string $method,
        ?array $requestParams,
        int $responseCode,
        float $responseTime
    ): void {
        // Check if audit logging is enabled
        if (!get_option('integrity_enable_audit_log', true)) {
            return;
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_audit_log';

        // Sanitize request params - remove any sensitive data
        $sanitizedParams = $requestParams ? self::sanitizeParams($requestParams) : null;

        $wpdb->insert($tableName, [
            'api_key_id' => $apiKeyId,
            'endpoint' => sanitize_text_field($endpoint),
            'method' => sanitize_text_field($method),
            'ip_address' => self::getClientIp(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(substr($_SERVER['HTTP_USER_AGENT'], 0, 500))
                : null,
            'request_params' => $sanitizedParams ? wp_json_encode($sanitizedParams) : null,
            'response_code' => $responseCode,
            'response_time' => $responseTime,
            'created_at' => current_time('mysql'),
        ], [
            '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%f', '%s'
        ]);
    }

    /**
     * Sanitize request parameters to remove sensitive data
     *
     * @param array $params The parameters to sanitize
     * @return array Sanitized parameters
     */
    private static function sanitizeParams(array $params): array
    {
        $sensitiveKeys = [
            'password', 'secret', 'token', 'key', 'api_key',
            'auth', 'credential', 'private', 'ssn', 'credit_card'
        ];

        $sanitized = [];
        foreach ($params as $key => $value) {
            $keyLower = strtolower((string) $key);

            // Check if key contains sensitive keywords
            $isSensitive = false;
            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($keyLower, $sensitiveKey) !== false) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizeParams($value);
            } else {
                // Truncate long values
                $sanitized[$key] = is_string($value) && strlen($value) > 200
                    ? substr($value, 0, 200) . '...'
                    : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get the client's IP address
     *
     * @return string The client IP address
     */
    public static function getClientIp(): string
    {
        // Check for proxy headers (but validate carefully)
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Get audit logs with pagination and filtering
     *
     * @param array $args Query arguments
     * @return array{logs: array, total: int}
     */
    public static function getLogs(array $args = []): array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_audit_log';

        $defaults = [
            'per_page' => 50,
            'page' => 1,
            'api_key_id' => null,
            'endpoint' => null,
            'response_code' => null,
            'ip_address' => null,
            'date_from' => null,
            'date_to' => null,
            'order_by' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $whereParams = [];

        if ($args['api_key_id']) {
            $where[] = 'api_key_id = %d';
            $whereParams[] = $args['api_key_id'];
        }

        if ($args['endpoint']) {
            $where[] = 'endpoint LIKE %s';
            $whereParams[] = '%' . $wpdb->esc_like($args['endpoint']) . '%';
        }

        if ($args['response_code']) {
            $where[] = 'response_code = %d';
            $whereParams[] = $args['response_code'];
        }

        if ($args['ip_address']) {
            $where[] = 'ip_address = %s';
            $whereParams[] = $args['ip_address'];
        }

        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $whereParams[] = $args['date_from'];
        }

        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $whereParams[] = $args['date_to'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countQuery = "SELECT COUNT(*) FROM $tableName WHERE $whereClause";
        if (!empty($whereParams)) {
            $countQuery = $wpdb->prepare($countQuery, $whereParams);
        }
        $total = (int) $wpdb->get_var($countQuery);

        // Get logs
        $orderBy = in_array($args['order_by'], ['created_at', 'response_code', 'response_time'])
            ? $args['order_by']
            : 'created_at';
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        $offset = ($args['page'] - 1) * $args['per_page'];

        $query = "SELECT * FROM $tableName WHERE $whereClause ORDER BY $orderBy $order LIMIT %d OFFSET %d";
        $queryParams = array_merge($whereParams, [$args['per_page'], $offset]);

        $logs = $wpdb->get_results($wpdb->prepare($query, $queryParams), ARRAY_A);

        // Decode JSON params
        foreach ($logs as &$log) {
            $log['request_params'] = $log['request_params']
                ? json_decode($log['request_params'], true)
                : null;
        }

        return [
            'logs' => $logs,
            'total' => $total,
        ];
    }

    /**
     * Get summary statistics for the dashboard
     *
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public static function getStats(int $days = 30): array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_audit_log';

        $dateFrom = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Total requests
        $totalRequests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tableName WHERE created_at >= %s",
            $dateFrom
        ));

        // Successful requests
        $successfulRequests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tableName WHERE created_at >= %s AND response_code >= 200 AND response_code < 300",
            $dateFrom
        ));

        // Failed auth attempts
        $failedAuth = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tableName WHERE created_at >= %s AND response_code = 401",
            $dateFrom
        ));

        // Rate limited requests
        $rateLimited = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $tableName WHERE created_at >= %s AND response_code = 429",
            $dateFrom
        ));

        // Average response time
        $avgResponseTime = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(response_time) FROM $tableName WHERE created_at >= %s",
            $dateFrom
        ));

        // Top endpoints
        $topEndpoints = $wpdb->get_results($wpdb->prepare(
            "SELECT endpoint, COUNT(*) as count 
             FROM $tableName 
             WHERE created_at >= %s 
             GROUP BY endpoint 
             ORDER BY count DESC 
             LIMIT 10",
            $dateFrom
        ), ARRAY_A);

        // Top IPs
        $topIps = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, COUNT(*) as count 
             FROM $tableName 
             WHERE created_at >= %s 
             GROUP BY ip_address 
             ORDER BY count DESC 
             LIMIT 10",
            $dateFrom
        ), ARRAY_A);

        return [
            'total_requests' => $totalRequests,
            'successful_requests' => $successfulRequests,
            'failed_auth' => $failedAuth,
            'rate_limited' => $rateLimited,
            'avg_response_time' => round($avgResponseTime, 4),
            'top_endpoints' => $topEndpoints,
            'top_ips' => $topIps,
            'period_days' => $days,
        ];
    }

    /**
     * Clear audit logs
     *
     * @param int|null $olderThanDays Only clear logs older than this many days (null = all logs)
     * @param int|null $apiKeyId Only clear logs for a specific API key (null = all keys)
     * @return int Number of rows deleted
     */
    public static function clearLogs(?int $olderThanDays = null, ?int $apiKeyId = null): int
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_audit_log';

        $where = [];
        $values = [];

        if ($olderThanDays !== null) {
            $where[] = 'created_at < DATE_SUB(NOW(), INTERVAL %d DAY)';
            $values[] = $olderThanDays;
        }

        if ($apiKeyId !== null) {
            $where[] = 'api_key_id = %d';
            $values[] = $apiKeyId;
        }

        if (empty($where)) {
            // Clear all logs
            $wpdb->query("TRUNCATE TABLE {$tableName}");
            return $wpdb->rows_affected ?? 0;
        }

        $sql = "DELETE FROM {$tableName} WHERE " . implode(' AND ', $where);

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

        $wpdb->query($sql);
        return $wpdb->rows_affected ?? 0;
    }
}
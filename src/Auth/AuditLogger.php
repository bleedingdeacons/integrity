<?php

declare(strict_types=1);

namespace Integrity\Auth;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Audit Logger
 *
 * Logs API requests for security auditing and monitoring.
 *
 * ── IP address resolution ──
 *
 * By default only $_SERVER['REMOTE_ADDR'] is trusted, because proxy
 * headers (X-Forwarded-For, CF-Connecting-IP, X-Real-IP) are trivially
 * spoofable by any client. Since this IP is used for both audit logging
 * AND IP whitelist enforcement (via ApiKeyManager::validateKey), blindly
 * trusting proxy headers would let an attacker bypass IP restrictions by
 * injecting a whitelisted address into the X-Forwarded-For header.
 *
 * When running behind a reverse proxy or CDN (nginx, Cloudflare, AWS ALB),
 * configure trusted proxies in Settings → Integrity → Trusted Proxies so
 * that proxy headers are only read when the direct connection comes from
 * an expected intermediary.
 *
 * Option: integrity_trusted_proxies
 *   - Empty (default): only REMOTE_ADDR is used — safest setting
 *   - Array of IPs/CIDR ranges: proxy headers are read only when
 *     REMOTE_ADDR matches one of the trusted proxy addresses
 *
 * Option: integrity_trusted_proxy_header
 *   - Which header to read when behind a trusted proxy
 *   - Default: 'HTTP_X_FORWARDED_FOR'
 *   - For Cloudflare: 'HTTP_CF_CONNECTING_IP'
 *   - For nginx with realip: 'HTTP_X_REAL_IP'
 */
class AuditLogger
{
    /**
     * Cached list of trusted proxy IPs/CIDR ranges.
     * Loaded once per request from the integrity_trusted_proxies option.
     *
     * @var string[]|null
     */
    private ?array $trustedProxies = null;

    /**
     * Cached proxy header name.
     *
     * @var string|null
     */
    private ?string $trustedProxyHeader = null;

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
    public function log(
        ?int $apiKeyId,
        string $endpoint,
        string $method,
        ?array $requestParams,
        int $responseCode,
        float $responseTime
    ): void {
        if (!get_option('integrity_enable_audit_log', true)) {
            return;
        }

        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_audit_log';

        $sanitizedParams = $requestParams ? $this->sanitizeParams($requestParams) : null;

        $wpdb->insert($tableName, [
            'api_key_id' => $apiKeyId,
            'endpoint' => sanitize_text_field($endpoint),
            'method' => sanitize_text_field($method),
            'ip_address' => $this->getClientIp(),
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
    private function sanitizeParams(array $params): array
    {
        $sensitiveKeys = [
            'password', 'secret', 'token', 'key', 'api_key',
            'auth', 'credential', 'private', 'ssn', 'credit_card'
        ];

        $sanitized = [];
        foreach ($params as $key => $value) {
            $keyLower = strtolower((string) $key);

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
                $sanitized[$key] = $this->sanitizeParams($value);
            } else {
                $sanitized[$key] = is_string($value) && strlen($value) > 200
                    ? substr($value, 0, 200) . '...'
                    : $value;
            }
        }

        return $sanitized;
    }

    /**
     * Get the client's real IP address.
     *
     * Only trusts proxy headers when REMOTE_ADDR matches a configured
     * trusted proxy. Without trusted proxies configured, returns
     * REMOTE_ADDR directly — the only unforgeable source.
     *
     * @return string The client IP address
     */
    public function getClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        // Only consult proxy headers if the direct connection is from
        // a known trusted proxy (CDN, load balancer, reverse proxy).
        $trustedProxies = $this->getTrustedProxies();

        if (!empty($trustedProxies) && $this->isProxyTrusted($remoteAddr, $trustedProxies)) {
            $header = $this->getTrustedProxyHeader();

            if (!empty($_SERVER[$header])) {
                // X-Forwarded-For contains: client, proxy1, proxy2, …
                // The rightmost non-trusted IP is the real client.
                // For simplicity with a single proxy layer, take the
                // leftmost (first) IP, which is the original client.
                $ips = explode(',', $_SERVER[$header]);
                $clientIp = trim($ips[0]);

                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Get the list of trusted proxy IPs/CIDR ranges.
     *
     * @return string[]
     */
    private function getTrustedProxies(): array
    {
        if ($this->trustedProxies === null) {
            $proxies = get_option('integrity_trusted_proxies', []);
            $this->trustedProxies = is_array($proxies) ? array_filter(array_map('trim', $proxies)) : [];
        }

        return $this->trustedProxies;
    }

    /**
     * Get the configured proxy header to read the client IP from.
     *
     * @return string The $_SERVER key to read
     */
    private function getTrustedProxyHeader(): string
    {
        if ($this->trustedProxyHeader === null) {
            $header = get_option('integrity_trusted_proxy_header', 'HTTP_X_FORWARDED_FOR');
            $this->trustedProxyHeader = is_string($header) && $header !== '' ? $header : 'HTTP_X_FORWARDED_FOR';
        }

        return $this->trustedProxyHeader;
    }

    /**
     * Check whether REMOTE_ADDR matches one of the trusted proxies.
     *
     * @param string   $ip      The REMOTE_ADDR value
     * @param string[] $proxies Trusted proxy IPs or CIDR ranges
     * @return bool
     */
    private function isProxyTrusted(string $ip, array $proxies): bool
    {
        foreach ($proxies as $proxy) {
            if (strpos($proxy, '/') !== false) {
                if ($this->ipInCidr($ip, $proxy)) {
                    return true;
                }
            } elseif ($ip === $proxy) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     *
     * @param string $ip   The IP address
     * @param string $cidr The CIDR range (e.g. "10.0.0.0/8")
     * @return bool
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int) $mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $maskBits = (int) $mask;
            $ipHex = bin2hex($ipBin);
            $subnetHex = bin2hex($subnetBin);

            $fullNibbles = intdiv($maskBits, 4);
            $remainderBits = $maskBits % 4;

            if (substr($ipHex, 0, $fullNibbles) !== substr($subnetHex, 0, $fullNibbles)) {
                return false;
            }

            if ($remainderBits > 0) {
                $ipNibble = intval($ipHex[$fullNibbles], 16);
                $subnetNibble = intval($subnetHex[$fullNibbles], 16);
                $nibbleMask = (0xF << (4 - $remainderBits)) & 0xF;

                return ($ipNibble & $nibbleMask) === ($subnetNibble & $nibbleMask);
            }

            return true;
        }

        return false;
    }

    /**
     * Get audit logs with pagination and filtering
     *
     * @param array $args Query arguments
     * @return array{logs: array, total: int}
     */
    public function getLogs(array $args = []): array
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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $countQuery = "SELECT COUNT(*) FROM `" . esc_sql($tableName) . "` WHERE $whereClause";
        if (!empty($whereParams)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $countQuery = $wpdb->prepare($countQuery, $whereParams);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var($countQuery);

        $orderBy = in_array($args['order_by'], ['created_at', 'response_code', 'response_time'])
            ? $args['order_by']
            : 'created_at';
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';
        $offset = ($args['page'] - 1) * $args['per_page'];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; order_by/order are allow-listed above
        $query = "SELECT * FROM `" . esc_sql($tableName) . "` WHERE $whereClause ORDER BY $orderBy $order LIMIT %d OFFSET %d";
        $queryParams = array_merge($whereParams, [$args['per_page'], $offset]);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $logs = $wpdb->get_results($wpdb->prepare($query, $queryParams), ARRAY_A);

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
    public function getStats(int $days = 30): array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_audit_log';

        $dateFrom = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $escapedTable = "`" . esc_sql($tableName) . "`";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $totalRequests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$escapedTable} WHERE created_at >= %s",
            $dateFrom
        ));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $successfulRequests = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$escapedTable} WHERE created_at >= %s AND response_code >= 200 AND response_code < 300",
            $dateFrom
        ));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $failedAuth = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$escapedTable} WHERE created_at >= %s AND response_code = 401",
            $dateFrom
        ));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rateLimited = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$escapedTable} WHERE created_at >= %s AND response_code = 429",
            $dateFrom
        ));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $avgResponseTime = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(response_time) FROM {$escapedTable} WHERE created_at >= %s",
            $dateFrom
        ));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $topEndpoints = $wpdb->get_results($wpdb->prepare(
            "SELECT endpoint, COUNT(*) as count
             FROM {$escapedTable}
             WHERE created_at >= %s
             GROUP BY endpoint
             ORDER BY count DESC
             LIMIT 10",
            $dateFrom
        ), ARRAY_A);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $topIps = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, COUNT(*) as count
             FROM {$escapedTable}
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
    public function clearLogs(?int $olderThanDays = null, ?int $apiKeyId = null): int
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
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be parameterised with prepare(); esc_sql used as defence-in-depth
            $wpdb->query("TRUNCATE TABLE `" . esc_sql($tableName) . "`");
            return $wpdb->rows_affected ?? 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be parameterised with prepare(); esc_sql used as defence-in-depth
        $sql = "DELETE FROM `" . esc_sql($tableName) . "` WHERE " . implode(' AND ', $where);

        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }

        $wpdb->query($sql);
        return $wpdb->rows_affected ?? 0;
    }
}
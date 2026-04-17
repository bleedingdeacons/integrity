<?php

declare(strict_types=1);

namespace Integrity\Auth;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API Key Manager
 *
 * Handles creation, validation, and management of API keys with security best practices.
 */
class ApiKeyManager
{
    private const KEY_LENGTH = 32;
    private const PREFIX_LENGTH = 8;

    /**
     * Generate a new API key
     *
     * @return array{key: string, hash: string, prefix: string}
     */
    public function generateKey(): array
    {
        $keyBytes = random_bytes(self::KEY_LENGTH);
        $key = 'int_' . bin2hex($keyBytes);

        return [
            'key' => $key,
            'hash' => $this->hashKey($key),
            'prefix' => substr($key, 0, self::PREFIX_LENGTH),
        ];
    }

    /**
     * Hash an API key using Argon2id
     *
     * @param string $key The plain text API key
     * @return string The hashed key
     */
    public function hashKey(string $key): string
    {
        return password_hash($key, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 1,
        ]);
    }

    /**
     * Verify an API key against a hash
     *
     * @param string $key The plain text API key
     * @param string $hash The stored hash
     * @return bool Whether the key is valid
     */
    public function verifyKey(string $key, string $hash): bool
    {
        return password_verify($key, $hash);
    }

    /**
     * Return a cached dummy Argon2id hash used to equalise timing on the
     * prefix-miss path in validateKey().
     *
     * Without this, a miss returns in microseconds while a hit spends ~100ms
     * in password_verify(). That gap is a timing oracle for prefix
     * enumeration: the api_key_prefix column is the first 8 chars of
     * 'int_' + hex, so the attacker-controllable portion is only 4 hex chars
     * (~65k values) — small enough to sweep.
     *
     * The hash is computed once per request using the same cost parameters
     * as hashKey() so the cost profile of the miss path matches the hit
     * path exactly.
     */
    private function dummyHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            // Contents are irrelevant — password_verify just needs a
            // well-formed Argon2id hash with matching cost parameters.
            $hash = $this->hashKey('timing-equalisation-placeholder');
        }
        return $hash;
    }

    /**
     * Create a new API key record
     *
     * @param string $name Human-readable name for the key
     * @param array $permissions Array of allowed permissions
     * @param int|null $rateLimit Optional rate limit (requests per hour)
     * @param string|null $expiresAt Optional expiration date (Y-m-d H:i:s)
     * @param array|null $ipWhitelist Optional array of allowed IP addresses/ranges
     * @return array{success: bool, key?: string, id?: int, error?: string}
     */
    public function createKey(
        string $name,
        array $permissions,
        ?int $rateLimit = null,
        ?string $expiresAt = null,
        ?array $ipWhitelist = null
    ): array {
        global $wpdb;

        $keyData = $this->generateKey();
        $tableName = $wpdb->prefix . 'integrity_api_keys';

        $defaultRateLimit = (int) get_option('integrity_default_rate_limit', 1000);

        $result = $wpdb->insert($tableName, [
            'name' => sanitize_text_field($name),
            'api_key_hash' => $keyData['hash'],
            'api_key_prefix' => $keyData['prefix'],
            'permissions' => wp_json_encode($permissions),
            'rate_limit' => $rateLimit ?? $defaultRateLimit,
            'created_at' => current_time('mysql'),
            'expires_at' => $expiresAt ? sanitize_text_field($expiresAt) : null,
            'is_active' => 1,
            'created_by' => get_current_user_id(),
            'ip_whitelist' => $ipWhitelist ? wp_json_encode($ipWhitelist) : null,
        ], [
            '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s'
        ]);

        if ($result === false) {
            return [
                'success' => false,
                'error' => 'Failed to create API key',
            ];
        }

        return [
            'success' => true,
            'key' => $keyData['key'],
            'id' => $wpdb->insert_id,
        ];
    }

    /**
     * Fixed number of password_verify() calls performed per validateKey()
     * invocation. Keeps wall-clock cost independent of both (a) whether a
     * matching prefix exists and (b) how many rows share the prefix bucket.
     *
     * Set generously above the expected collision count for the 4-hex-char
     * variable portion of api_key_prefix. If a bucket legitimately exceeds
     * this, the extra rows are still verified for correctness — the timing
     * leak only re-emerges for unusually large buckets.
     */
    private const VERIFY_ITERATIONS = 8;

    /**
     * Validate an API key and return key data if valid
     *
     * @param string $key The API key to validate
     * @param string|null $clientIp The client's IP address for whitelist validation
     * @return array|null Key data if valid, null otherwise
     */
    public function validateKey(string $key, ?string $clientIp = null): ?array
    {
        global $wpdb;

        $prefix = substr($key, 0, self::PREFIX_LENGTH);
        $tableName = $wpdb->prefix . 'integrity_api_keys';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `" . esc_sql($tableName) . "` WHERE api_key_prefix = %s AND is_active = 1",
            $prefix
        ), ARRAY_A);

        if ($results === null) {
            $results = [];
        }

        // Run a fixed number of password_verify() calls regardless of how
        // many rows matched the prefix. This prevents both the miss-vs-hit
        // timing oracle and the bucket-size oracle (where an attacker
        // infers how many active keys share a prefix from how many
        // verifies ran). We do *not* short-circuit on first match — the
        // post-loop checks (expiry, IP whitelist) and the DB update are
        // deferred until after the fixed-cost work is done, so hit and
        // miss paths take the same time.
        $matchedRow = null;
        $realCount = count($results);
        $iterations = max(self::VERIFY_ITERATIONS, $realCount);

        for ($i = 0; $i < $iterations; $i++) {
            if ($i < $realCount) {
                $row  = $results[$i];
                $hash = $row['api_key_hash'];
                if (password_verify($key, $hash) && $matchedRow === null) {
                    $matchedRow = $row;
                }
            } else {
                // Padding iteration: cost-equivalent verify with no effect.
                password_verify($key, $this->dummyHash());
            }
        }

        if ($matchedRow === null) {
            return null;
        }

        if ($matchedRow['expires_at'] && strtotime($matchedRow['expires_at']) < time()) {
            return null;
        }

        if ($matchedRow['ip_whitelist'] && $clientIp) {
            $whitelist = json_decode($matchedRow['ip_whitelist'], true);
            if (!empty($whitelist) && !$this->isIpAllowed($clientIp, $whitelist)) {
                return null;
            }
        }

        $wpdb->update(
            $tableName,
            [
                'last_used' => current_time('mysql'),
                'request_count' => $matchedRow['request_count'] + 1,
            ],
            ['id' => $matchedRow['id']],
            ['%s', '%d'],
            ['%d']
        );

        $matchedRow['permissions'] = json_decode($matchedRow['permissions'], true);
        return $matchedRow;
    }

    /**
     * Check if an IP address is in the whitelist
     *
     * @param string $ip The IP address to check
     * @param array $whitelist Array of allowed IPs/CIDR ranges
     * @return bool Whether the IP is allowed
     */
    private function isIpAllowed(string $ip, array $whitelist): bool
    {
        foreach ($whitelist as $allowed) {
            if (strpos($allowed, '/') !== false) {
                if ($this->ipInCidr($ip, $allowed)) {
                    return true;
                }
            } elseif ($ip === $allowed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if an IP is within a CIDR range
     *
     * @param string $ip The IP address
     * @param string $cidr The CIDR range
     * @return bool Whether the IP is in the range
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $maskBits = (int)$mask;
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
     * Revoke an API key
     *
     * @param int $keyId The key ID to revoke
     * @return bool Whether the operation succeeded
     */
    public function revokeKey(int $keyId): bool
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_api_keys';

        $result = $wpdb->update(
            $tableName,
            ['is_active' => 0],
            ['id' => $keyId],
            ['%d'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Delete an API key permanently
     *
     * @param int $keyId The key ID to delete
     * @return bool Whether the operation succeeded
     */
    public function deleteKey(int $keyId): bool
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_api_keys';

        $result = $wpdb->delete(
            $tableName,
            ['id' => $keyId],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get all API keys (without sensitive data)
     *
     * @return array List of API keys
     */
    public function getAllKeys(): array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_api_keys';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $results = $wpdb->get_results(
            "SELECT id, name, api_key_prefix, permissions, rate_limit, last_used,
                    request_count, created_at, expires_at, is_active, created_by, ip_whitelist
             FROM `" . esc_sql($tableName) . "`
             ORDER BY created_at DESC",
            ARRAY_A
        );

        foreach ($results as &$row) {
            $row['permissions'] = json_decode($row['permissions'], true);
            $row['ip_whitelist'] = $row['ip_whitelist'] ? json_decode($row['ip_whitelist'], true) : null;
        }

        return $results;
    }

    /**
     * Get a single API key by ID (without sensitive data)
     *
     * @param int $keyId The key ID
     * @return array|null Key data or null if not found
     */
    public function getKey(int $keyId): ?array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_api_keys';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; cannot be parameterised with prepare()
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, api_key_prefix, permissions, rate_limit, last_used,
                    request_count, created_at, expires_at, is_active, created_by, ip_whitelist
             FROM `" . esc_sql($tableName) . "`
             WHERE id = %d",
            $keyId
        ), ARRAY_A);

        if ($row) {
            $row['permissions'] = json_decode($row['permissions'], true);
            $row['ip_whitelist'] = $row['ip_whitelist'] ? json_decode($row['ip_whitelist'], true) : null;
        }

        return $row;
    }

    /**
     * Update an API key
     *
     * @param int $keyId The key ID to update
     * @param array $data The data to update
     * @return bool Whether the operation succeeded
     */
    public function updateKey(int $keyId, array $data): bool
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'integrity_api_keys';

        $updateData = [];
        $formats = [];

        if (isset($data['name'])) {
            $updateData['name'] = sanitize_text_field($data['name']);
            $formats[] = '%s';
        }

        if (isset($data['permissions'])) {
            $updateData['permissions'] = wp_json_encode($data['permissions']);
            $formats[] = '%s';
        }

        if (isset($data['rate_limit'])) {
            $updateData['rate_limit'] = (int) $data['rate_limit'];
            $formats[] = '%d';
        }

        if (isset($data['expires_at'])) {
            $updateData['expires_at'] = $data['expires_at'] ? sanitize_text_field($data['expires_at']) : null;
            $formats[] = '%s';
        }

        if (isset($data['is_active'])) {
            $updateData['is_active'] = (int) $data['is_active'];
            $formats[] = '%d';
        }

        if (isset($data['ip_whitelist'])) {
            $updateData['ip_whitelist'] = $data['ip_whitelist'] ? wp_json_encode($data['ip_whitelist']) : null;
            $formats[] = '%s';
        }

        if (empty($updateData)) {
            return false;
        }

        $result = $wpdb->update(
            $tableName,
            $updateData,
            ['id' => $keyId],
            $formats,
            ['%d']
        );

        return $result !== false;
    }
}
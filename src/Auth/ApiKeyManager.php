<?php

declare(strict_types=1);

namespace Integrity\Auth;

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
            'threads' => 3,
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

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $tableName WHERE api_key_prefix = %s AND is_active = 1",
            $prefix
        ), ARRAY_A);

        if (empty($results)) {
            return null;
        }

        foreach ($results as $row) {
            if ($this->verifyKey($key, $row['api_key_hash'])) {
                if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
                    return null;
                }

                if ($row['ip_whitelist'] && $clientIp) {
                    $whitelist = json_decode($row['ip_whitelist'], true);
                    if (!empty($whitelist) && !$this->isIpAllowed($clientIp, $whitelist)) {
                        return null;
                    }
                }

                $wpdb->update(
                    $tableName,
                    [
                        'last_used' => current_time('mysql'),
                        'request_count' => $row['request_count'] + 1,
                    ],
                    ['id' => $row['id']],
                    ['%s', '%d'],
                    ['%d']
                );

                $row['permissions'] = json_decode($row['permissions'], true);
                return $row;
            }
        }

        return null;
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

            $fullBytes = (int)($maskBits / 4);
            return substr($ipHex, 0, $fullBytes) === substr($subnetHex, 0, $fullBytes);
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

        $results = $wpdb->get_results(
            "SELECT id, name, api_key_prefix, permissions, rate_limit, last_used,
                    request_count, created_at, expires_at, is_active, created_by, ip_whitelist
             FROM $tableName
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

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, api_key_prefix, permissions, rate_limit, last_used,
                    request_count, created_at, expires_at, is_active, created_by, ip_whitelist
             FROM $tableName
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
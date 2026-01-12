<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\ApiKeyManager;
use Integrity\Tests\TestCase;
use WP_Mock;
use Mockery;

/**
 * Unit tests for ApiKeyManager
 */
class ApiKeyManagerTest extends TestCase
{
    /**
     * @test
     */
    public function generateKey_returns_array_with_required_keys(): void
    {
        $result = ApiKeyManager::generateKey();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('prefix', $result);
    }

    /**
     * @test
     */
    public function generateKey_creates_key_with_int_prefix(): void
    {
        $result = ApiKeyManager::generateKey();

        $this->assertStringStartsWith('int_', $result['key']);
    }

    /**
     * @test
     */
    public function generateKey_creates_key_of_expected_length(): void
    {
        $result = ApiKeyManager::generateKey();

        // int_ (4) + 64 hex chars (32 bytes) = 68 characters
        $this->assertEquals(68, strlen($result['key']));
    }

    /**
     * @test
     */
    public function generateKey_creates_unique_keys(): void
    {
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $result = ApiKeyManager::generateKey();
            $keys[] = $result['key'];
        }

        // All keys should be unique
        $this->assertEquals(count($keys), count(array_unique($keys)));
    }

    /**
     * @test
     */
    public function generateKey_prefix_is_first_8_chars(): void
    {
        $result = ApiKeyManager::generateKey();

        $this->assertEquals(substr($result['key'], 0, 8), $result['prefix']);
    }

    /**
     * @test
     */
    public function hashKey_returns_non_empty_string(): void
    {
        $hash = ApiKeyManager::hashKey('int_test_key_12345');

        $this->assertIsString($hash);
        $this->assertNotEmpty($hash);
    }

    /**
     * @test
     */
    public function hashKey_returns_different_hash_for_different_keys(): void
    {
        $hash1 = ApiKeyManager::hashKey('int_test_key_12345');
        $hash2 = ApiKeyManager::hashKey('int_test_key_67890');

        $this->assertNotEquals($hash1, $hash2);
    }

    /**
     * @test
     */
    public function hashKey_uses_argon2id(): void
    {
        $hash = ApiKeyManager::hashKey('int_test_key_12345');

        // Argon2id hashes start with $argon2id$
        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    /**
     * @test
     */
    public function verifyKey_returns_true_for_valid_key(): void
    {
        $key = 'int_test_key_12345';
        $hash = ApiKeyManager::hashKey($key);

        $this->assertTrue(ApiKeyManager::verifyKey($key, $hash));
    }

    /**
     * @test
     */
    public function verifyKey_returns_false_for_invalid_key(): void
    {
        $key = 'int_test_key_12345';
        $hash = ApiKeyManager::hashKey($key);

        $this->assertFalse(ApiKeyManager::verifyKey('int_wrong_key', $hash));
    }

    /**
     * @test
     */
    public function verifyKey_returns_false_for_empty_key(): void
    {
        $hash = ApiKeyManager::hashKey('int_test_key_12345');

        $this->assertFalse(ApiKeyManager::verifyKey('', $hash));
    }

    /**
     * @test
     */
    public function verifyKey_is_timing_safe(): void
    {
        $key = 'int_test_key_12345';
        $hash = ApiKeyManager::hashKey($key);

        // Measure time for correct key
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            ApiKeyManager::verifyKey($key, $hash);
        }
        $correctTime = microtime(true) - $start;

        // Measure time for wrong key (same length)
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            ApiKeyManager::verifyKey('int_wrong_key_1234', $hash);
        }
        $wrongTime = microtime(true) - $start;

        // Times should be similar (within 50% of each other)
        // This is a basic timing attack check
        $ratio = max($correctTime, $wrongTime) / min($correctTime, $wrongTime);
        $this->assertLessThan(2.0, $ratio, 'Timing difference too large, possible timing attack vulnerability');
    }

    /**
     * @test
     */
    public function createKey_with_valid_data_calls_wpdb_insert(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);
        
        $wpdb->insert_id = 1;

        WP_Mock::userFunction('get_option')
            ->with('integrity_default_rate_limit', 1000)
            ->andReturn(1000);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnArg(0);

        WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(function ($data) {
                return json_encode($data);
            });

        WP_Mock::userFunction('current_time')
            ->with('mysql')
            ->andReturn('2024-01-01 00:00:00');

        WP_Mock::userFunction('get_current_user_id')
            ->andReturn(1);

        $result = ApiKeyManager::createKey('Test Key', ['groups:read']);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('id', $result);
    }

    /**
     * @test
     */
    public function createKey_returns_error_on_database_failure(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);

        WP_Mock::userFunction('get_option')
            ->andReturn(1000);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnArg(0);

        WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(function ($data) {
                return json_encode($data);
            });

        WP_Mock::userFunction('current_time')
            ->andReturn('2024-01-01 00:00:00');

        WP_Mock::userFunction('get_current_user_id')
            ->andReturn(1);

        $result = ApiKeyManager::createKey('Test Key', ['groups:read']);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * @test
     */
    public function revokeKey_updates_is_active_to_zero(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_integrity_api_keys',
                ['is_active' => 0],
                ['id' => 123],
                ['%d'],
                ['%d']
            )
            ->andReturn(1);

        $result = ApiKeyManager::revokeKey(123);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function revokeKey_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(false);

        $result = ApiKeyManager::revokeKey(123);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function deleteKey_removes_record_from_database(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('delete')
            ->once()
            ->with(
                'wp_integrity_api_keys',
                ['id' => 456],
                ['%d']
            )
            ->andReturn(1);

        $result = ApiKeyManager::deleteKey(456);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function deleteKey_returns_false_on_failure(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('delete')
            ->once()
            ->andReturn(false);

        $result = ApiKeyManager::deleteKey(456);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function getAllKeys_returns_array(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $mockKeys = [
            [
                'id' => 1,
                'name' => 'Key 1',
                'api_key_prefix' => 'int_key1',
                'permissions' => '["groups:read"]',
                'rate_limit' => 1000,
                'last_used' => null,
                'request_count' => 0,
                'created_at' => '2024-01-01 00:00:00',
                'expires_at' => null,
                'is_active' => 1,
                'created_by' => 1,
                'ip_whitelist' => null,
            ],
        ];
        
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($mockKeys);

        $result = ApiKeyManager::getAllKeys();

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(['groups:read'], $result[0]['permissions']);
    }

    /**
     * @test
     */
    public function getKey_returns_key_data_when_found(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $mockKey = [
            'id' => 1,
            'name' => 'Test Key',
            'api_key_prefix' => 'int_test',
            'permissions' => '["groups:read","meetings:read"]',
            'rate_limit' => 1000,
            'last_used' => null,
            'request_count' => 50,
            'created_at' => '2024-01-01 00:00:00',
            'expires_at' => null,
            'is_active' => 1,
            'created_by' => 1,
            'ip_whitelist' => null,
        ];
        
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');
        
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn($mockKey);

        $result = ApiKeyManager::getKey(1);

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['id']);
        $this->assertEquals(['groups:read', 'meetings:read'], $result['permissions']);
    }

    /**
     * @test
     */
    public function getKey_returns_null_when_not_found(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');
        
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $result = ApiKeyManager::getKey(999);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function updateKey_updates_specified_fields(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnArg(0);

        WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(function ($data) {
                return json_encode($data);
            });

        $result = ApiKeyManager::updateKey(1, [
            'name' => 'Updated Name',
            'rate_limit' => 2000,
        ]);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function updateKey_returns_false_when_no_data_provided(): void
    {
        $result = ApiKeyManager::updateKey(1, []);

        $this->assertFalse($result);
    }
}

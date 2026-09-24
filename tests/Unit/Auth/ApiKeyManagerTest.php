<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use BleedingDeacons\WpMocks\WpState;
use Integrity\Auth\ApiKeyManager;
use Mockery;

/*
 * Unit tests for ApiKeyManager
 *
 * sanitize_text_field(), wp_json_encode(), current_time() and
 * get_current_user_id() are real functions in wp-mocks, so only the options
 * these paths read still need seeding.
 */

beforeEach(function () {
    // ApiKeyManager's methods were static when these tests were written
    // and are instance methods now; it takes no constructor arguments.
    $this->apiKeyManager = new ApiKeyManager();
});

describe('generateKey', function () {
    it('returns an array with the required keys', function () {
        $result = $this->apiKeyManager->generateKey();

        expect($result)
            ->toBeArray()
            ->toHaveKey('key')
            ->toHaveKey('hash')
            ->toHaveKey('prefix');
    });

    it('creates a key with the int_ prefix', function () {
        $result = $this->apiKeyManager->generateKey();

        expect($result['key'])->toStartWith('int_');
    });

    it('creates a key of the expected length', function () {
        $result = $this->apiKeyManager->generateKey();

        // int_ (4) + 64 hex chars (32 bytes) = 68 characters
        expect(strlen($result['key']))->toEqual(68);
    });

    it('creates unique keys', function () {
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $result = $this->apiKeyManager->generateKey();
            $keys[] = $result['key'];
        }

        // All keys should be unique
        expect(count(array_unique($keys)))->toEqual(count($keys));
    });

    it('uses the first 8 characters as the prefix', function () {
        $result = $this->apiKeyManager->generateKey();

        expect($result['prefix'])->toEqual(substr($result['key'], 0, 8));
    });
});

describe('hashKey', function () {
    it('returns a non-empty string', function () {
        $hash = $this->apiKeyManager->hashKey('int_test_key_12345');

        expect($hash)
            ->toBeString()
            ->not->toBeEmpty();
    });

    it('returns a different hash for different keys', function () {
        $hash1 = $this->apiKeyManager->hashKey('int_test_key_12345');
        $hash2 = $this->apiKeyManager->hashKey('int_test_key_67890');

        expect($hash2)->not->toEqual($hash1);
    });

    it('uses argon2id', function () {
        $hash = $this->apiKeyManager->hashKey('int_test_key_12345');

        // Argon2id hashes start with $argon2id$
        expect($hash)->toStartWith('$argon2id$');
    });
});

describe('verifyKey', function () {
    it('returns true for a valid key', function () {
        $key = 'int_test_key_12345';
        $hash = $this->apiKeyManager->hashKey($key);

        expect($this->apiKeyManager->verifyKey($key, $hash))->toBeTrue();
    });

    it('returns false for an invalid key', function () {
        $key = 'int_test_key_12345';
        $hash = $this->apiKeyManager->hashKey($key);

        expect($this->apiKeyManager->verifyKey('int_wrong_key', $hash))->toBeFalse();
    });

    it('returns false for an empty key', function () {
        $hash = $this->apiKeyManager->hashKey('int_test_key_12345');

        expect($this->apiKeyManager->verifyKey('', $hash))->toBeFalse();
    });

    it('is timing-safe', function () {
        $key = 'int_test_key_12345';
        $hash = $this->apiKeyManager->hashKey($key);

        // Measure time for correct key
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->apiKeyManager->verifyKey($key, $hash);
        }
        $correctTime = microtime(true) - $start;

        // Measure time for wrong key (same length)
        $start = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->apiKeyManager->verifyKey('int_wrong_key_1234', $hash);
        }
        $wrongTime = microtime(true) - $start;

        // Times should be similar (within 50% of each other)
        // This is a basic timing attack check
        $ratio = max($correctTime, $wrongTime) / min($correctTime, $wrongTime);
        expect($ratio)->toBeLessThan(2.0, 'Timing difference too large, possible timing attack vulnerability');
    });
});

describe('createKey', function () {
    it('calls wpdb insert with valid data', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        $wpdb->insert_id = 1;

        WpState::$options['integrity_default_rate_limit'] = 1000;

        $result = $this->apiKeyManager->createKey('Test Key', ['groups:read']);

        expect($result['success'])->toBeTrue()
            ->and($result)->toHaveKey('key')
            ->and($result)->toHaveKey('id');
    });

    it('returns an error on database failure', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);

        WpState::$options['integrity_default_rate_limit'] = 1000;

        $result = $this->apiKeyManager->createKey('Test Key', ['groups:read']);

        expect($result['success'])->toBeFalse()
            ->and($result)->toHaveKey('error');
    });
});

describe('revokeKey', function () {
    it('updates is_active to zero', function () {
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

        $result = $this->apiKeyManager->revokeKey(123);

        expect($result)->toBeTrue();
    });

    it('returns false on failure', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(false);

        $result = $this->apiKeyManager->revokeKey(123);

        expect($result)->toBeFalse();
    });
});

describe('deleteKey', function () {
    it('removes the record from the database', function () {
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

        $result = $this->apiKeyManager->deleteKey(456);

        expect($result)->toBeTrue();
    });

    it('returns false on failure', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('delete')
            ->once()
            ->andReturn(false);

        $result = $this->apiKeyManager->deleteKey(456);

        expect($result)->toBeFalse();
    });
});

describe('getAllKeys', function () {
    it('returns an array', function () {
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

        $result = $this->apiKeyManager->getAllKeys();

        expect($result)
            ->toBeArray()
            ->toHaveCount(1)
            ->and($result[0]['permissions'])->toEqual(['groups:read']);
    });
});

describe('getKey', function () {
    it('returns the key data when found', function () {
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

        $result = $this->apiKeyManager->getKey(1);

        expect($result)
            ->toBeArray()
            ->toHaveKey('id', 1)
            ->and($result['permissions'])->toEqual(['groups:read', 'meetings:read']);
    });

    it('returns null when not found', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $result = $this->apiKeyManager->getKey(999);

        expect($result)->toBeNull();
    });
});

describe('updateKey', function () {
    it('updates the specified fields', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $result = $this->apiKeyManager->updateKey(1, [
            'name' => 'Updated Name',
            'rate_limit' => 2000,
        ]);

        expect($result)->toBeTrue();
    });

    it('returns false when no data is provided', function () {
        $result = $this->apiKeyManager->updateKey(1, []);

        expect($result)->toBeFalse();
    });
});

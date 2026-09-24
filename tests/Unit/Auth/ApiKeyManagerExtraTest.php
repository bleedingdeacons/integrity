<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\ApiKeyManager;
use Mockery;
use ReflectionMethod;

/*
 * Covers ApiKeyManager's validation, IP/CIDR matching and CRUD paths beyond
 * the key-generation basics in ApiKeyManagerTest. A mocked $wpdb stands in for
 * the database.
 */

covers(ApiKeyManager::class);

function apiKeyExtraMethod(string $method): ReflectionMethod
{
    // No setAccessible() call: it has been a no-op since PHP 8.1 — this
    // plugin's floor — and is deprecated from 8.5.
    return new ReflectionMethod(ApiKeyManager::class, $method);
}

function apiKeyExtraWpdb(): object
{
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($q) => $q)->byDefault();
    $GLOBALS['wpdb'] = $wpdb;
    return $wpdb;
}

function apiKeyExtraRow(ApiKeyManager $manager, string $key, array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'api_key_hash' => $manager->hashKey($key),
        'expires_at' => null,
        'ip_whitelist' => null,
        'request_count' => 4,
        'permissions' => json_encode(['members:read']),
    ], $overrides);
}

beforeEach(function () {
    $this->manager = new ApiKeyManager();

    // esc_sql(), current_time(), sanitize_text_field() and wp_json_encode()
    // are all real functions in wp-mocks, with the behaviour these tests
    // used to spell out by hand.
});

// ─── ipInCidr / isIpAllowed ──────────────────────────────────────
describe('ipInCidr / isIpAllowed', function () {
    it('matches IPv4 ranges', function () {
        $m = apiKeyExtraMethod('ipInCidr');
        expect($m->invoke($this->manager, '192.168.1.5', '192.168.1.0/24'))->toBeTrue()
            ->and($m->invoke($this->manager, '10.0.0.1', '192.168.1.0/24'))->toBeFalse()
            ->and($m->invoke($this->manager, 'not-an-ip', '192.168.1.0/24'))->toBeFalse();
    });

    it('matches IPv6 ranges', function () {
        $m = apiKeyExtraMethod('ipInCidr');
        expect($m->invoke($this->manager, '2001:db8::1', '2001:db8::/32'))->toBeTrue()
            ->and($m->invoke($this->manager, '2001:dead::1', '2001:db8::/32'))->toBeFalse()
            // Non-nibble-aligned prefix exercises the remainder-bits branch.
            ->and($m->invoke($this->manager, '2001:db8:0:1::', '2001:db8:0:1::/60'))->toBeTrue();
    });

    /**
     * A /0 entry means "every address" and must say so.
     *
     * It used to compute `-1 << (32 - 0)`. Shifting by the full width is the
     * undefined case, and on a 64-bit build gives -4294967296 rather than 0,
     * so an admin writing 0.0.0.0/0 to mean "allow anything" got a whitelist
     * that matched nothing and locked the key out.
     */
    it('treats a zero prefix as every address', function () {
        $m = apiKeyExtraMethod('ipInCidr');
        expect($m->invoke($this->manager, '8.8.8.8', '0.0.0.0/0'))->toBeTrue()
            ->and($m->invoke($this->manager, '10.0.0.1', '0.0.0.0/0'))->toBeTrue();
    });

    /**
     * A prefix that is absent, empty or out of range is not permission.
     *
     * '10.0.0.0/' cast to 0 and took the shift path above. An oversized IPv6
     * prefix walked past the end of the hex string and read an uninitialised
     * offset.
     */
    it('refuses a malformed prefix', function (string $ip, string $cidr) {
        expect(apiKeyExtraMethod('ipInCidr')->invoke($this->manager, $ip, $cidr))->toBeFalse();
    })->with([
        'empty prefix'      => ['10.0.0.1', '10.0.0.0/'],
        'non-numeric'       => ['10.0.0.1', '10.0.0.0/abc'],
        'negative'          => ['10.0.0.1', '10.0.0.0/-8'],
        'ipv4 out of range' => ['10.0.0.1', '10.0.0.0/33'],
        'ipv6 out of range' => ['2001:db8::1', '2001:db8::/200'],
        'family mismatch'   => ['2001:db8::1', '10.0.0.0/24'],
    ]);

    it('handles exact and CIDR entries in isIpAllowed', function () {
        $m = apiKeyExtraMethod('isIpAllowed');
        expect($m->invoke($this->manager, '10.0.0.7', ['10.0.0.7']))->toBeTrue()
            ->and($m->invoke($this->manager, '192.168.1.9', ['192.168.1.0/24']))->toBeTrue()
            ->and($m->invoke($this->manager, '8.8.8.8', ['10.0.0.7', '192.168.1.0/24']))->toBeFalse();
    });
});

// ─── validateKey ─────────────────────────────────────────────────
describe('validateKey', function () {
    it('refuses a malformed key without touching the database', function (string $key) {
        $wpdb = apiKeyExtraWpdb();
        // No get_results() expectation: reaching the query at all is the
        // failure. A malformed key must cost neither a round-trip nor any
        // Argon2id time.
        $wpdb->shouldNotReceive('get_results');

        expect($this->manager->validateKey($key))->toBeNull();
    })->with([
        'empty'            => [''],
        'wrong prefix'     => ['key_' . str_repeat('a', 64)],
        'no prefix'        => [str_repeat('a', 68)],
        'too short'        => ['int_' . str_repeat('a', 63)],
        'too long'         => ['int_' . str_repeat('a', 65)],
        'non-hex body'     => ['int_' . str_repeat('z', 64)],
        'uppercase hex'    => ['int_' . str_repeat('A', 64)],
        'sql-ish'          => ["int_' OR 1=1 -- " . str_repeat('a', 48)],
        'newline injected' => ["int_\n" . str_repeat('a', 63)],
    ]);

    it('accepts in looksLikeKey what generateKey emits', function () {
        $generated = $this->manager->generateKey();

        expect($this->manager->looksLikeKey($generated['key']))->toBeTrue();
    });

    it('still runs the full verify loop for a well-formed but unknown key', function () {
        // The structural gate must not become a shortcut for well-formed
        // candidates: that is the timing oracle dummyHash() exists to close.
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('get_results')->once()->andReturn([]);

        expect($this->manager->validateKey('int_' . str_repeat('e', 64)))->toBeNull();
    });

    it('returns the row on a match', function () {
        $key = 'int_' . str_repeat('a', 64);
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('get_results')->andReturn([apiKeyExtraRow($this->manager, $key)]);
        $wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = $this->manager->validateKey($key);
        expect($result)->toBeArray()
            ->and($result['permissions'])->toBe(['members:read']);
    });

    it('returns null when no row matches', function () {
        $key = 'int_' . str_repeat('b', 64);
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('get_results')->andReturn([]);

        expect($this->manager->validateKey($key))->toBeNull();
    });

    it('returns null for an expired key', function () {
        $key = 'int_' . str_repeat('c', 64);
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('get_results')->andReturn([
            apiKeyExtraRow($this->manager, $key, ['expires_at' => '2000-01-01 00:00:00']),
        ]);

        expect($this->manager->validateKey($key))->toBeNull();
    });

    it('returns null when the client IP is not whitelisted', function () {
        $key = 'int_' . str_repeat('d', 64);
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('get_results')->andReturn([
            apiKeyExtraRow($this->manager, $key, ['ip_whitelist' => json_encode(['10.0.0.0/24'])]),
        ]);

        expect($this->manager->validateKey($key, '8.8.8.8'))->toBeNull();
    });
});

// ─── CRUD ────────────────────────────────────────────────────────
describe('CRUD', function () {
    it('reports revokeKey success from wpdb update', function () {
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('update')->once()->andReturn(1);
        expect($this->manager->revokeKey(5))->toBeTrue();

        $wpdb2 = apiKeyExtraWpdb();
        $wpdb2->shouldReceive('update')->once()->andReturn(false);
        expect($this->manager->revokeKey(5))->toBeFalse();
    });

    it('reports deleteKey success from wpdb delete', function () {
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('delete')->once()->andReturn(1);
        expect($this->manager->deleteKey(5))->toBeTrue();
    });

    it('decodes JSON columns in getAllKeys', function () {
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('get_results')->andReturn([
            ['permissions' => json_encode(['a']), 'ip_whitelist' => json_encode(['1.2.3.4'])],
            ['permissions' => json_encode(['b']), 'ip_whitelist' => null],
        ]);

        $keys = $this->manager->getAllKeys();
        expect($keys[0]['permissions'])->toBe(['a'])
            ->and($keys[0]['ip_whitelist'])->toBe(['1.2.3.4'])
            ->and($keys[1]['ip_whitelist'])->toBeNull();
    });

    it('returns a decoded row or null from getKey', function () {
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('get_row')->andReturn(['permissions' => json_encode(['x']), 'ip_whitelist' => null]);
        expect($this->manager->getKey(1)['permissions'])->toBe(['x']);

        $wpdb2 = apiKeyExtraWpdb();
        $wpdb2->shouldReceive('get_row')->andReturn(null);
        expect($this->manager->getKey(2))->toBeNull();
    });

    it('maps every supported field in updateKey', function () {
        $wpdb = apiKeyExtraWpdb();
        $wpdb->shouldReceive('update')->once()->andReturn(1);

        expect($this->manager->updateKey(1, [
            'name' => 'Renamed',
            'permissions' => ['members:read'],
            'rate_limit' => 500,
            'expires_at' => '2027-01-01 00:00:00',
            'is_active' => 1,
            'ip_whitelist' => ['10.0.0.0/8'],
        ]))->toBeTrue();
    });

    it('returns false from updateKey with no recognised fields', function () {
        apiKeyExtraWpdb();
        expect($this->manager->updateKey(1, ['unknown' => 'x']))->toBeFalse();
    });
});

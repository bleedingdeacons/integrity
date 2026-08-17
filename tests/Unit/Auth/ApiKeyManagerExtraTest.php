<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\ApiKeyManager;
use Integrity\Tests\TestCase;
use Mockery;
use ReflectionMethod;

/**
 * Covers ApiKeyManager's validation, IP/CIDR matching and CRUD paths beyond
 * the key-generation basics in ApiKeyManagerTest. A mocked $wpdb stands in for
 * the database.
 *
 * @covers \Integrity\Auth\ApiKeyManager
 */
class ApiKeyManagerExtraTest extends TestCase
{
    private ApiKeyManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new ApiKeyManager();

        // esc_sql(), current_time(), sanitize_text_field() and wp_json_encode()
        // are all real functions in wp-mocks, with the behaviour these tests
        // used to spell out by hand.
    }

    private function ip(string $method): ReflectionMethod
    {
        // No setAccessible() call: it has been a no-op since PHP 8.1 — this
        // plugin's floor — and is deprecated from 8.5.
        return new ReflectionMethod(ApiKeyManager::class, $method);
    }

    // ─── ipInCidr / isIpAllowed ──────────────────────────────────────

    /** @test */
    public function ip_in_cidr_matches_ipv4_ranges(): void
    {
        $m = $this->ip('ipInCidr');
        $this->assertTrue($m->invoke($this->manager, '192.168.1.5', '192.168.1.0/24'));
        $this->assertFalse($m->invoke($this->manager, '10.0.0.1', '192.168.1.0/24'));
        $this->assertFalse($m->invoke($this->manager, 'not-an-ip', '192.168.1.0/24'));
    }

    /** @test */
    public function ip_in_cidr_matches_ipv6_ranges(): void
    {
        $m = $this->ip('ipInCidr');
        $this->assertTrue($m->invoke($this->manager, '2001:db8::1', '2001:db8::/32'));
        $this->assertFalse($m->invoke($this->manager, '2001:dead::1', '2001:db8::/32'));
        // Non-nibble-aligned prefix exercises the remainder-bits branch.
        $this->assertTrue($m->invoke($this->manager, '2001:db8:0:1::', '2001:db8:0:1::/60'));
    }

    /**
     * A /0 entry means "every address" and must say so.
     *
     * It used to compute `-1 << (32 - 0)`. Shifting by the full width is the
     * undefined case, and on a 64-bit build gives -4294967296 rather than 0,
     * so an admin writing 0.0.0.0/0 to mean "allow anything" got a whitelist
     * that matched nothing and locked the key out.
     *
     * @test
     */
    public function ip_in_cidr_treats_a_zero_prefix_as_every_address(): void
    {
        $m = $this->ip('ipInCidr');
        $this->assertTrue($m->invoke($this->manager, '8.8.8.8', '0.0.0.0/0'));
        $this->assertTrue($m->invoke($this->manager, '10.0.0.1', '0.0.0.0/0'));
    }

    /**
     * A prefix that is absent, empty or out of range is not permission.
     *
     * '10.0.0.0/' cast to 0 and took the shift path above. An oversized IPv6
     * prefix walked past the end of the hex string and read an uninitialised
     * offset.
     *
     * @test
     * @dataProvider malformedCidrProvider
     */
    public function ip_in_cidr_refuses_a_malformed_prefix(string $ip, string $cidr): void
    {
        $this->assertFalse($this->ip('ipInCidr')->invoke($this->manager, $ip, $cidr));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedCidrProvider(): array
    {
        return [
            'empty prefix'      => ['10.0.0.1', '10.0.0.0/'],
            'non-numeric'       => ['10.0.0.1', '10.0.0.0/abc'],
            'negative'          => ['10.0.0.1', '10.0.0.0/-8'],
            'ipv4 out of range' => ['10.0.0.1', '10.0.0.0/33'],
            'ipv6 out of range' => ['2001:db8::1', '2001:db8::/200'],
            'family mismatch'   => ['2001:db8::1', '10.0.0.0/24'],
        ];
    }

    /** @test */
    public function is_ip_allowed_handles_exact_and_cidr_entries(): void
    {
        $m = $this->ip('isIpAllowed');
        $this->assertTrue($m->invoke($this->manager, '10.0.0.7', ['10.0.0.7']));
        $this->assertTrue($m->invoke($this->manager, '192.168.1.9', ['192.168.1.0/24']));
        $this->assertFalse($m->invoke($this->manager, '8.8.8.8', ['10.0.0.7', '192.168.1.0/24']));
    }

    // ─── validateKey ─────────────────────────────────────────────────

    private function wpdb(): object
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($q) => $q)->byDefault();
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    private function keyRow(string $key, array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'api_key_hash' => $this->manager->hashKey($key),
            'expires_at' => null,
            'ip_whitelist' => null,
            'request_count' => 4,
            'permissions' => json_encode(['members:read']),
        ], $overrides);
    }

    /** @test */
    public function validate_key_returns_the_row_on_a_match(): void
    {
        $key = 'int_' . str_repeat('a', 64);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('get_results')->andReturn([$this->keyRow($key)]);
        $wpdb->shouldReceive('update')->once()->andReturn(1);

        $result = $this->manager->validateKey($key);
        $this->assertIsArray($result);
        $this->assertSame(['members:read'], $result['permissions']);
    }

    /** @test */
    public function validate_key_returns_null_when_no_row_matches(): void
    {
        $key = 'int_' . str_repeat('b', 64);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $this->assertNull($this->manager->validateKey($key));
    }

    /** @test */
    public function validate_key_returns_null_for_an_expired_key(): void
    {
        $key = 'int_' . str_repeat('c', 64);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('get_results')->andReturn([
            $this->keyRow($key, ['expires_at' => '2000-01-01 00:00:00']),
        ]);

        $this->assertNull($this->manager->validateKey($key));
    }

    /** @test */
    public function validate_key_returns_null_when_the_client_ip_is_not_whitelisted(): void
    {
        $key = 'int_' . str_repeat('d', 64);
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('get_results')->andReturn([
            $this->keyRow($key, ['ip_whitelist' => json_encode(['10.0.0.0/24'])]),
        ]);

        $this->assertNull($this->manager->validateKey($key, '8.8.8.8'));
    }

    // ─── CRUD ────────────────────────────────────────────────────────

    /** @test */
    public function revoke_key_reports_success_from_wpdb_update(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('update')->once()->andReturn(1);
        $this->assertTrue($this->manager->revokeKey(5));

        $wpdb2 = $this->wpdb();
        $wpdb2->shouldReceive('update')->once()->andReturn(false);
        $this->assertFalse($this->manager->revokeKey(5));
    }

    /** @test */
    public function delete_key_reports_success_from_wpdb_delete(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('delete')->once()->andReturn(1);
        $this->assertTrue($this->manager->deleteKey(5));
    }

    /** @test */
    public function get_all_keys_decodes_json_columns(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('get_results')->andReturn([
            ['permissions' => json_encode(['a']), 'ip_whitelist' => json_encode(['1.2.3.4'])],
            ['permissions' => json_encode(['b']), 'ip_whitelist' => null],
        ]);

        $keys = $this->manager->getAllKeys();
        $this->assertSame(['a'], $keys[0]['permissions']);
        $this->assertSame(['1.2.3.4'], $keys[0]['ip_whitelist']);
        $this->assertNull($keys[1]['ip_whitelist']);
    }

    /** @test */
    public function get_key_returns_a_decoded_row_or_null(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('get_row')->andReturn(['permissions' => json_encode(['x']), 'ip_whitelist' => null]);
        $this->assertSame(['x'], $this->manager->getKey(1)['permissions']);

        $wpdb2 = $this->wpdb();
        $wpdb2->shouldReceive('get_row')->andReturn(null);
        $this->assertNull($this->manager->getKey(2));
    }

    /** @test */
    public function update_key_maps_every_supported_field(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('update')->once()->andReturn(1);

        $this->assertTrue($this->manager->updateKey(1, [
            'name' => 'Renamed',
            'permissions' => ['members:read'],
            'rate_limit' => 500,
            'expires_at' => '2027-01-01 00:00:00',
            'is_active' => 1,
            'ip_whitelist' => ['10.0.0.0/8'],
        ]));
    }

    /** @test */
    public function update_key_returns_false_with_no_recognised_fields(): void
    {
        $this->wpdb();
        $this->assertFalse($this->manager->updateKey(1, ['unknown' => 'x']));
    }
}

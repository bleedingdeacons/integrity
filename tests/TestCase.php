<?php

declare(strict_types=1);

namespace Integrity\Tests;

use BleedingDeacons\WpMocks\TestCase as WpMocksTestCase;
use Mockery;

/**
 * Base TestCase for Integrity plugin tests
 *
 * Brain Monkey's lifecycle, Mockery integration, the WordPress stubs and the
 * assertActionAdded()/assertFilterAdded() helpers all come from
 * bleedingdeacons/wp-mocks, shared across the plugin suite.
 *
 * The esc_sql() and wp_parse_args() stubs this class used to register in
 * setUp() are gone: both are real functions in the shared stub layer, with the
 * same behaviour the assertions here expect.
 */
abstract class TestCase extends WpMocksTestCase
{
    /**
     * Create a mock WP_REST_Request
     *
     * @param array $params Request parameters
     * @param array $headers Request headers
     * @return object
     */
    protected function createMockRequest(array $params = [], array $headers = []): object
    {
        $request = Mockery::mock('WP_REST_Request');

        $request->shouldReceive('get_param')
            ->andReturnUsing(function ($key) use ($params) {
                return $params[$key] ?? null;
            });

        $request->shouldReceive('get_params')
            ->andReturn($params);

        $request->shouldReceive('get_header')
            ->andReturnUsing(function ($key) use ($headers) {
                return $headers[$key] ?? null;
            });

        // WP_REST_Request::get_headers() returns each header as an array of
        // values. The audit logger collects them all to record the request,
        // so a double without this method fails before reaching the assertion.
        $request->shouldReceive('get_headers')
            ->andReturnUsing(function () use ($headers) {
                return array_map(
                    static fn ($value): array => is_array($value) ? $value : [$value],
                    $headers
                );
            });

        $request->shouldReceive('get_route')
            ->andReturn($params['_route'] ?? '/integrity/v1/test');

        $request->shouldReceive('get_method')
            ->andReturn($params['_method'] ?? 'GET');

        $request->shouldReceive('set_param')
            ->andReturnUsing(function ($key, $value) use (&$params) {
                $params[$key] = $value;
            });

        return $request;
    }

    /**
     * Create a mock API key data array
     *
     * @param array $overrides Override default values
     * @return array
     */
    protected function createMockApiKeyData(array $overrides = []): array
    {
        $defaults = [
            'id' => 1,
            'name' => 'Test API Key',
            'api_key_prefix' => 'int_test',
            'permissions' => ['groups:read', 'meetings:read'],
            'rate_limit' => 1000,
            'last_used' => null,
            'request_count' => 0,
            'created_at' => '2024-01-01 00:00:00',
            'expires_at' => null,
            'is_active' => 1,
            'created_by' => 1,
            'ip_whitelist' => null,
        ];

        return array_merge($defaults, $overrides);
    }
}

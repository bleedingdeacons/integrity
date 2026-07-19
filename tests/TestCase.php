<?php

declare(strict_types=1);

namespace Integrity\Tests;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use WP_Mock;

/**
 * Base TestCase for Integrity plugin tests
 *
 * Provides setup and teardown for WP_Mock and Mockery integration.
 *
 * Extends PHPUnit's TestCase and drives WP_Mock by hand, matching Unity and
 * tsml-for-unity. WP_Mock\Tools\TestCase is not used anywhere in the suite,
 * and at the wp_mock 0.4.x pinned here it could not be: it overrides
 * expectOutputString(), which PHPUnit 10 made final, so autoloading it fatals.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();

        // The escaping helpers are pure pass-throughs as far as these tests
        // are concerned: the classes under test call them when interpolating
        // table names into SQL, and without a definition PHP fatals on an
        // undefined function before the assertion is ever reached.
        WP_Mock::passthruFunction('esc_sql');

        // Likewise wp_parse_args: the classes under test use it to apply
        // defaults to an options array, and its real behaviour is what the
        // assertions expect, so stub the behaviour rather than a return value.
        WP_Mock::userFunction('wp_parse_args')
            ->andReturnUsing(
                static fn ($args, $defaults = []): array => array_merge($defaults, (array) $args)
            );
    }

    /**
     * Tear down test environment
     */
    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Assert that WordPress hooks were added correctly
     *
     * @param string $action The action hook name
     * @param callable|array $callback The callback
     * @param int $priority The priority
     * @param int $acceptedArgs Number of accepted arguments
     */
    protected function assertActionAdded(string $action, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->assertTrue(
            WP_Mock::onActionAdded($action)->react($callback, $priority, $acceptedArgs),
            "Failed asserting that action '{$action}' was added."
        );
    }

    /**
     * Assert that WordPress filters were added correctly
     *
     * @param string $filter The filter hook name
     * @param callable|array $callback The callback
     * @param int $priority The priority
     * @param int $acceptedArgs Number of accepted arguments
     */
    protected function assertFilterAdded(string $filter, $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->assertTrue(
            WP_Mock::onFilterAdded($filter)->react($callback, $priority, $acceptedArgs),
            "Failed asserting that filter '{$filter}' was added."
        );
    }

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

<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit;

use Integrity\Plugin;
use Integrity\Tests\TestCase;
use WP_Mock;
use Mockery;

/**
 * Unit tests for Plugin class
 */
class PluginTest extends TestCase
{
    /**
     * @test
     */
    public function init_registers_rest_api_init_action(): void
    {
        WP_Mock::expectActionAdded('rest_api_init', [
            'Integrity\Api\RestController',
            'register'
        ]);

        WP_Mock::userFunction('is_admin')
            ->andReturn(false);

        Plugin::init();

        $this->assertTrue(true); // Verified by mock expectations
    }

    /**
     * @test
     */
    public function init_registers_admin_hooks_when_is_admin(): void
    {
        WP_Mock::expectActionAdded('rest_api_init', Mockery::any());

        WP_Mock::userFunction('is_admin')
            ->andReturn(true);

        // Admin hooks should be registered
        WP_Mock::expectActionAdded('admin_menu', Mockery::any());

        Plugin::init();

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function init_only_initializes_once(): void
    {
        // Reset the static state
        $reflection = new \ReflectionClass(Plugin::class);
        $property = $reflection->getProperty('initialized');
        $property->setAccessible(true);
        $property->setValue(null, false);

        WP_Mock::userFunction('is_admin')
            ->andReturn(false);

        WP_Mock::expectActionAdded('rest_api_init', Mockery::any())
            ->once(); // Should only be called once

        Plugin::init();
        Plugin::init(); // Second call should be no-op

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function addSecurityHeaders_adds_filter(): void
    {
        WP_Mock::expectFilterAdded('rest_pre_serve_request', Mockery::any(), 10, 3);

        Plugin::addSecurityHeaders();

        $this->assertTrue(true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset Plugin initialized state for each test
        $reflection = new \ReflectionClass(Plugin::class);
        $property = $reflection->getProperty('initialized');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }
}

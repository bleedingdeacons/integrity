<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit;

use Closure;
use Integrity\Admin\SettingsPage;
use Integrity\Plugin;
use Integrity\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Unity\Core\Interfaces\Container;
use WP_Mock;

/**
 * Unit tests for Plugin class
 *
 * Plugin::init() takes Unity's container and registers Integrity's services
 * into it, so every test here supplies a container double. The REST routes
 * are wired through a closure that resolves RestController on rest_api_init
 * rather than a [class, method] callable, which is why the hook assertions
 * match on Closure.
 */
class PluginTest extends TestCase
{
    /** @var Container&MockInterface */
    private $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetPluginStatics();

        // Unity is not autoloadable from this plugin's test run, so Mockery
        // invents the Container type. PSR-11 has to be named explicitly or
        // the double will not satisfy Plugin::$container's ContainerInterface
        // type — Unity's Container extends it, but the invented one does not.
        $this->container = Mockery::mock(Container::class, ContainerInterface::class);

        // registerServices() registers a service per controller and auth
        // class. The exact set is Plugin's business, not this test's, so
        // accept any registration.
        $this->container->shouldReceive('register')->andReturnNull();
    }

    protected function tearDown(): void
    {
        // Plugin holds its container and init state statically; leaving
        // either set would make the next test's init() a silent no-op.
        $this->resetPluginStatics();

        parent::tearDown();
    }

    /**
     * @test
     */
    public function init_registers_rest_api_init_action(): void
    {
        WP_Mock::userFunction('is_admin')
            ->andReturn(false);

        // Two callbacks land on rest_api_init: the closure that resolves and
        // registers RestController, and the security-header hook.
        // Any closure will do: WP_Mock indexes hooked callbacks by identity
        // and normalises every Closure to the same key, so a matcher object
        // would never match but a throwaway closure does.
        WP_Mock::expectActionAdded('rest_api_init', function (): void {
        });
        WP_Mock::expectActionAdded('rest_api_init', [Plugin::class, 'addSecurityHeaders']);

        Plugin::init($this->container);

        $this->assertSame($this->container, Plugin::getContainer());
    }

    /**
     * @test
     */
    public function init_registers_admin_hooks_when_is_admin(): void
    {
        WP_Mock::userFunction('is_admin')
            ->andReturn(true);

        // Any closure will do: WP_Mock indexes hooked callbacks by identity
        // and normalises every Closure to the same key, so a matcher object
        // would never match but a throwaway closure does.
        WP_Mock::expectActionAdded('rest_api_init', function (): void {
        });
        WP_Mock::expectActionAdded('rest_api_init', [Plugin::class, 'addSecurityHeaders']);

        $settingsPage = Mockery::mock(SettingsPage::class);
        $settingsPage->shouldReceive('init')->once();

        $this->container->shouldReceive('get')
            ->with(SettingsPage::class)
            ->once()
            ->andReturn($settingsPage);

        Plugin::init($this->container);

        $this->assertSame($this->container, Plugin::getContainer());
    }

    /**
     * @test
     */
    public function init_only_initializes_once(): void
    {
        WP_Mock::userFunction('is_admin')
            ->andReturn(false);

        // Any closure will do: WP_Mock indexes hooked callbacks by identity
        // and normalises every Closure to the same key, so a matcher object
        // would never match but a throwaway closure does.
        WP_Mock::expectActionAdded('rest_api_init', function (): void {
        });
        WP_Mock::expectActionAdded('rest_api_init', [Plugin::class, 'addSecurityHeaders']);

        Plugin::init($this->container);

        // A second init must be a no-op, so the container it is handed should
        // never be touched — and the first one must still be in place.
        $secondContainer = Mockery::mock(Container::class, ContainerInterface::class);
        $secondContainer->shouldNotReceive('register');
        $secondContainer->shouldNotReceive('get');

        Plugin::init($secondContainer);

        $this->assertSame($this->container, Plugin::getContainer());
    }

    /**
     * @test
     */
    public function addSecurityHeaders_adds_filter(): void
    {
        // add_filter cannot be stubbed with userFunction — WP_Mock intercepts
        // the hook functions itself — so the registration is asserted through
        // WP_Mock's own expectation, verified when it tears down. Priority 10
        // and 3 arguments: rest_pre_serve_request passes $served, $result,
        // $request.
        WP_Mock::expectFilterAdded(
            'rest_pre_serve_request',
            function (): void {
            },
            10,
            3
        );

        Plugin::addSecurityHeaders();

        $this->addToAssertionCount(1);
    }

    /**
     * Reset Plugin's static init state between tests.
     */
    private function resetPluginStatics(): void
    {
        $reflection = new ReflectionClass(Plugin::class);

        // No setAccessible() call: it has been a no-op since PHP 8.1, which
        // is this plugin's floor, and is deprecated as of 8.5.
        $reflection->getProperty('initialized')->setValue(null, false);
        $reflection->getProperty('container')->setValue(null, null);
    }
}

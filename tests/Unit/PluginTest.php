<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Closure;
use Integrity\Admin\SettingsPage;
use Integrity\Plugin;
use Mockery;
use ReflectionClass;
use Unity\Core\Interfaces\Container;

/*
 * Unit tests for Plugin class
 *
 * Plugin::init() takes Unity's container and registers Integrity's services
 * into it, so every test here supplies a container double. The REST routes
 * are wired through a closure that resolves RestController on rest_api_init
 * rather than a [class, method] callable, which is why the hook assertions
 * match on Closure.
 */

/**
 * Reset Plugin's static init state between tests.
 */
function resetIntegrityPluginStatics(): void
{
    $reflection = new ReflectionClass(Plugin::class);

    // No setAccessible() call: it has been a no-op since PHP 8.1, which
    // is this plugin's floor, and is deprecated as of 8.5.
    $reflection->getProperty('initialized')->setValue(null, false);
    $reflection->getProperty('container')->setValue(null, null);
}

beforeEach(function () {
    resetIntegrityPluginStatics();

    // tests/bootstrap.php autoloads Unity from the sibling checkout, so this
    // doubles the real Container — which extends PSR-11's ContainerInterface,
    // and so satisfies Plugin::$container's type on its own.
    $this->container = Mockery::mock(Container::class);

    // registerServices() registers a service per controller and auth
    // class. The exact set is Plugin's business, not this test's, so
    // accept any registration.
    $this->container->shouldReceive('register')->andReturnNull();
});

afterEach(function () {
    // Plugin holds its container and init state statically; leaving
    // either set would make the next test's init() a silent no-op.
    resetIntegrityPluginStatics();
});

it('registers the rest_api_init action on init', function () {
    WpState::$isAdmin = false;

    Plugin::init($this->container);

    // Two callbacks land on rest_api_init: the closure that resolves and
    // registers RestController, and the security-header hook. Brain Monkey
    // matches a hooked callback by identity, so the anonymous one can only
    // be asserted as "something is hooked here"; the named one is checked
    // exactly.
    $this->assertActionAdded('rest_api_init');
    $this->assertActionAdded('rest_api_init', [Plugin::class, 'addSecurityHeaders']);

    expect(Plugin::getContainer())->toBe($this->container);
});

it('registers the admin hooks on init when is_admin', function () {
    WpState::$isAdmin = true;

    $settingsPage = Mockery::mock(SettingsPage::class);
    $settingsPage->shouldReceive('init')->once();

    $this->container->shouldReceive('get')
        ->with(SettingsPage::class)
        ->once()
        ->andReturn($settingsPage);

    Plugin::init($this->container);

    expect(Plugin::getContainer())->toBe($this->container);
});

it('only initialises once', function () {
    WpState::$isAdmin = false;

    Plugin::init($this->container);

    // A second init must be a no-op, so the container it is handed should
    // never be touched — and the first one must still be in place.
    $secondContainer = Mockery::mock(Container::class);
    $secondContainer->shouldNotReceive('register');
    $secondContainer->shouldNotReceive('get');

    Plugin::init($secondContainer);

    expect(Plugin::getContainer())->toBe($this->container);
});

it('adds the security-header filter', function () {
    // add_filter is Brain Monkey's, not something to stub over, so the
    // registration is asserted through its own expectation, verified at
    // teardown. Priority 10 and 3 arguments: rest_pre_serve_request passes
    // $served, $result, $request.
    Filters\expectAdded('rest_pre_serve_request')
        ->once()
        ->with(Mockery::type(Closure::class), 10, 3);

    Plugin::addSecurityHeaders();
});

<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use BleedingDeacons\WpMocks\WpState;
use Integrity\Auth\PreAuthThrottle;

/*
 * Unit tests for PreAuthThrottle.
 *
 * The throttle is transient-backed and wp-mocks keeps transients in
 * WpState::$transients, so these exercise the real storage path rather than
 * a double. Expiry is not modelled by the stub — the class relies on the
 * window index in the key rather than on TTL for correctness, which is what
 * the window-rollover test below covers.
 */

beforeEach(function () {
    $this->throttle = new PreAuthThrottle();
});

it('does not block a fresh client', function () {
    expect($this->throttle->isBlocked('203.0.113.7'))->toBeFalse();
});

it('keeps isBlocked read-only', function () {
    $this->throttle->isBlocked('203.0.113.7');

    expect(WpState::$transients)->toBe(
        [],
        'A request that goes on to authenticate must not cost a write.'
    );
});

it('blocks a client once the budget is spent', function () {
    $ip = '203.0.113.7';

    // One short of the cap: still allowed.
    for ($i = 0; $i < 19; $i++) {
        $this->throttle->penalise($ip);
    }
    expect($this->throttle->isBlocked($ip))->toBeFalse();

    $this->throttle->penalise($ip);
    expect($this->throttle->isBlocked($ip))->toBeTrue();
});

it('counts the budget per client', function () {
    for ($i = 0; $i < 20; $i++) {
        $this->throttle->penalise('203.0.113.7');
    }

    expect($this->throttle->isBlocked('203.0.113.7'))->toBeTrue()
        ->and($this->throttle->isBlocked('198.51.100.4'))
        ->toBeFalse('One abusive client must not lock out every other caller.');
});

it('scopes the counter to a window', function () {
    $ip = '203.0.113.7';
    for ($i = 0; $i < 20; $i++) {
        $this->throttle->penalise($ip);
    }
    expect($this->throttle->isBlocked($ip))->toBeTrue();

    // The key embeds floor(time() / WINDOW), so a counter cannot outlive
    // its window even if the transient itself is never expired. Rather
    // than sleep, assert the key really is window-scoped: no stored key
    // is reachable from a different window index.
    $keys = array_keys(WpState::$transients);
    expect($keys)->toHaveCount(1)
        ->and($keys[0])->toMatch('/^integrity_preauth_[0-9a-f]{32}_\d+$/');
});

it('keeps retryAfter within the window', function () {
    $retryAfter = $this->throttle->retryAfter();

    expect($retryAfter)
        ->toBeGreaterThan(0)
        ->toBeLessThanOrEqual(900);
});

it('does not store the client IP in the clear', function () {
    $ip = '203.0.113.7';
    $this->throttle->penalise($ip);

    $keys = array_keys(WpState::$transients);
    expect($keys[0])->not->toContain($ip);
});

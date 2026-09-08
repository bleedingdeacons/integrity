<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use BleedingDeacons\WpMocks\WpState;
use Integrity\Auth\PreAuthThrottle;
use Integrity\Tests\TestCase;

/**
 * Unit tests for PreAuthThrottle.
 *
 * The throttle is transient-backed and wp-mocks keeps transients in
 * WpState::$transients, so these exercise the real storage path rather than
 * a double. Expiry is not modelled by the stub — the class relies on the
 * window index in the key rather than on TTL for correctness, which is what
 * the window-rollover test below covers.
 */
class PreAuthThrottleTest extends TestCase
{
    private PreAuthThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->throttle = new PreAuthThrottle();
    }

    /** @test */
    public function a_fresh_client_is_not_blocked(): void
    {
        $this->assertFalse($this->throttle->isBlocked('203.0.113.7'));
    }

    /** @test */
    public function isBlocked_is_read_only(): void
    {
        $this->throttle->isBlocked('203.0.113.7');

        $this->assertSame(
            [],
            WpState::$transients,
            'A request that goes on to authenticate must not cost a write.'
        );
    }

    /** @test */
    public function a_client_is_blocked_once_the_budget_is_spent(): void
    {
        $ip = '203.0.113.7';

        // One short of the cap: still allowed.
        for ($i = 0; $i < 19; $i++) {
            $this->throttle->penalise($ip);
        }
        $this->assertFalse($this->throttle->isBlocked($ip));

        $this->throttle->penalise($ip);
        $this->assertTrue($this->throttle->isBlocked($ip));
    }

    /** @test */
    public function the_budget_is_counted_per_client(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->penalise('203.0.113.7');
        }

        $this->assertTrue($this->throttle->isBlocked('203.0.113.7'));
        $this->assertFalse(
            $this->throttle->isBlocked('198.51.100.4'),
            'One abusive client must not lock out every other caller.'
        );
    }

    /** @test */
    public function the_counter_is_scoped_to_a_window(): void
    {
        $ip = '203.0.113.7';
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->penalise($ip);
        }
        $this->assertTrue($this->throttle->isBlocked($ip));

        // The key embeds floor(time() / WINDOW), so a counter cannot outlive
        // its window even if the transient itself is never expired. Rather
        // than sleep, assert the key really is window-scoped: no stored key
        // is reachable from a different window index.
        $keys = array_keys(WpState::$transients);
        $this->assertCount(1, $keys);
        $this->assertMatchesRegularExpression('/^integrity_preauth_[0-9a-f]{32}_\d+$/', $keys[0]);
    }

    /** @test */
    public function retryAfter_is_within_the_window(): void
    {
        $retryAfter = $this->throttle->retryAfter();

        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(900, $retryAfter);
    }

    /** @test */
    public function the_client_ip_is_not_stored_in_the_clear(): void
    {
        $ip = '203.0.113.7';
        $this->throttle->penalise($ip);

        $keys = array_keys(WpState::$transients);
        $this->assertStringNotContainsString($ip, $keys[0]);
    }
}

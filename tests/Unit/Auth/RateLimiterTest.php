<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\RateLimiter;
use Integrity\Tests\TestCase;
use WP_Mock;
use Mockery;

/**
 * Unit tests for RateLimiter
 */
class RateLimiterTest extends TestCase
{
    /**
     * @test
     */
    public function checkAndIncrement_returns_allowed_when_under_limit(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('query')
            ->once()
            ->with('prepared_query')
            ->andReturn(1);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(51); // count after increment

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkAndIncrement(1, 1000);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(949, $result['remaining']);
        $this->assertArrayHasKey('reset', $result);
    }

    /**
     * @test
     */
    public function checkAndIncrement_returns_not_allowed_when_over_limit(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(1001); // count after increment exceeds limit

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkAndIncrement(1, 1000);

        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
    }

    /**
     * @test
     */
    public function checkAndIncrement_returns_allowed_for_first_request_in_window(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(1); // first request

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkAndIncrement(1, 1000);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(999, $result['remaining']);
    }

    /**
     * @test
     */
    public function checkAndIncrement_returns_not_allowed_when_exceeding_limit(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(1001); // 1001st request with limit of 1000

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkAndIncrement(1, 1000);

        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
    }

    /**
     * @test
     */
    public function checkAndIncrement_allows_request_at_exact_limit_boundary(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(1000); // 1000th request with limit of 1000

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkAndIncrement(1, 1000);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
    }

    /**
     * @test
     */
    public function checkAndIncrement_with_zero_limit_always_denied(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(1); // even 1 exceeds limit of 0

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkAndIncrement(1, 0);

        $this->assertFalse($result['allowed']);
    }

    /**
     * @test
     */
    public function checkLimit_returns_allowed_when_under_limit(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['request_count' => 50]);

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkLimit(1, 1000);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(950, $result['remaining']);
        $this->assertArrayHasKey('reset', $result);
    }

    /**
     * @test
     */
    public function checkLimit_returns_not_allowed_when_at_limit(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['request_count' => 1000]);

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkLimit(1, 1000);

        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
    }

    /**
     * @test
     */
    public function checkLimit_returns_full_limit_for_new_window(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkLimit(1, 1000);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(1000, $result['remaining']);
    }

    /**
     * @test
     */
    public function checkLimit_reset_time_is_in_future(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $rateLimiter = new RateLimiter();
        $result = $rateLimiter->checkLimit(1, 1000);

        $this->assertGreaterThan(time(), $result['reset']);
    }

    /**
     * @test
     */
    public function getHeaders_returns_correct_header_format(): void
    {
        $rateLimiter = new RateLimiter();
        $headers = $rateLimiter->getHeaders(1000, 500, 1704067200);

        $this->assertIsArray($headers);
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);

        $this->assertEquals(1000, $headers['X-RateLimit-Limit']);
        $this->assertEquals(500, $headers['X-RateLimit-Remaining']);
        $this->assertEquals(1704067200, $headers['X-RateLimit-Reset']);
    }

    /**
     * @test
     */
    public function getHeaders_remaining_never_negative(): void
    {
        $rateLimiter = new RateLimiter();
        $headers = $rateLimiter->getHeaders(1000, -50, 1704067200);

        $this->assertEquals(0, $headers['X-RateLimit-Remaining']);
    }
}

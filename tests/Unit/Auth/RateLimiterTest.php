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

        $result = RateLimiter::checkLimit(1, 1000);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(949, $result['remaining']);
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

        $result = RateLimiter::checkLimit(1, 1000);

        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
    }

    /**
     * @test
     */
    public function checkLimit_returns_not_allowed_when_over_limit(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');
        
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['request_count' => 1500]);

        $result = RateLimiter::checkLimit(1, 1000);

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
            ->andReturn(null); // No existing record

        $result = RateLimiter::checkLimit(1, 1000);

        $this->assertTrue($result['allowed']);
        $this->assertEquals(999, $result['remaining']);
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

        $result = RateLimiter::checkLimit(1, 1000);

        $this->assertGreaterThan(time(), $result['reset']);
    }

    /**
     * @test
     */
    public function incrementCount_executes_query(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query');
        
        $wpdb->shouldReceive('query')
            ->once()
            ->with('prepared_query')
            ->andReturn(1);

        // Should not throw
        RateLimiter::incrementCount(1);
        
        $this->assertTrue(true); // Assert that we got here
    }

    /**
     * @test
     */
    public function getHeaders_returns_correct_header_format(): void
    {
        $headers = RateLimiter::getHeaders(1000, 500, 1704067200);

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
        $headers = RateLimiter::getHeaders(1000, -50, 1704067200);

        $this->assertEquals(0, $headers['X-RateLimit-Remaining']);
    }

    /**
     * @test
     */
    public function checkLimit_with_zero_limit_always_denied(): void
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

        $result = RateLimiter::checkLimit(1, 0);

        $this->assertFalse($result['allowed']);
    }

    /**
     * @test
     */
    public function checkLimit_with_different_api_keys_are_independent(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        
        // First key - high usage
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query_1');
        
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['request_count' => 900]);

        $result1 = RateLimiter::checkLimit(1, 1000);

        // Second key - low usage
        $wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('prepared_query_2');
        
        $wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(['request_count' => 10]);

        $result2 = RateLimiter::checkLimit(2, 1000);

        $this->assertTrue($result1['allowed']);
        $this->assertTrue($result2['allowed']);
        $this->assertEquals(99, $result1['remaining']);
        $this->assertEquals(989, $result2['remaining']);
    }
}

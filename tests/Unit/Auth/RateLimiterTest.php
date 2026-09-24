<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\RateLimiter;
use Mockery;

/*
 * Unit tests for RateLimiter
 */

describe('checkAndIncrement', function () {
    it('returns allowed when under the limit', function () {
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

        expect($result['allowed'])->toBeTrue()
            ->and($result['remaining'])->toEqual(949)
            ->and($result)->toHaveKey('reset');
    });

    it('returns not allowed when over the limit', function () {
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

        expect($result['allowed'])->toBeFalse()
            ->and($result['remaining'])->toEqual(0);
    });

    it('returns allowed for the first request in a window', function () {
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

        expect($result['allowed'])->toBeTrue()
            ->and($result['remaining'])->toEqual(999);
    });

    it('returns not allowed when exceeding the limit', function () {
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

        expect($result['allowed'])->toBeFalse()
            ->and($result['remaining'])->toEqual(0);
    });

    it('allows a request at the exact limit boundary', function () {
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

        expect($result['allowed'])->toBeTrue()
            ->and($result['remaining'])->toEqual(0);
    });

    it('always denies with a zero limit', function () {
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

        expect($result['allowed'])->toBeFalse();
    });
});

describe('checkLimit', function () {
    it('returns allowed when under the limit', function () {
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

        expect($result['allowed'])->toBeTrue()
            ->and($result['remaining'])->toEqual(950)
            ->and($result)->toHaveKey('reset');
    });

    it('returns not allowed when at the limit', function () {
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

        expect($result['allowed'])->toBeFalse()
            ->and($result['remaining'])->toEqual(0);
    });

    it('returns the full limit for a new window', function () {
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

        expect($result['allowed'])->toBeTrue()
            ->and($result['remaining'])->toEqual(1000);
    });

    it('puts the reset time in the future', function () {
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

        expect($result['reset'])->toBeGreaterThan(time());
    });
});

describe('getHeaders', function () {
    it('returns the correct header format', function () {
        $rateLimiter = new RateLimiter();
        $headers = $rateLimiter->getHeaders(1000, 500, 1704067200);

        expect($headers)
            ->toBeArray()
            ->toHaveKey('X-RateLimit-Limit', 1000)
            ->toHaveKey('X-RateLimit-Remaining', 500)
            ->toHaveKey('X-RateLimit-Reset', 1704067200);
    });

    it('never returns a negative remaining count', function () {
        $rateLimiter = new RateLimiter();
        $headers = $rateLimiter->getHeaders(1000, -50, 1704067200);

        expect($headers['X-RateLimit-Remaining'])->toEqual(0);
    });
});

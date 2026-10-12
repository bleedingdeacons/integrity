<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Integrity\Api\ClientReferences;
use Mockery;

/*
 * ClientReferences talks to $wpdb directly, so each test swaps in a double
 * for the global and restores the previous one afterwards.
 */

covers(ClientReferences::class);

const CLIENT_REFERENCE = '3f1c2b4a-9d8e-4c7b-a6f5-0e1d2c3b4a59';

beforeEach(function () {
    $this->previousWpdb = $GLOBALS['wpdb'] ?? null;

    $this->wpdb = Mockery::mock();
    // Hand the query back with its arguments so the assertions can see both.
    $this->wpdb->shouldReceive('prepare')->andReturnUsing(
        fn (string $query, ...$args) => ['query' => $query, 'args' => $args]
    );

    $GLOBALS['wpdb'] = $this->wpdb;
    $this->references = new ClientReferences();
});

afterEach(function () {
    $GLOBALS['wpdb'] = $this->previousWpdb;
});

describe('isValid', function () {
    it('accepts a UUID in either case', function () {
        expect(ClientReferences::isValid(CLIENT_REFERENCE))->toBeTrue()
            ->and(ClientReferences::isValid(strtoupper(CLIENT_REFERENCE)))->toBeTrue();
    });

    it('rejects anything that is not a canonical UUID', function (mixed $value) {
        expect(ClientReferences::isValid($value))->toBeFalse();
    })->with([
        'empty' => '',
        'braced' => '{' . CLIENT_REFERENCE . '}',
        'no hyphens' => str_replace('-', '', CLIENT_REFERENCE),
        'too short' => substr(CLIENT_REFERENCE, 0, 35),
        'trailing text' => CLIENT_REFERENCE . 'x',
        'not hex' => 'zzzzzzzz-9d8e-4c7b-a6f5-0e1d2c3b4a59',
        'number' => 42,
        'null' => null,
        'array' => [CLIENT_REFERENCE],
    ]);
});

describe('findMemberId', function () {
    beforeEach(function () {
        Functions\when('get_post_types')->justReturn(['post' => 'post', 'member' => 'member']);
    });

    it('finds the member carrying the reference in post meta', function () {
        WpState::$queryPosts = [(object) ['ID' => 123, 'post_type' => 'member']];

        expect($this->references->findMemberId(CLIENT_REFERENCE))->toBe(123);

        $args = WpState::$options['__last_get_posts_args'];
        expect($args['meta_key'])->toBe(ClientReferences::META_KEY)
            ->and($args['meta_value'])->toBe(CLIENT_REFERENCE)
            ->and($args['fields'])->toBe('ids')
            ->and($args['posts_per_page'])->toBe(1)
            ->and($args['post_status'])->toBe('any')
            // Named, not 'any': 'any' skips types excluded from search.
            ->and($args['post_type'])->toBe(['post', 'member']);
    });

    it('returns null when no member carries the reference', function () {
        WpState::$queryPosts = [];

        expect($this->references->findMemberId(CLIENT_REFERENCE))->toBeNull();
    });
});

describe('remember', function () {
    it('stores the reference as meta on the member', function () {
        expect($this->references->remember(123, CLIENT_REFERENCE))->toBeTrue()
            ->and(WpState::$postMeta[123][ClientReferences::META_KEY])->toBe(CLIENT_REFERENCE);
    });
});

describe('lock and release', function () {
    it('holds the lock when MySQL grants it', function () {
        $this->wpdb->shouldReceive('get_var')->once()->andReturnUsing(function (array $prepared) {
            expect($prepared['query'])->toContain('GET_LOCK')
                ->and($prepared['args'][1])->toBe(ClientReferences::LOCK_TIMEOUT);
            return '1';
        });

        expect($this->references->lock(CLIENT_REFERENCE))->toBeTrue();
    });

    it('does not hold the lock when the wait times out or MySQL errors', function (mixed $result) {
        $this->wpdb->shouldReceive('get_var')->once()->andReturn($result);

        expect($this->references->lock(CLIENT_REFERENCE))->toBeFalse();
    })->with(['timed out' => '0', 'error' => null]);

    it('names the lock within MySQL\'s 64-character limit, the same whatever the case', function () {
        $names = [];
        $this->wpdb->shouldReceive('get_var')->andReturnUsing(function (array $prepared) use (&$names) {
            $names[] = $prepared['args'][0];
            return '1';
        });

        $this->references->lock(CLIENT_REFERENCE);
        $this->references->lock(strtoupper(CLIENT_REFERENCE));

        expect(strlen($names[0]))->toBeLessThanOrEqual(64)
            ->and($names[1])->toBe($names[0]);
    });

    it('releases the lock it took', function () {
        $this->wpdb->shouldReceive('get_var')->once()->andReturnUsing(function (array $prepared) {
            expect($prepared['query'])->toContain('RELEASE_LOCK')
                ->and($prepared['args'][0])->toContain(CLIENT_REFERENCE);
            return '1';
        });

        $this->references->release(CLIENT_REFERENCE);
    });
});

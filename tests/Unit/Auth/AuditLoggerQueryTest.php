<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\AuditLogger;
use Mockery;

/*
 * Covers AuditLogger's read/stats/clear query builders, which assemble
 * filtered SQL over the audit-log table. A mocked $wpdb captures the calls.
 */

covers(AuditLogger::class);

function auditLoggerQueryWpdb(): object
{
    $wpdb = Mockery::mock('wpdb');
    $wpdb->prefix = 'wp_';
    $wpdb->rows_affected = 7;
    $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($q) => $q)->byDefault();
    $wpdb->shouldReceive('esc_like')->andReturnUsing(fn ($v) => $v)->byDefault();
    $GLOBALS['wpdb'] = $wpdb;
    return $wpdb;
}

beforeEach(function () {
    $this->logger = new AuditLogger();

    // esc_sql() and wp_parse_args() are real functions in wp-mocks with
    // the behaviour this class's query builders expect, so there is
    // nothing left to stub here.
});

it('builds a filtered query for getLogs and decodes params', function () {
    $wpdb = auditLoggerQueryWpdb();
    $wpdb->shouldReceive('get_var')->andReturn(1);
    $wpdb->shouldReceive('get_results')->andReturn([
        ['request_params' => json_encode(['a' => 1])],
        ['request_params' => null],
    ]);

    $result = $this->logger->getLogs([
        'api_key_id' => 3,
        'endpoint' => '/members',
        'response_code' => 200,
        'ip_address' => '1.2.3.4',
        'date_from' => '2026-01-01 00:00:00',
        'date_to' => '2026-12-31 23:59:59',
        'order_by' => 'response_code',
        'order' => 'ASC',
        'page' => 2,
        'per_page' => 25,
    ]);

    expect($result['total'])->toBe(1)
        ->and($result['logs'][0]['request_params'])->toBe(['a' => 1])
        ->and($result['logs'][1]['request_params'])->toBeNull();
});

it('aggregates the dashboard counts in getStats', function () {
    $wpdb = auditLoggerQueryWpdb();
    $wpdb->shouldReceive('get_var')->andReturn(5);
    $wpdb->shouldReceive('get_results')->andReturn([['endpoint' => '/members', 'count' => 3]]);

    $stats = $this->logger->getStats(7);

    expect($stats['total_requests'])->toBe(5)
        ->and($stats['period_days'])->toBe(7)
        ->and($stats)->toHaveKey('top_endpoints')
        ->and($stats)->toHaveKey('top_ips');
});

it('truncates in clearLogs when there are no filters', function () {
    $wpdb = auditLoggerQueryWpdb();
    $wpdb->shouldReceive('query')->once()->with(Mockery::pattern('/TRUNCATE/'));

    expect($this->logger->clearLogs())->toBe(7);
});

it('deletes with filters in clearLogs', function () {
    $wpdb = auditLoggerQueryWpdb();
    $wpdb->shouldReceive('query')->once()->with(Mockery::pattern('/DELETE/'));

    expect($this->logger->clearLogs(30, 5))->toBe(7);
});

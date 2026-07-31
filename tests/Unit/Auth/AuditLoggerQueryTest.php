<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;

/**
 * Covers AuditLogger's read/stats/clear query builders, which assemble
 * filtered SQL over the audit-log table. A mocked $wpdb captures the calls.
 *
 * @covers \Integrity\Auth\AuditLogger
 */
class AuditLoggerQueryTest extends TestCase
{
    private AuditLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new AuditLogger();

        // esc_sql() and wp_parse_args() are real functions in wp-mocks with
        // the behaviour this class's query builders expect, so there is
        // nothing left to stub here.
    }

    private function wpdb(): object
    {
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->rows_affected = 7;
        $wpdb->shouldReceive('prepare')->andReturnUsing(fn ($q) => $q)->byDefault();
        $wpdb->shouldReceive('esc_like')->andReturnUsing(fn ($v) => $v)->byDefault();
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    /** @test */
    public function get_logs_builds_a_filtered_query_and_decodes_params(): void
    {
        $wpdb = $this->wpdb();
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

        $this->assertSame(1, $result['total']);
        $this->assertSame(['a' => 1], $result['logs'][0]['request_params']);
        $this->assertNull($result['logs'][1]['request_params']);
    }

    /** @test */
    public function get_stats_aggregates_the_dashboard_counts(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('get_var')->andReturn(5);
        $wpdb->shouldReceive('get_results')->andReturn([['endpoint' => '/members', 'count' => 3]]);

        $stats = $this->logger->getStats(7);

        $this->assertSame(5, $stats['total_requests']);
        $this->assertSame(7, $stats['period_days']);
        $this->assertArrayHasKey('top_endpoints', $stats);
        $this->assertArrayHasKey('top_ips', $stats);
    }

    /** @test */
    public function clear_logs_truncates_when_no_filters(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('query')->once()->with(Mockery::pattern('/TRUNCATE/'));

        $this->assertSame(7, $this->logger->clearLogs());
    }

    /** @test */
    public function clear_logs_deletes_with_filters(): void
    {
        $wpdb = $this->wpdb();
        $wpdb->shouldReceive('query')->once()->with(Mockery::pattern('/DELETE/'));

        $this->assertSame(7, $this->logger->clearLogs(30, 5));
    }
}

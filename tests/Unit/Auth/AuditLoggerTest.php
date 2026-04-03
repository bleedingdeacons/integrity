<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use WP_Mock;
use Mockery;

/**
 * Unit tests for AuditLogger
 */
class AuditLoggerTest extends TestCase
{
    /**
     * @test
     */
    public function log_inserts_record_when_enabled(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        WP_Mock::userFunction('get_option')
            ->with('integrity_enable_audit_log', true)
            ->andReturn(true);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnArg(0);

        WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(function ($data) {
                return json_encode($data);
            });

        WP_Mock::userFunction('current_time')
            ->with('mysql')
            ->andReturn('2024-01-01 12:00:00');

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        AuditLogger::log(
            1,
            '/integrity/v1/groups',
            'GET',
            ['page' => 1],
            200,
            0.125
        );

        // Assert insert was called (verified by Mockery expectations)
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function log_does_not_insert_when_disabled(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        WP_Mock::userFunction('get_option')
            ->with('integrity_enable_audit_log', true)
            ->andReturn(false);

        // insert should NOT be called
        $wpdb->shouldNotReceive('insert');

        AuditLogger::log(
            1,
            '/integrity/v1/groups',
            'GET',
            ['page' => 1],
            200,
            0.125
        );

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function log_sanitizes_sensitive_params(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        WP_Mock::userFunction('get_option')
            ->andReturn(true);

        WP_Mock::userFunction('sanitize_text_field')
            ->andReturnArg(0);

        WP_Mock::userFunction('current_time')
            ->andReturn('2024-01-01 12:00:00');

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $capturedParams = null;

        WP_Mock::userFunction('wp_json_encode')
            ->andReturnUsing(function ($data) use (&$capturedParams) {
                $capturedParams = $data;
                return json_encode($data);
            });

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        AuditLogger::log(
            1,
            '/test',
            'POST',
            [
                'username' => 'testuser',
                'password' => 'secret123',
                'api_key' => 'int_supersecret',
                'data' => 'normal_data',
            ],
            200,
            0.1
        );

        // Verify sensitive fields were redacted
        $this->assertEquals('[REDACTED]', $capturedParams['password']);
        $this->assertEquals('[REDACTED]', $capturedParams['api_key']);
        $this->assertEquals('testuser', $capturedParams['username']);
        $this->assertEquals('normal_data', $capturedParams['data']);
    }

    /**
     * @test
     */
    public function getClientIp_returns_remote_addr_when_no_trusted_proxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxies', [])
            ->andReturn([]);

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        // Without trusted proxies, proxy headers are ignored
        $this->assertEquals('10.0.0.1', $ip);
    }

    /**
     * @test
     */
    public function getClientIp_reads_proxy_header_when_remote_addr_is_trusted(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 10.0.0.1';

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxies', [])
            ->andReturn(['10.0.0.1']);

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxy_header', 'HTTP_X_FORWARDED_FOR')
            ->andReturn('HTTP_X_FORWARDED_FOR');

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        $this->assertEquals('203.0.113.50', $ip);
    }

    /**
     * @test
     */
    public function getClientIp_reads_cloudflare_header_when_configured(): void
    {
        $_SERVER['REMOTE_ADDR'] = '172.70.100.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxies', [])
            ->andReturn(['172.64.0.0/13', '173.245.48.0/20']);

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxy_header', 'HTTP_X_FORWARDED_FOR')
            ->andReturn('HTTP_CF_CONNECTING_IP');

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        $this->assertEquals('198.51.100.25', $ip);
    }

    /**
     * @test
     */
    public function getClientIp_ignores_proxy_header_when_remote_addr_not_trusted(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.10.10.10';

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxies', [])
            ->andReturn(['10.0.0.1']);

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        // REMOTE_ADDR is not in the trusted proxies list, so proxy
        // headers are not consulted — prevents spoofing
        $this->assertEquals('192.168.1.100', $ip);
    }

    /**
     * @test
     */
    public function getClientIp_supports_cidr_trusted_proxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.5.42';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxies', [])
            ->andReturn(['10.0.0.0/8']);

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxy_header', 'HTTP_X_FORWARDED_FOR')
            ->andReturn('HTTP_X_FORWARDED_FOR');

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        $this->assertEquals('203.0.113.99', $ip);
    }

    /**
     * @test
     */
    public function getClientIp_validates_ip_format(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'invalid-ip';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        $this->assertEquals('0.0.0.0', $ip);
    }

    /**
     * @test
     */
    public function getClientIp_falls_back_to_remote_addr_when_proxy_header_empty(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxies', [])
            ->andReturn(['10.0.0.1']);

        WP_Mock::userFunction('get_option')
            ->with('integrity_trusted_proxy_header', 'HTTP_X_FORWARDED_FOR')
            ->andReturn('HTTP_X_FORWARDED_FOR');

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        // Proxy header is not set, so falls back to REMOTE_ADDR
        $this->assertEquals('10.0.0.1', $ip);
    }

    /**
     * @test
     */
    public function getLogs_returns_paginated_results(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $mockLogs = [
            [
                'id' => 1,
                'api_key_id' => 1,
                'endpoint' => '/integrity/v1/groups',
                'method' => 'GET',
                'ip_address' => '192.168.1.100',
                'user_agent' => 'TestAgent',
                'request_params' => '{"page":1}',
                'response_code' => 200,
                'response_time' => 0.125,
                'created_at' => '2024-01-01 12:00:00',
            ],
        ];

        $wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(1);

        $wpdb->shouldReceive('prepare')
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($mockLogs);

        $wpdb->shouldReceive('esc_like')
            ->andReturnArg(0);

        $result = AuditLogger::getLogs(['page' => 1, 'per_page' => 50]);

        $this->assertArrayHasKey('logs', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(1, $result['total']);
        $this->assertCount(1, $result['logs']);
    }

    /**
     * @test
     */
    public function getLogs_decodes_json_params(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $mockLogs = [
            [
                'id' => 1,
                'api_key_id' => 1,
                'endpoint' => '/test',
                'method' => 'GET',
                'ip_address' => '127.0.0.1',
                'user_agent' => null,
                'request_params' => '{"foo":"bar","num":42}',
                'response_code' => 200,
                'response_time' => 0.1,
                'created_at' => '2024-01-01 00:00:00',
            ],
        ];

        $wpdb->shouldReceive('get_var')
            ->andReturn(1);

        $wpdb->shouldReceive('prepare')
            ->andReturn('prepared_query');

        $wpdb->shouldReceive('get_results')
            ->andReturn($mockLogs);

        $result = AuditLogger::getLogs();

        $this->assertIsArray($result['logs'][0]['request_params']);
        $this->assertEquals('bar', $result['logs'][0]['request_params']['foo']);
        $this->assertEquals(42, $result['logs'][0]['request_params']['num']);
    }

    /**
     * @test
     */
    public function getStats_returns_expected_metrics(): void
    {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive('prepare')
            ->andReturn('prepared_query');

        // Total requests
        $wpdb->shouldReceive('get_var')
            ->andReturn(1000, 800, 50, 10, 0.15);

        // Top endpoints
        $wpdb->shouldReceive('get_results')
            ->andReturn(
                [['endpoint' => '/groups', 'count' => 500]],
                [['ip_address' => '192.168.1.1', 'count' => 100]]
            );

        $stats = AuditLogger::getStats(30);

        $this->assertArrayHasKey('total_requests', $stats);
        $this->assertArrayHasKey('successful_requests', $stats);
        $this->assertArrayHasKey('failed_auth', $stats);
        $this->assertArrayHasKey('rate_limited', $stats);
        $this->assertArrayHasKey('avg_response_time', $stats);
        $this->assertArrayHasKey('top_endpoints', $stats);
        $this->assertArrayHasKey('top_ips', $stats);
        $this->assertArrayHasKey('period_days', $stats);

        $this->assertEquals(30, $stats['period_days']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);
        unset($_SERVER['HTTP_X_REAL_IP']);
        unset($_SERVER['HTTP_USER_AGENT']);

        parent::tearDown();
    }
}
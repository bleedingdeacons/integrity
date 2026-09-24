<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Auth;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Integrity\Auth\AuditLogger;
use Mockery;

/*
 * Unit tests for AuditLogger
 *
 * Options are seeded into WpState rather than stubbed one expectation at a
 * time: wp-mocks' get_option() is a real function over that store, so a test
 * says what the site is configured to do and the class reads it back.
 */

beforeEach(function () {
    // Instance methods now; static when these tests were written.
    $this->auditLogger = new AuditLogger();
});

afterEach(function () {
    unset($_SERVER['REMOTE_ADDR']);
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    unset($_SERVER['HTTP_CF_CONNECTING_IP']);
    unset($_SERVER['HTTP_X_REAL_IP']);
    unset($_SERVER['HTTP_USER_AGENT']);
});

describe('log', function () {
    it('inserts a record when enabled', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        WpState::$options['integrity_enable_audit_log'] = true;

        // log() resolves the client IP, which consults the trusted-proxy
        // allowlist. Added to the source after these tests were written;
        // none configured, so REMOTE_ADDR is used directly.
        WpState::$options['integrity_trusted_proxies'] = [];

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';

        // The insert is the assertion: verified by this Mockery expectation.
        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        $this->auditLogger->log(
            1,
            '/integrity/v1/groups',
            'GET',
            ['page' => 1],
            200,
            0.125
        );
    });

    it('does not insert when disabled', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        WpState::$options['integrity_enable_audit_log'] = false;

        // insert should NOT be called
        $wpdb->shouldNotReceive('insert');

        $this->auditLogger->log(
            1,
            '/integrity/v1/groups',
            'GET',
            ['page' => 1],
            200,
            0.125
        );
    });

    it('sanitises sensitive params', function () {
        global $wpdb;
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';

        WpState::$options['integrity_enable_audit_log'] = true;
        WpState::$options['integrity_trusted_proxies'] = [];

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        // The redaction happens before encoding, so intercept the encoder to
        // see the array as the logger built it.
        $capturedParams = null;
        Functions\when('wp_json_encode')->alias(
            static function ($data) use (&$capturedParams) {
                $capturedParams = $data;

                return json_encode($data);
            }
        );

        $wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        $this->auditLogger->log(
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
        expect($capturedParams)
            ->toHaveKey('password', '[REDACTED]')
            ->toHaveKey('api_key', '[REDACTED]')
            ->toHaveKey('username', 'testuser')
            ->toHaveKey('data', 'normal_data');
    });
});

describe('getClientIp', function () {
    it('returns REMOTE_ADDR when there are no trusted proxies', function () {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';

        WpState::$options['integrity_trusted_proxies'] = [];

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        // Without trusted proxies, proxy headers are ignored
        expect($ip)->toEqual('10.0.0.1');
    });

    it('reads the proxy header when REMOTE_ADDR is trusted', function () {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 10.0.0.1';

        WpState::$options['integrity_trusted_proxies'] = ['10.0.0.1'];
        WpState::$options['integrity_trusted_proxy_header'] = 'HTTP_X_FORWARDED_FOR';

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        expect($ip)->toEqual('203.0.113.50');
    });

    it('reads the Cloudflare header when configured', function () {
        $_SERVER['REMOTE_ADDR'] = '172.70.100.5';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.25';

        WpState::$options['integrity_trusted_proxies'] = ['172.64.0.0/13', '173.245.48.0/20'];
        WpState::$options['integrity_trusted_proxy_header'] = 'HTTP_CF_CONNECTING_IP';

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        expect($ip)->toEqual('198.51.100.25');
    });

    it('ignores the proxy header when REMOTE_ADDR is not trusted', function () {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.10.10.10';

        WpState::$options['integrity_trusted_proxies'] = ['10.0.0.1'];

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        // REMOTE_ADDR is not in the trusted proxies list, so proxy
        // headers are not consulted — prevents spoofing
        expect($ip)->toEqual('192.168.1.100');
    });

    it('supports CIDR trusted proxies', function () {
        $_SERVER['REMOTE_ADDR'] = '10.0.5.42';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';

        WpState::$options['integrity_trusted_proxies'] = ['10.0.0.0/8'];
        WpState::$options['integrity_trusted_proxy_header'] = 'HTTP_X_FORWARDED_FOR';

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        expect($ip)->toEqual('203.0.113.99');
    });

    it('validates the IP format', function () {
        $_SERVER['REMOTE_ADDR'] = 'invalid-ip';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['HTTP_CF_CONNECTING_IP']);

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        expect($ip)->toEqual('0.0.0.0');
    });

    it('falls back to REMOTE_ADDR when the proxy header is empty', function () {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        WpState::$options['integrity_trusted_proxies'] = ['10.0.0.1'];
        WpState::$options['integrity_trusted_proxy_header'] = 'HTTP_X_FORWARDED_FOR';

        $logger = new AuditLogger();
        $ip = $logger->getClientIp();

        // Proxy header is not set, so falls back to REMOTE_ADDR
        expect($ip)->toEqual('10.0.0.1');
    });
});

describe('getLogs', function () {
    it('returns paginated results', function () {
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

        $result = $this->auditLogger->getLogs(['page' => 1, 'per_page' => 50]);

        expect($result)
            ->toHaveKey('logs')
            ->toHaveKey('total', 1)
            ->and($result['logs'])->toHaveCount(1);
    });

    it('decodes JSON params', function () {
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

        $result = $this->auditLogger->getLogs();

        expect($result['logs'][0]['request_params'])
            ->toBeArray()
            ->toHaveKey('foo', 'bar')
            ->toHaveKey('num', 42);
    });
});

describe('getStats', function () {
    it('returns the expected metrics', function () {
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

        $stats = $this->auditLogger->getStats(30);

        expect($stats)
            ->toHaveKey('total_requests')
            ->toHaveKey('successful_requests')
            ->toHaveKey('failed_auth')
            ->toHaveKey('rate_limited')
            ->toHaveKey('avg_response_time')
            ->toHaveKey('top_endpoints')
            ->toHaveKey('top_ips')
            ->toHaveKey('period_days', 30);
    });
});

// ── Personal data redaction (F6) ───────────────────────────────────
describe('redact', function () {
    it('removes personal data', function (string $key) {
        // The list knew about credentials but not about the two fields
        // Scrutiny exists to obscure. logFailedRequest() passes
        // $request->get_params() wholesale, so a 401/403/429 on
        // /members/create wrote a real member's address and number into
        // request_params for the whole retention window.
        $out = (new AuditLogger())->redact([$key => 'sensitive-value']);

        expect($out[$key])->toBe('[REDACTED]', $key . ' must not be logged in the clear');
    })->with([
        'personal_email' => ['personal_email'],
        'email'          => ['email'],
        'email_address'  => ['email_address'],
        'mobile_number'  => ['mobile_number'],
        'mobile'         => ['mobile'],
        'telephone'      => ['telephone'],
        'landline'       => ['landline'],
        'phone'          => ['phone'],
        'home_phone'     => ['home_phone'],
    ]);

    it('leaves ordinary parameters alone', function () {
        $out = (new AuditLogger())->redact([
            'page'     => 2,
            'per_page' => 50,
            'expand'   => 'meetings',
            'title'    => 'Tuesday Group',
        ]);

        expect($out)->toBe(
            ['page' => 2, 'per_page' => 50, 'expand' => 'meetings', 'title' => 'Tuesday Group'],
            'Redaction must not eat the parameters that make a log entry useful.'
        );
    });

    it('reaches into nested parameters', function () {
        $out = (new AuditLogger())->redact([
            'member' => ['name' => 'A Person', 'personal_email' => 'a@example.com'],
        ]);

        expect($out['member']['name'])->toBe('A Person')
            ->and($out['member']['personal_email'])->toBe('[REDACTED]');
    });

    it('still removes credentials', function () {
        $out = (new AuditLogger())->redact(['password' => 'p', 'api_key' => 'k', 'token' => 't']);

        expect($out)->toBe(['password' => '[REDACTED]', 'api_key' => '[REDACTED]', 'token' => '[REDACTED]']);
    });
});

<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Closure;
use Integrity\Api\Controllers\GroupController;
use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Api\Controllers\MemberController;
use Integrity\Api\Controllers\PositionController;
use Integrity\Api\RestController;
use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\AuditLogger;
use Integrity\Auth\PreAuthThrottle;
use Integrity\Auth\RateLimiter;
use Integrity\Tests\TestCase;
use Mockery;

/**
 * Unit tests for the refactored RestController (instance-based DI)
 *
 * register_rest_route() is a real function in wp-mocks that records into
 * WpState::$restRoutes, and get_option()/is_ssl() read the same store, so the
 * per-test expectation stacks these tests used to carry are gone.
 */
class RestControllerTest extends TestCase
{
    private ApiKeyManager|Mockery\MockInterface $apiKeyManager;
    private AuditLogger|Mockery\MockInterface $auditLogger;
    private RateLimiter|Mockery\MockInterface $rateLimiter;
    private PreAuthThrottle|Mockery\MockInterface $preAuthThrottle;
    private GroupController|Mockery\MockInterface $groupController;
    private MeetingController|Mockery\MockInterface $meetingController;
    private PositionController|Mockery\MockInterface $positionController;
    private MemberController|Mockery\MockInterface $memberController;
    private IntergroupMeetingController|Mockery\MockInterface $intergroupMeetingController;
    private RestController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKeyManager = Mockery::mock(ApiKeyManager::class);
        $this->auditLogger = Mockery::mock(AuditLogger::class);
        $this->rateLimiter = Mockery::mock(RateLimiter::class);
        $this->preAuthThrottle = Mockery::mock(PreAuthThrottle::class);
        // Default to "not throttled" so the existing auth-path tests exercise
        // the same flow they were written for. The throttle's own behaviour
        // is covered in PreAuthThrottleTest; the tests below that care about
        // it override these defaults.
        $this->preAuthThrottle->shouldReceive('isBlocked')->andReturn(false)->byDefault();
        $this->preAuthThrottle->shouldReceive('penalise')->andReturnNull()->byDefault();
        $this->preAuthThrottle->shouldReceive('retryAfter')->andReturn(900)->byDefault();
        $this->groupController = Mockery::mock(GroupController::class);
        $this->meetingController = Mockery::mock(MeetingController::class);
        $this->positionController = Mockery::mock(PositionController::class);
        $this->memberController = Mockery::mock(MemberController::class);
        $this->intergroupMeetingController = Mockery::mock(IntergroupMeetingController::class);

        $this->controller = new RestController(
            $this->apiKeyManager,
            $this->auditLogger,
            $this->rateLimiter,
            $this->preAuthThrottle,
            $this->groupController,
            $this->meetingController,
            $this->positionController,
            $this->memberController,
            $this->intergroupMeetingController
        );
    }

    // ── Route registration ─────────────────────────────────────────────

    /**
     * @test
     */
    public function register_registers_all_expected_routes(): void
    {
        // Controller mocks must return args arrays when register() wires routes
        $this->groupController->shouldReceive('getGroupsArgs')->once()->andReturn([]);
        $this->meetingController->shouldReceive('getMeetingsArgs')->once()->andReturn([]);
        $this->positionController->shouldReceive('getPositionsArgs')->once()->andReturn([]);
        $this->memberController->shouldReceive('getMembersArgs')->once()->andReturn([]);
        $this->memberController->shouldReceive('getUpdateMemberArgs')->once()->andReturn([]);
        $this->memberController->shouldReceive('getCreateMemberArgs')->once()->andReturn([]);
        $this->memberController->shouldReceive('getRecordComplianceArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getIntergroupMeetingsArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getRegisterAttendeeArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getUnregisterAttendeeArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getRegisterOfficerArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getUnregisterOfficerArgs')->once()->andReturn([]);

        $this->controller->register();

        // Groups (2) + Meetings (2) + Positions (2) + Members (5) +
        // Intergroup Meetings (6) + Health (1) = 18. wp-mocks records every
        // register_rest_route() call, so this reads the real registrations
        // rather than a capture closure over a stub.
        $registeredRoutes = array_map(
            static fn (array $r): string => $r['namespace'] . $r['route'],
            WpState::$restRoutes
        );

        $this->assertCount(18, $registeredRoutes);
        $this->assertSame(
            $registeredRoutes,
            array_unique($registeredRoutes),
            'Each route should be registered exactly once.'
        );
    }

    // ── Auth: missing key ──────────────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_returns_error_when_no_api_key(): void
    {
        $request = $this->createMockRequest();

        WpState::$options['integrity_require_https'] = false;

        // Resolved for the audit record before the key is even looked for.
        $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');

        // Audit logger should still log the failed request
        $this->auditLogger->shouldReceive('log')->once();

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('missing_api_key', $result->get_error_code());
    }

    // ── Auth: invalid key ──────────────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_returns_error_when_key_is_invalid(): void
    {
        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_invalid_key']);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');
        $this->apiKeyManager->shouldReceive('validateKey')
            ->with('int_invalid_key', '127.0.0.1')
            ->andReturn(null);

        $this->auditLogger->shouldReceive('log')->once();

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('invalid_api_key', $result->get_error_code());
    }

    // ── Auth: expansion scopes ─────────────────────────────────────────

    /**
     * @test
     */
    public function expand_meetings_is_refused_on_a_groups_only_key(): void
    {
        // The finding: permissions keyed on the path alone, so a key issued
        // with groups:read and deliberately without meetings:read could read
        // meetings through /groups?expand=meetings.
        $request = $this->createMockRequest(
            ['expand' => 'meetings', '_route' => '/integrity/v1/groups'],
            ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
        );

        $result = $this->authorise($request, ['groups:read']);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('insufficient_permissions', $result->get_error_code());
        $this->assertStringContainsString('meetings:read', $result->get_error_message());
    }

    /**
     * @test
     */
    public function expand_meetings_is_allowed_when_the_key_holds_both_scopes(): void
    {
        $request = $this->createMockRequest(
            ['expand' => 'meetings', '_route' => '/integrity/v1/groups'],
            ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
        );

        $this->assertTrue($this->authorise($request, ['groups:read', 'meetings:read']));
    }

    /**
     * @test
     */
    public function groups_without_expand_still_needs_only_the_groups_scope(): void
    {
        // The fix must not tighten the ordinary case.
        $request = $this->createMockRequest(
            ['_route' => '/integrity/v1/groups'],
            ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
        );

        $this->assertTrue($this->authorise($request, ['groups:read']));
    }

    /**
     * @test
     */
    public function a_wildcard_key_still_reaches_an_expansion(): void
    {
        $request = $this->createMockRequest(
            ['expand' => 'meetings', '_route' => '/integrity/v1/groups'],
            ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
        );

        $this->assertTrue($this->authorise($request, ['*']));
    }

    /**
     * Run checkPermission() for a key holding $permissions, with the
     * throttle and rate limiter out of the way.
     *
     * @param array<int, string> $permissions
     * @return true|\WP_Error
     */
    private function authorise(object $request, array $permissions)
    {
        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->auditLogger->shouldReceive('log')->zeroOrMoreTimes();

        $keyData = $this->createMockApiKeyData([
            'permissions' => $permissions,
            'rate_limit'  => 100,
        ]);
        $this->apiKeyManager->shouldReceive('validateKey')->andReturn($keyData);
        $this->rateLimiter->shouldReceive('checkAndIncrement')
            ->andReturn(['allowed' => true, 'remaining' => 99, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);

        return $this->controller->checkPermission($request);
    }

    // ── Auth: transport ────────────────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_refuses_a_plain_http_request(): void
    {
        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]);

        WpState::$options['integrity_require_https'] = true;
        WpState::$isSsl = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->auditLogger->shouldReceive('log')->once();
        $this->apiKeyManager->shouldNotReceive('validateKey');

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('https_required', $result->get_error_code());
    }

    /**
     * The finding this replaced: `defined('WP_DEBUG') && WP_DEBUG` used to be
     * part of the condition, so turning debugging on switched off a transport
     * security control — on a staging box as a matter of course, and on a live
     * one whenever something was being chased.
     *
     * In its own process because define() is permanent: setting WP_DEBUG here
     * would leak into every test that ran afterwards.
     *
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function wp_debug_no_longer_switches_off_the_https_requirement(): void
    {
        define('WP_DEBUG', true);

        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]);

        WpState::$options['integrity_require_https'] = true;
        WpState::$isSsl = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->auditLogger->shouldReceive('log')->once();
        $this->apiKeyManager->shouldNotReceive('validateKey');

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals(
            'https_required',
            $result->get_error_code(),
            'A debugging flag must not weaken transport security.'
        );
    }

    /**
     * The replacement hatch, for a laptop and nowhere else.
     *
     * Separate process for the same reason as above.
     *
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function the_dedicated_constant_allows_plain_http(): void
    {
        define('INTEGRITY_ALLOW_INSECURE_TRANSPORT', true);

        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]);

        WpState::$options['integrity_require_https'] = true;
        WpState::$isSsl = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->auditLogger->shouldReceive('log')->once();

        // Past the transport guard, the request goes on to fail authentication
        // instead — which is the proof it got past it.
        $this->apiKeyManager->shouldReceive('validateKey')->once()->andReturn(null);

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('invalid_api_key', $result->get_error_code());
    }

    // ── Auth: pre-authentication throttle ──────────────────────────────

    /**
     * @test
     */
    public function checkPermission_refuses_a_throttled_client_before_validating_the_key(): void
    {
        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->preAuthThrottle->shouldReceive('isBlocked')->with('203.0.113.7')->andReturn(true);
        $this->preAuthThrottle->shouldReceive('retryAfter')->andReturn(742);

        // The point of the whole change: validateKey() runs eight Argon2id
        // verifies, and a throttled caller must never reach it.
        $this->apiKeyManager->shouldNotReceive('validateKey');

        // Nor may a refusal write an audit row — otherwise a flood can still
        // fill the audit table once its CPU cost is bounded.
        $this->auditLogger->shouldNotReceive('log');

        Filters\expectAdded('rest_post_dispatch')
            ->once()
            ->with(Mockery::type(Closure::class));

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('too_many_attempts', $result->get_error_code());
        $this->assertEquals(429, $result->get_error_data()['status']);
    }

    /**
     * @test
     */
    public function checkPermission_charges_the_throttle_when_a_key_fails_to_validate(): void
    {
        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('b', 64)]);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->apiKeyManager->shouldReceive('validateKey')->andReturn(null);
        $this->auditLogger->shouldReceive('log')->once();

        // Without this the budget is never spent and the throttle is inert.
        $this->preAuthThrottle->shouldReceive('penalise')->once()->with('203.0.113.7');

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('invalid_api_key', $result->get_error_code());
    }

    /**
     * @test
     */
    public function checkPermission_does_not_charge_the_throttle_for_a_working_key(): void
    {
        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('c', 64)]);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');

        $keyData = $this->createMockApiKeyData([
            'permissions' => ['*'],
            'rate_limit'  => 100,
        ]);
        $this->apiKeyManager->shouldReceive('validateKey')->andReturn($keyData);
        $this->rateLimiter->shouldReceive('checkAndIncrement')
            ->andReturn(['allowed' => true, 'remaining' => 99, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);

        // A caller with a working key must not accumulate a failure count.
        $this->preAuthThrottle->shouldNotReceive('penalise');

        $this->assertTrue($this->controller->checkPermission($request));
    }

    /**
     * @test
     */
    public function checkPermission_charges_the_throttle_when_no_key_is_presented(): void
    {
        $request = $this->createMockRequest([], []);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->auditLogger->shouldReceive('log')->once();

        // A keyless flood costs no Argon2id but still writes an audit row
        // each time, so it has to consume the budget like any other failure.
        $this->preAuthThrottle->shouldReceive('penalise')->once()->with('203.0.113.7');

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('missing_api_key', $result->get_error_code());
    }

    /**
     * @test
     */
    public function a_throttled_client_is_refused_before_the_https_check(): void
    {
        $request = $this->createMockRequest([], []);

        // HTTPS required and this request is not secure: without the throttle
        // sitting first, this path would write an audit row on every attempt.
        WpState::$options['integrity_require_https'] = true;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
        $this->preAuthThrottle->shouldReceive('isBlocked')->with('203.0.113.7')->andReturn(true);
        $this->preAuthThrottle->shouldReceive('retryAfter')->andReturn(900);

        $this->auditLogger->shouldNotReceive('log');

        Filters\expectAdded('rest_post_dispatch')
            ->once()
            ->with(Mockery::type(Closure::class));

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('too_many_attempts', $result->get_error_code());
    }

    // ── Auth: rate limited ─────────────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_returns_error_when_rate_limited(): void
    {
        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_valid_key_12345678']);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');

        $keyData = $this->createMockApiKeyData([
            'permissions' => ['*'],
            'rate_limit' => 100,
        ]);
        $this->apiKeyManager->shouldReceive('validateKey')->andReturn($keyData);

        $this->rateLimiter->shouldReceive('checkAndIncrement')
            ->with(1, 100)
            ->andReturn(['allowed' => false, 'remaining' => 0, 'reset' => time() + 3600]);

        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([
            'X-RateLimit-Limit' => 100,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => time() + 3600,
        ]);

        // A rejected request still attaches the rate-limit response headers.
        // add_filter belongs to Brain Monkey, so this is its expectation,
        // verified at teardown. The callback is anonymous, so it is matched
        // by type rather than identity.
        Filters\expectAdded('rest_post_dispatch')
            ->once()
            ->with(Mockery::type(Closure::class));

        $this->auditLogger->shouldReceive('log')->once();

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('rate_limit_exceeded', $result->get_error_code());
    }

    // ── Auth: insufficient permissions ─────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_returns_error_when_permission_missing(): void
    {
        $request = $this->createMockRequest(['_route' => '/integrity/v1/members'], ['Authorization' => 'Bearer int_valid_key_12345678']);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');

        // Key only has groups:read, not members:read
        $keyData = $this->createMockApiKeyData([
            'permissions' => ['groups:read'],
            'rate_limit' => 1000,
        ]);
        $this->apiKeyManager->shouldReceive('validateKey')->andReturn($keyData);

        $this->rateLimiter->shouldReceive('checkAndIncrement')
            ->andReturn(['allowed' => true, 'remaining' => 999, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);


        $this->auditLogger->shouldReceive('log')->once();

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('insufficient_permissions', $result->get_error_code());
    }

    // ── Auth: success ──────────────────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_returns_true_on_valid_request(): void
    {
        $request = $this->createMockRequest(['_route' => '/integrity/v1/groups'], ['Authorization' => 'Bearer int_valid_key_12345678']);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');

        $keyData = $this->createMockApiKeyData([
            'permissions' => ['groups:read'],
            'rate_limit' => 1000,
        ]);
        $this->apiKeyManager->shouldReceive('validateKey')->andReturn($keyData);

        $this->rateLimiter->shouldReceive('checkAndIncrement')
            ->andReturn(['allowed' => true, 'remaining' => 999, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);


        $result = $this->controller->checkPermission($request);

        $this->assertTrue($result);
    }

    // ── Auth: wildcard permission ──────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_allows_wildcard_permission(): void
    {
        $request = $this->createMockRequest(['_route' => '/integrity/v1/members/123/update'], ['Authorization' => 'Bearer int_valid_key_12345678']);

        WpState::$options['integrity_require_https'] = false;

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');

        $keyData = $this->createMockApiKeyData([
            'permissions' => ['*'],
            'rate_limit' => 1000,
        ]);
        $this->apiKeyManager->shouldReceive('validateKey')->andReturn($keyData);

        $this->rateLimiter->shouldReceive('checkAndIncrement')
            ->andReturn(['allowed' => true, 'remaining' => 999, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);


        $result = $this->controller->checkPermission($request);

        $this->assertTrue($result);
    }

    // ── Permission mapping ─────────────────────────────────────────────

    /**
     * @test
     * @dataProvider endpointPermissionProvider
     */
    public function getRequiredPermission_returns_correct_permission(string $endpoint, ?string $expected): void
    {
        // No setAccessible() call: a no-op since PHP 8.1 (this plugin's
        // floor) and deprecated as of 8.5.
        $method = (new \ReflectionClass(RestController::class))->getMethod('getRequiredPermission');

        $result = $method->invoke($this->controller, $endpoint);

        $this->assertEquals($expected, $result);
    }

    public static function endpointPermissionProvider(): array
    {
        return [
            'groups'                      => ['/integrity/v1/groups', 'groups:read'],
            'groups/{id}'                 => ['/integrity/v1/groups/123', 'groups:read'],
            'meetings'                    => ['/integrity/v1/meetings', 'meetings:read'],
            'positions'                   => ['/integrity/v1/positions', 'positions:read'],
            'members read'                => ['/integrity/v1/members', 'members:read'],
            'members update'              => ['/integrity/v1/members/1/update', 'members:write'],
            'members create'              => ['/integrity/v1/members/create', 'members:write'],
            'members compliance'          => ['/integrity/v1/members/1/compliance', 'members:write'],
            'intergroup-meetings read'    => ['/integrity/v1/intergroup-meetings', 'intergroup-meetings:read'],
            'intergroup-meetings register'   => ['/integrity/v1/intergroup-meetings/1/register-group', 'intergroup-meetings:write'],
            'intergroup-meetings unregister' => ['/integrity/v1/intergroup-meetings/1/unregister-group', 'intergroup-meetings:write'],
            'health'                      => ['/integrity/v1/health', null],
            'unknown'                     => ['/integrity/v1/unknown', null],
        ];
    }

    // ── Health check ───────────────────────────────────────────────────

    /**
     * @test
     */
    public function healthCheck_returns_correct_structure(): void
    {
        $request = $this->createMockRequest();

        $response = $this->controller->healthCheck($request);

        $this->assertInstanceOf('WP_REST_Response', $response);

        $data = $response->get_data();
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('unity_available', $data);
    }

    /**
     * @test
     */
    public function healthCheck_timestamp_is_iso_format(): void
    {
        $request = $this->createMockRequest();

        $response = $this->controller->healthCheck($request);
        $data = $response->get_data();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $data['timestamp']
        );
    }

    /**
     * @test
     */
    public function healthCheck_returns_version(): void
    {
        $request = $this->createMockRequest();

        $response = $this->controller->healthCheck($request);
        $data = $response->get_data();

        $this->assertEquals(INTEGRITY_VERSION, $data['version']);
    }

    // ── Error response structure ───────────────────────────────────────

    /**
     * @test
     */
    public function error_responses_never_contain_debug_field(): void
    {
        // Verify the error response template used throughout the controller
        // does not contain a 'debug' key (C3 fix)
        $response = new \WP_REST_Response([
            'success' => false,
            'error' => [
                'code' => 'internal_error',
                'message' => 'An internal error occurred',
            ],
        ], 500);

        $data = $response->get_data();

        $this->assertArrayNotHasKey('debug', $data['error']);
    }


    // ── Obscured value detection ───────────────────────────────────────
    //
    // Detection lives in ControllerTrait::isObscuredEmail / isObscuredPhone
    // and is exercised by MemberController integration tests. Previous tests
    // at this location targeted RestController via reflection (incorrect —
    // the methods are on the trait used by MemberController) and asserted
    // that RFC-valid emails like "j__n@example.com" were treated as masked
    // (the M-10 bug). Removed.
}

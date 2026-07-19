<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api;

use Integrity\Api\Controllers\GroupController;
use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Api\Controllers\MemberController;
use Integrity\Api\Controllers\PositionController;
use Integrity\Api\RestController;
use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\AuditLogger;
use Integrity\Auth\RateLimiter;
use Integrity\Tests\TestCase;
use Mockery;
use WP_Mock;

/**
 * Unit tests for the refactored RestController (instance-based DI)
 */
class RestControllerTest extends TestCase
{
    private ApiKeyManager|Mockery\MockInterface $apiKeyManager;
    private AuditLogger|Mockery\MockInterface $auditLogger;
    private RateLimiter|Mockery\MockInterface $rateLimiter;
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
        $this->groupController = Mockery::mock(GroupController::class);
        $this->meetingController = Mockery::mock(MeetingController::class);
        $this->positionController = Mockery::mock(PositionController::class);
        $this->memberController = Mockery::mock(MemberController::class);
        $this->intergroupMeetingController = Mockery::mock(IntergroupMeetingController::class);

        $this->controller = new RestController(
            $this->apiKeyManager,
            $this->auditLogger,
            $this->rateLimiter,
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
        // Groups (2) + Meetings (2) + Positions (2) + Members (5) +
        // Intergroup Meetings (6) + Health (1) = 18
        $registeredRoutes = [];

        WP_Mock::userFunction('register_rest_route')
            ->times(18)
            ->andReturnUsing(
                function (string $namespace, string $route) use (&$registeredRoutes): bool {
                    $registeredRoutes[] = $namespace . $route;
                    return true;
                }
            );

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

        // Assert on the observable result rather than WP_Mock's
        // assertConditionsMet(): that helper lives on WP_Mock's own TestCase,
        // which this suite does not extend. The ->times(18) and ->once()
        // expectations above are still verified, by WP_Mock::tearDown() and
        // Mockery::close() respectively.
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

        WP_Mock::userFunction('get_option')
            ->with('integrity_require_https', true)
            ->andReturn(false);

        WP_Mock::userFunction('is_ssl')
            ->andReturn(true);

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

        WP_Mock::userFunction('get_option')
            ->with('integrity_require_https', true)
            ->andReturn(false);

        WP_Mock::userFunction('is_ssl')
            ->andReturn(true);

        $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');
        $this->apiKeyManager->shouldReceive('validateKey')
            ->with('int_invalid_key', '127.0.0.1')
            ->andReturn(null);

        $this->auditLogger->shouldReceive('log')->once();

        $result = $this->controller->checkPermission($request);

        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('invalid_api_key', $result->get_error_code());
    }

    // ── Auth: rate limited ─────────────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_returns_error_when_rate_limited(): void
    {
        $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_valid_key_12345678']);

        WP_Mock::userFunction('get_option')
            ->with('integrity_require_https', true)
            ->andReturn(false);

        WP_Mock::userFunction('is_ssl')
            ->andReturn(true);

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
        // add_filter is intercepted by WP_Mock itself, so it cannot be stubbed
        // with userFunction; and any closure matches, because WP_Mock keys
        // hooked callbacks by identity and normalises all closures alike.
        WP_Mock::expectFilterAdded('rest_post_dispatch', function (): void {
        });

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

        WP_Mock::userFunction('get_option')
            ->with('integrity_require_https', true)
            ->andReturn(false);

        WP_Mock::userFunction('is_ssl')
            ->andReturn(true);

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

        WP_Mock::userFunction('get_option')
            ->with('integrity_require_https', true)
            ->andReturn(false);

        WP_Mock::userFunction('is_ssl')
            ->andReturn(true);

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

        WP_Mock::userFunction('get_option')
            ->with('integrity_require_https', true)
            ->andReturn(false);

        WP_Mock::userFunction('is_ssl')
            ->andReturn(true);

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
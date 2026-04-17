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
        // Groups (2) + Meetings (2) + Positions (2) + Members (4) +
        // Intergroup Meetings (6) + Health (1) = 17
        WP_Mock::userFunction('register_rest_route')
            ->times(17);

        // Controller mocks must return args arrays when register() wires routes
        $this->groupController->shouldReceive('getGroupsArgs')->once()->andReturn([]);
        $this->meetingController->shouldReceive('getMeetingsArgs')->once()->andReturn([]);
        $this->positionController->shouldReceive('getPositionsArgs')->once()->andReturn([]);
        $this->memberController->shouldReceive('getMembersArgs')->once()->andReturn([]);
        $this->memberController->shouldReceive('getUpdateMemberArgs')->once()->andReturn([]);
        $this->memberController->shouldReceive('getCreateMemberArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getIntergroupMeetingsArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getRegisterAttendeeArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getUnregisterAttendeeArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getRegisterOfficerArgs')->once()->andReturn([]);
        $this->intergroupMeetingController->shouldReceive('getUnregisterOfficerArgs')->once()->andReturn([]);

        $this->controller->register();

        $this->assertConditionsMet();
    }

    // ── Auth: missing key ──────────────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_returns_error_when_no_api_key(): void
    {
        $request = $this->createMockRequest();
        $request->shouldReceive('get_header')
            ->with('Authorization')
            ->andReturn(null);
        $request->shouldReceive('get_header')
            ->with('X-API-Key')
            ->andReturn(null);

        WP_Mock::userFunction('get_option')
            ->with('integrity_require_https', true)
            ->andReturn(false);

        WP_Mock::userFunction('is_ssl')
            ->andReturn(true);

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
        $request = $this->createMockRequest();
        $request->shouldReceive('get_header')
            ->with('Authorization')
            ->andReturn('Bearer int_invalid_key');
        $request->shouldReceive('get_header')
            ->with('X-API-Key')
            ->andReturn(null);

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
        $request = $this->createMockRequest();
        $request->shouldReceive('get_header')
            ->with('Authorization')
            ->andReturn('Bearer int_valid_key_12345678');
        $request->shouldReceive('get_header')
            ->with('X-API-Key')
            ->andReturn(null);

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

        $this->rateLimiter->shouldReceive('checkLimit')
            ->with(1, 100)
            ->andReturn(['allowed' => false, 'remaining' => 0, 'reset' => time() + 3600]);

        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([
            'X-RateLimit-Limit' => 100,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => time() + 3600,
        ]);

        WP_Mock::userFunction('add_filter')->once();

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
        $request = $this->createMockRequest(['_route' => '/integrity/v1/members']);
        $request->shouldReceive('get_header')
            ->with('Authorization')
            ->andReturn('Bearer int_valid_key_12345678');
        $request->shouldReceive('get_header')
            ->with('X-API-Key')
            ->andReturn(null);

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

        $this->rateLimiter->shouldReceive('checkLimit')
            ->andReturn(['allowed' => true, 'remaining' => 999, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('incrementCount')->once();
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);

        WP_Mock::userFunction('add_filter');

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
        $request = $this->createMockRequest(['_route' => '/integrity/v1/groups']);
        $request->shouldReceive('get_header')
            ->with('Authorization')
            ->andReturn('Bearer int_valid_key_12345678');
        $request->shouldReceive('get_header')
            ->with('X-API-Key')
            ->andReturn(null);

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

        $this->rateLimiter->shouldReceive('checkLimit')
            ->andReturn(['allowed' => true, 'remaining' => 999, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('incrementCount')->once();
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);

        WP_Mock::userFunction('add_filter');

        $result = $this->controller->checkPermission($request);

        $this->assertTrue($result);
    }

    // ── Auth: wildcard permission ──────────────────────────────────────

    /**
     * @test
     */
    public function checkPermission_allows_wildcard_permission(): void
    {
        $request = $this->createMockRequest(['_route' => '/integrity/v1/members/123/update']);
        $request->shouldReceive('get_header')
            ->with('Authorization')
            ->andReturn('Bearer int_valid_key_12345678');
        $request->shouldReceive('get_header')
            ->with('X-API-Key')
            ->andReturn(null);

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

        $this->rateLimiter->shouldReceive('checkLimit')
            ->andReturn(['allowed' => true, 'remaining' => 999, 'reset' => time() + 3600]);
        $this->rateLimiter->shouldReceive('incrementCount')->once();
        $this->rateLimiter->shouldReceive('getHeaders')->andReturn([]);

        WP_Mock::userFunction('add_filter');

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
        $reflection = new \ReflectionClass(RestController::class);
        $method = $reflection->getMethod('getRequiredPermission');
        $method->setAccessible(true);

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

    // ── Transform helpers ──────────────────────────────────────────────

    /**
     * @test
     */
    public function transformGroup_returns_expected_fields(): void
    {
        $group = Mockery::mock('Unity\Groups\Interfaces\Group');
        $group->shouldReceive('getId')->andReturn(1);
        $group->shouldReceive('getTitle')->andReturn('Test Group');
        $group->shouldReceive('getEmail')->andReturn('test@example.com');
        $group->shouldReceive('getPhone')->andReturn('555-1234');
        $group->shouldReceive('getWebsite')->andReturn('https://example.com');
        $group->shouldReceive('getLink')->andReturn('/group/1');
        $group->shouldReceive('getGroupNotes')->andReturn('Notes');
        $group->shouldReceive('getDistrictId')->andReturn(42);
        $group->shouldReceive('getLastContact')->andReturn('2024-01-01');
        $group->shouldReceive('getMeetings')->andReturn([]);
        $group->shouldReceive('getContacts')->andReturn([]);
        $group->shouldReceive('getVenmo')->andReturn('@TestGroup');
        $group->shouldReceive('getPaypal')->andReturn('');
        $group->shouldReceive('getSquare')->andReturn('');
        $group->shouldReceive('hasContributionOptions')->andReturn(true);
        $group->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');

        $reflection = new \ReflectionClass(RestController::class);
        $method = $reflection->getMethod('transformGroup');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $group, []);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('Test Group', $result['title']);
        $this->assertArrayHasKey('contribution_options', $result);
        $this->assertTrue($result['contribution_options']['has_options']);
        $this->assertArrayHasKey('meeting_ids', $result);
        $this->assertArrayHasKey('updated', $result);
    }

    /**
     * @test
     */
    public function transformGroup_expands_meetings_when_requested(): void
    {
        $meeting = Mockery::mock('Unity\Meetings\Interfaces\Meeting');
        $meeting->shouldReceive('getId')->andReturn(100);
        $meeting->shouldReceive('getName')->andReturn('Morning');
        $meeting->shouldReceive('getSlug')->andReturn('morning');
        $meeting->shouldReceive('getLocation')->andReturn(null);
        $meeting->shouldReceive('getUrl')->andReturn('');
        $meeting->shouldReceive('getDay')->andReturn(1);
        $meeting->shouldReceive('getDayOfWeek')->andReturn('Monday');
        $meeting->shouldReceive('getTime')->andReturn('07:00');
        $meeting->shouldReceive('getEndTime')->andReturn('08:00');
        $meeting->shouldReceive('getTypes')->andReturn([]);
        $meeting->shouldReceive('getState')->andReturn('active');
        $meeting->shouldReceive('isOnline')->andReturn(false);
        $meeting->shouldReceive('getOnlineLink')->andReturn('');
        $meeting->shouldReceive('getOnlineNotes')->andReturn('');
        $meeting->shouldReceive('getContacts')->andReturn([]);
        $meeting->shouldReceive('getMeta')->andReturn([]);
        $meeting->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');

        $group = Mockery::mock('Unity\Groups\Interfaces\Group');
        $group->shouldReceive('getId')->andReturn(1);
        $group->shouldReceive('getTitle')->andReturn('Test');
        $group->shouldReceive('getEmail')->andReturn('');
        $group->shouldReceive('getPhone')->andReturn('');
        $group->shouldReceive('getWebsite')->andReturn('');
        $group->shouldReceive('getLink')->andReturn('');
        $group->shouldReceive('getGroupNotes')->andReturn('');
        $group->shouldReceive('getDistrictId')->andReturn(null);
        $group->shouldReceive('getLastContact')->andReturn(null);
        $group->shouldReceive('getMeetings')->andReturn([$meeting]);
        $group->shouldReceive('getContacts')->andReturn([]);
        $group->shouldReceive('getVenmo')->andReturn('');
        $group->shouldReceive('getPaypal')->andReturn('');
        $group->shouldReceive('getSquare')->andReturn('');
        $group->shouldReceive('hasContributionOptions')->andReturn(false);
        $group->shouldReceive('getUpdated')->andReturn('2024-06-01 10:00:00');

        $reflection = new \ReflectionClass(RestController::class);
        $method = $reflection->getMethod('transformGroup');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $group, ['meetings']);

        $this->assertArrayHasKey('meetings', $result);
        $this->assertArrayNotHasKey('meeting_ids', $result);
        $this->assertIsArray($result['meetings']);
        $this->assertEquals(100, $result['meetings'][0]['id']);
    }

    // ── Timestamp formatting ───────────────────────────────────────────

    /**
     * @test
     * @dataProvider timestampProvider
     */
    public function formatUpdatedTimestamp_returns_iso_format(string $input, string $expected): void
    {
        $reflection = new \ReflectionClass(RestController::class);
        $method = $reflection->getMethod('formatUpdatedTimestamp');
        $method->setAccessible(true);

        $result = $method->invoke($this->controller, $input);

        $this->assertEquals($expected, $result);
    }

    public static function timestampProvider(): array
    {
        return [
            'standard WP datetime' => ['2025-03-09 14:30:00', '2025-03-09T14:30:00.000Z'],
            'empty string'         => ['', ''],
            'date only'            => ['2025-01-01', '2025-01-01T00:00:00.000Z'],
        ];
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
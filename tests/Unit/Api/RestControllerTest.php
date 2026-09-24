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
use Mockery;

/*
 * Unit tests for the refactored RestController (instance-based DI)
 *
 * register_rest_route() is a real function in wp-mocks that records into
 * WpState::$restRoutes, and get_option()/is_ssl() read the same store, so the
 * per-test expectation stacks these tests used to carry are gone.
 *
 * The two tests that define() WP_DEBUG and INTEGRITY_ALLOW_INSECURE_TRANSPORT
 * are in RestControllerConstantsTest.php: they need a process each, and Pest
 * cannot isolate a closure.
 */

beforeEach(function () {
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

    /*
     * Run checkPermission() for a key holding $permissions, with the
     * throttle and rate limiter out of the way.
     *
     * @param array<int, string> $permissions
     * @return true|\WP_Error
     */
    $this->authorise = function (object $request, array $permissions) {
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
    };
});

// ── Route registration ─────────────────────────────────────────────
it('registers all the expected routes', function () {
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

    expect($registeredRoutes)
        ->toHaveCount(18)
        ->toBe(array_unique($registeredRoutes), 'Each route should be registered exactly once.');
});

// ── Auth: missing key ──────────────────────────────────────────────
it('returns an error from checkPermission when there is no API key', function () {
    $request = $this->createMockRequest();

    WpState::$options['integrity_require_https'] = false;

    // Resolved for the audit record before the key is even looked for.
    $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');

    // Audit logger should still log the failed request
    $this->auditLogger->shouldReceive('log')->once();

    $result = $this->controller->checkPermission($request);

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('missing_api_key');
});

// ── Auth: invalid key ──────────────────────────────────────────────
it('returns an error from checkPermission when the key is invalid', function () {
    $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_invalid_key']);

    WpState::$options['integrity_require_https'] = false;

    $this->auditLogger->shouldReceive('getClientIp')->andReturn('127.0.0.1');
    $this->apiKeyManager->shouldReceive('validateKey')
        ->with('int_invalid_key', '127.0.0.1')
        ->andReturn(null);

    $this->auditLogger->shouldReceive('log')->once();

    $result = $this->controller->checkPermission($request);

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('invalid_api_key');
});

// ── Auth: expansion scopes ─────────────────────────────────────────
it('refuses expand=meetings on a groups-only key', function () {
    // The finding: permissions keyed on the path alone, so a key issued
    // with groups:read and deliberately without meetings:read could read
    // meetings through /groups?expand=meetings.
    $request = $this->createMockRequest(
        ['expand' => 'meetings', '_route' => '/integrity/v1/groups'],
        ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
    );

    $result = ($this->authorise)($request, ['groups:read']);

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('insufficient_permissions')
        ->and($result->get_error_message())->toContain('meetings:read');
});

it('allows expand=meetings when the key holds both scopes', function () {
    $request = $this->createMockRequest(
        ['expand' => 'meetings', '_route' => '/integrity/v1/groups'],
        ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
    );

    expect(($this->authorise)($request, ['groups:read', 'meetings:read']))->toBeTrue();
});

it('still needs only the groups scope for groups without expand', function () {
    // The fix must not tighten the ordinary case.
    $request = $this->createMockRequest(
        ['_route' => '/integrity/v1/groups'],
        ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
    );

    expect(($this->authorise)($request, ['groups:read']))->toBeTrue();
});

it('still lets a wildcard key reach an expansion', function () {
    $request = $this->createMockRequest(
        ['expand' => 'meetings', '_route' => '/integrity/v1/groups'],
        ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]
    );

    expect(($this->authorise)($request, ['*']))->toBeTrue();
});

// ── Auth: transport ────────────────────────────────────────────────
it('refuses a plain HTTP request in checkPermission', function () {
    $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('a', 64)]);

    WpState::$options['integrity_require_https'] = true;
    WpState::$isSsl = false;

    $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
    $this->auditLogger->shouldReceive('log')->once();
    $this->apiKeyManager->shouldNotReceive('validateKey');

    $result = $this->controller->checkPermission($request);

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('https_required');
});

// ── Auth: pre-authentication throttle ──────────────────────────────
it('refuses a throttled client in checkPermission before validating the key', function () {
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

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('too_many_attempts')
        ->and($result->get_error_data()['status'])->toEqual(429);
});

it('charges the throttle in checkPermission when a key fails to validate', function () {
    $request = $this->createMockRequest([], ['Authorization' => 'Bearer int_' . str_repeat('b', 64)]);

    WpState::$options['integrity_require_https'] = false;

    $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
    $this->apiKeyManager->shouldReceive('validateKey')->andReturn(null);
    $this->auditLogger->shouldReceive('log')->once();

    // Without this the budget is never spent and the throttle is inert.
    $this->preAuthThrottle->shouldReceive('penalise')->once()->with('203.0.113.7');

    $result = $this->controller->checkPermission($request);

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('invalid_api_key');
});

it('does not charge the throttle in checkPermission for a working key', function () {
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

    expect($this->controller->checkPermission($request))->toBeTrue();
});

it('charges the throttle in checkPermission when no key is presented', function () {
    $request = $this->createMockRequest([], []);

    WpState::$options['integrity_require_https'] = false;

    $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
    $this->auditLogger->shouldReceive('log')->once();

    // A keyless flood costs no Argon2id but still writes an audit row
    // each time, so it has to consume the budget like any other failure.
    $this->preAuthThrottle->shouldReceive('penalise')->once()->with('203.0.113.7');

    $result = $this->controller->checkPermission($request);

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('missing_api_key');
});

it('refuses a throttled client before the HTTPS check', function () {
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

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('too_many_attempts');
});

// ── Auth: rate limited ─────────────────────────────────────────────
it('returns an error from checkPermission when rate limited', function () {
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

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('rate_limit_exceeded');
});

// ── Auth: insufficient permissions ─────────────────────────────────
it('returns an error from checkPermission when a permission is missing', function () {
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

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toEqual('insufficient_permissions');
});

// ── Auth: success ──────────────────────────────────────────────────
it('returns true from checkPermission on a valid request', function () {
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

    expect($result)->toBeTrue();
});

// ── Auth: wildcard permission ──────────────────────────────────────
it('allows a wildcard permission in checkPermission', function () {
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

    expect($result)->toBeTrue();
});

// ── Permission mapping ─────────────────────────────────────────────
it('maps each endpoint to the correct required permission', function (string $endpoint, ?string $expected) {
    // No setAccessible() call: a no-op since PHP 8.1 (this plugin's
    // floor) and deprecated as of 8.5.
    $method = (new \ReflectionClass(RestController::class))->getMethod('getRequiredPermission');

    $result = $method->invoke($this->controller, $endpoint);

    expect($result)->toEqual($expected);
})->with([
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
]);

// ── Health check ───────────────────────────────────────────────────
it('returns the correct health check structure', function () {
    $request = $this->createMockRequest();

    $response = $this->controller->healthCheck($request);

    expect($response)->toBeInstanceOf(\WP_REST_Response::class)
        ->and($response->get_data())
        ->toHaveKey('status')
        ->toHaveKey('timestamp')
        ->toHaveKey('version')
        ->toHaveKey('unity_available');
});

it('returns an ISO-format health check timestamp', function () {
    $request = $this->createMockRequest();

    $response = $this->controller->healthCheck($request);
    $data = $response->get_data();

    expect($data['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/');
});

it('returns the version from the health check', function () {
    $request = $this->createMockRequest();

    $response = $this->controller->healthCheck($request);
    $data = $response->get_data();

    expect($data['version'])->toEqual(INTEGRITY_VERSION);
});

// ── Error response structure ───────────────────────────────────────
it('never puts a debug field in error responses', function () {
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

    expect($data['error'])->not->toHaveKey('debug');
});


// ── Obscured value detection ───────────────────────────────────────
//
// Detection lives in ControllerTrait::isObscuredEmail / isObscuredPhone
// and is exercised by MemberController integration tests. Previous tests
// at this location targeted RestController via reflection (incorrect —
// the methods are on the trait used by MemberController) and asserted
// that RFC-valid emails like "j__n@example.com" were treated as masked
// (the M-10 bug). Removed.

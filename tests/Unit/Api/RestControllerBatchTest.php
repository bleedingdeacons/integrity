<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api;

use BleedingDeacons\WpMocks\WpState;
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
 * Batch support: the write routes opt in to core's POST /wp-json/batch/v1,
 * and checkPermission() authenticates sub-requests against the outer HTTP
 * request's credentials, validating the key once per batch.
 *
 * Core builds each sub-request from the batch body, so the client's
 * Authorization header is only ever on the outer request. These tests drive
 * beginBatch()/endBatch() directly in place of core's
 * rest_request_before_callbacks / rest_request_after_callbacks.
 */

beforeEach(function () {
    $this->apiKeyManager = Mockery::mock(ApiKeyManager::class);
    $this->auditLogger = Mockery::mock(AuditLogger::class);
    $this->rateLimiter = Mockery::mock(RateLimiter::class);
    $this->preAuthThrottle = Mockery::mock(PreAuthThrottle::class);
    $this->preAuthThrottle->shouldReceive('isBlocked')->andReturn(false)->byDefault();
    $this->preAuthThrottle->shouldReceive('penalise')->andReturnNull()->byDefault();
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

    WpState::$options['integrity_require_https'] = false;
    $this->auditLogger->shouldReceive('getClientIp')->andReturn('203.0.113.7');
    $this->auditLogger->shouldReceive('log')->zeroOrMoreTimes();
    $this->rateLimiter->shouldReceive('getHeaders')->andReturn([])->byDefault();

    $this->outer = $this->createMockRequest(
        ['_route' => '/batch/v1', '_method' => 'POST'],
        ['Authorization' => 'Bearer int_outer_key']
    );

    $this->subRequest = fn (array $headers = []) => $this->createMockRequest(
        ['_route' => '/integrity/v1/members/5/update', '_method' => 'POST'],
        $headers
    );

    $this->allowRateLimit = function (): void {
        $this->rateLimiter->shouldReceive('checkAndIncrement')
            ->andReturn(['allowed' => true, 'remaining' => 99, 'reset' => time() + 3600]);
    };
});

// ── Route registration ─────────────────────────────────────────────
it('opts every write route into batch v1 and leaves every read route out', function () {
    foreach (
        [
            [$this->groupController, ['getGroupsArgs']],
            [$this->meetingController, ['getMeetingsArgs']],
            [$this->positionController, ['getPositionsArgs']],
            [$this->memberController, ['getMembersArgs', 'getUpdateMemberArgs', 'getCreateMemberArgs', 'getRecordComplianceArgs']],
            [$this->intergroupMeetingController, [
                'getIntergroupMeetingsArgs',
                'getRegisterAttendeeArgs',
                'getUnregisterAttendeeArgs',
                'getRegisterOfficerArgs',
                'getUnregisterOfficerArgs',
            ]],
        ] as [$mock, $methods]
    ) {
        foreach ($methods as $method) {
            $mock->shouldReceive($method)->andReturn([]);
        }
    }

    $this->controller->register();

    $writes = array_filter(WpState::$restRoutes, static fn (array $r): bool => $r['args']['methods'] === 'POST');
    $reads  = array_filter(WpState::$restRoutes, static fn (array $r): bool => $r['args']['methods'] !== 'POST');

    expect($writes)->toHaveCount(7);
    foreach ($writes as $route) {
        expect($route['args']['allow_batch'] ?? null)->toBe(['v1' => true], $route['route']);
    }
    foreach ($reads as $route) {
        expect($route['args'])->not->toHaveKey('allow_batch');
    }
});

// ── Batch lifecycle ────────────────────────────────────────────────
it('passes the response through untouched at both ends of a batch', function () {
    $response = new \stdClass();

    expect($this->controller->beginBatch($response, [], $this->outer))->toBe($response)
        ->and($this->controller->endBatch($response, [], $this->outer))->toBe($response);
});

it('does not treat an ordinary request as the start of a batch', function () {
    $this->controller->beginBatch(null, [], ($this->subRequest)(['Authorization' => 'Bearer int_other']));

    $result = $this->controller->checkPermission(($this->subRequest)());

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toBe('missing_api_key');
});

// ── Authentication inside a batch ──────────────────────────────────
it('authenticates a sub-request with the credential on the outer batch request', function () {
    ($this->allowRateLimit)();
    $this->apiKeyManager->shouldReceive('validateKey')
        ->once()
        ->with('int_outer_key', '203.0.113.7')
        ->andReturn($this->createMockApiKeyData(['permissions' => ['members:write']]));

    $this->controller->beginBatch(null, [], $this->outer);

    expect($this->controller->checkPermission(($this->subRequest)()))->toBeTrue();
});

it('validates the key once per batch but charges the rate limit per sub-request', function () {
    $this->apiKeyManager->shouldReceive('validateKey')
        ->once()
        ->andReturn($this->createMockApiKeyData(['permissions' => ['members:write']]));
    $this->rateLimiter->shouldReceive('checkAndIncrement')
        ->times(3)
        ->andReturn(['allowed' => true, 'remaining' => 99, 'reset' => time() + 3600]);

    $this->controller->beginBatch(null, [], $this->outer);

    for ($i = 0; $i < 3; $i++) {
        expect($this->controller->checkPermission(($this->subRequest)()))->toBeTrue();
    }
});

it('holds a sub-request that names its own key to that key', function () {
    ($this->allowRateLimit)();
    $this->apiKeyManager->shouldReceive('validateKey')
        ->once()
        ->with('int_inner_key', '203.0.113.7')
        ->andReturn($this->createMockApiKeyData(['permissions' => ['members:write']]));

    $this->controller->beginBatch(null, [], $this->outer);

    expect($this->controller->checkPermission(($this->subRequest)(['Authorization' => 'Bearer int_inner_key'])))
        ->toBeTrue();
});

it('still checks scopes per sub-request when the key is shared', function () {
    ($this->allowRateLimit)();
    $this->apiKeyManager->shouldReceive('validateKey')
        ->once()
        ->andReturn($this->createMockApiKeyData(['permissions' => ['members:write']]));

    $this->controller->beginBatch(null, [], $this->outer);

    $members = $this->controller->checkPermission(($this->subRequest)());
    $register = $this->controller->checkPermission($this->createMockRequest(
        ['_route' => '/integrity/v1/intergroup-meetings/3/register-group', '_method' => 'POST']
    ));

    expect($members)->toBeTrue()
        ->and($register)->toBeInstanceOf(\WP_Error::class)
        ->and($register->get_error_code())->toBe('insufficient_permissions');
});

it('charges the throttle once for a bad key reused across a batch', function () {
    $this->apiKeyManager->shouldReceive('validateKey')->once()->andReturn(null);
    $this->preAuthThrottle->shouldReceive('penalise')->once()->with('203.0.113.7');

    $this->controller->beginBatch(null, [], $this->outer);

    for ($i = 0; $i < 3; $i++) {
        $result = $this->controller->checkPermission(($this->subRequest)());
        expect($result)->toBeInstanceOf(\WP_Error::class)
            ->and($result->get_error_code())->toBe('invalid_api_key');
    }
});

it('forgets the outer credential and the cached key once the batch ends', function () {
    ($this->allowRateLimit)();
    $this->apiKeyManager->shouldReceive('validateKey')
        ->andReturn($this->createMockApiKeyData(['permissions' => ['members:write']]));

    $this->controller->beginBatch(null, [], $this->outer);
    expect($this->controller->checkPermission(($this->subRequest)()))->toBeTrue();
    $this->controller->endBatch(null, [], $this->outer);

    $result = $this->controller->checkPermission(($this->subRequest)());

    expect($result)->toBeInstanceOf(\WP_Error::class)
        ->and($result->get_error_code())->toBe('missing_api_key');
});

it('validates afresh in the next batch rather than reusing the last one', function () {
    ($this->allowRateLimit)();
    $this->apiKeyManager->shouldReceive('validateKey')
        ->twice()
        ->andReturn($this->createMockApiKeyData(['permissions' => ['members:write']]));

    foreach ([1, 2] as $_) {
        $this->controller->beginBatch(null, [], $this->outer);
        expect($this->controller->checkPermission(($this->subRequest)()))->toBeTrue();
        $this->controller->endBatch(null, [], $this->outer);
    }
});

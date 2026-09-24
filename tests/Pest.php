<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test in this suite runs on Integrity\Tests\TestCase, which wraps
// wp-mocks': Brain Monkey's lifecycle, Mockery integration, the WordPress
// stand-ins and the createMockRequest()/createMockApiKeyData() helpers. There
// is no pure-PHP split here as there is in Scrutiny and Trusted, so the whole
// Unit directory is bound. A test file added anywhere under it gets the same
// base class, and with it Brain Monkey — without that, add_action() and the
// rest of the hook layer are simply undefined.
//
// One file stays a PHPUnit class rather than Pest closures:
// Api/RestControllerConstantsTest.php. Its two tests define WP_DEBUG and
// INTEGRITY_ALLOW_INSECURE_TRANSPORT, which cannot be undone once defined, so
// they must run in a separate process — and Pest refuses process isolation
// outright. Pest runs the class as it is.

//
// The beforeEach below replaces something process isolation used to give for
// free. Seven controller suites ran every test in its own process, because
// they alias-mocked Unity\Plugin; they now run in-process. HasLogger memoises
// its channel in a static per using class, so the first test to log through
// Integrity\Plugin or RestController would otherwise fix the channel for every
// test after it, and logChannel() would never run again. That is a leak
// between tests, and it showed up as a line of Integrity\Plugin dropping out of
// coverage. Only classes already loaded are reset, so this never includes a
// source file on its own account.

use Integrity\Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function () {
        foreach ([\Integrity\Plugin::class, \Integrity\Api\RestController::class] as $class) {
            if (class_exists($class, false)) {
                (new ReflectionProperty($class, 'loggerChannel'))->setValue(null, null);
            }
        }
    })
    ->in('Unit');

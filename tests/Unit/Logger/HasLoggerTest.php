<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Logger;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use Integrity\Logger\HasLogger;
use ReflectionClass;

/*
 * The HasLogger trait resolves the shared Sentinel logger via wp_log() and
 * degrades to a silent no-op when it hands back nothing.
 *
 * Both paths are covered here. Before the move to wp-mocks only the degraded
 * one was: wp_log() did not exist in the unit run at all, so every forwarder
 * ran against a null channel and the test could only assert that nothing blew
 * up. The shared `sentinel` stub group supplies a recording channel, so what
 * the trait actually forwards is now assertable — and the absent case is still
 * reachable by making wp_log() return null.
 *
 * The channel is memoised per using-class, so the static cache is reset around
 * each test.
 */

covers(HasLogger::class);

/** A class that uses the trait without overriding logChannel(). */
class IntegrityLoggerHost
{
    use HasLogger;
}

/**
 * Reset the trait's per-class static channel cache so each test starts
 * from a clean slate (the property is private static on the trait).
 */
function resetIntegrityLoggerHostChannel(): void
{
    $ref = new ReflectionClass(IntegrityLoggerHost::class);
    if ($ref->hasProperty('loggerChannel')) {
        // No setAccessible() call: it has been a no-op since PHP 8.1 —
        // which this plugin requires — and is deprecated from 8.5.
        $ref->getProperty('loggerChannel')->setValue(null, null);
    }
}

beforeEach(function () {
    resetIntegrityLoggerHostChannel();
});

afterEach(function () {
    resetIntegrityLoggerHostChannel();
});

it('resolves the channel once and memoises it', function () {
    $channel = new \Sentinel_Log_Channel();

    // logChannel() derives the name from the class basename via
    // sanitize_key(); wp_log() is called once and the result cached.
    Functions\expect('wp_log')->once()->with('integrityloggerhost')->andReturn($channel);

    $first  = IntegrityLoggerHost::log();
    $second = IntegrityLoggerHost::log();

    expect($first)->toBe($channel)
        ->and($second)->toBe($channel, 'channel must be memoised, not re-resolved');
});

it('forwards every level to the channel', function () {
    $channel = new \Sentinel_Log_Channel();
    Functions\expect('wp_log')->andReturn($channel);

    IntegrityLoggerHost::logEmergency('m', ['k' => 'v']);
    IntegrityLoggerHost::logAlert('m');
    IntegrityLoggerHost::logCritical('m');
    IntegrityLoggerHost::logError('m');
    IntegrityLoggerHost::logWarning('m');
    IntegrityLoggerHost::logNotice('m');
    IntegrityLoggerHost::logInfo('m');
    IntegrityLoggerHost::logDebug('m');

    expect($channel->levels())
        ->toBe(['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']);
});

it('makes every level a safe no-op without a channel', function () {
    // The logger mu-plugin is not deployed: wp_log() answers with nothing,
    // and every forwarder has to fall through its null-safe call.
    Functions\when('wp_log')->justReturn(null);

    expect(IntegrityLoggerHost::log())->toBeNull();

    IntegrityLoggerHost::logEmergency('m', ['k' => 'v']);
    IntegrityLoggerHost::logAlert('m');
    IntegrityLoggerHost::logCritical('m');
    IntegrityLoggerHost::logError('m');
    IntegrityLoggerHost::logWarning('m');
    IntegrityLoggerHost::logNotice('m');
    IntegrityLoggerHost::logInfo('m');
    IntegrityLoggerHost::logDebug('m');

    expect(WpState::$logs)->toBe([], 'nothing was logged');
});

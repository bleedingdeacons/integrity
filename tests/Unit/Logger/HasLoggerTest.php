<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Logger;

use Brain\Monkey\Functions;
use Integrity\Logger\HasLogger;
use Integrity\Tests\TestCase;
use ReflectionClass;

/**
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
 *
 * @covers \Integrity\Logger\HasLogger
 */
class HasLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetChannel();
    }

    protected function tearDown(): void
    {
        $this->resetChannel();
        parent::tearDown();
    }

    /** @test */
    public function log_resolves_the_channel_once_and_memoises_it(): void
    {
        $channel = new \Sentinel_Log_Channel();

        // logChannel() derives the name from the class basename via
        // sanitize_key(); wp_log() is called once and the result cached.
        Functions\expect('wp_log')->once()->with('integrityloggerhost')->andReturn($channel);

        $first  = IntegrityLoggerHost::log();
        $second = IntegrityLoggerHost::log();

        $this->assertSame($channel, $first);
        $this->assertSame($channel, $second, 'channel must be memoised, not re-resolved');
    }

    /** @test */
    public function every_level_forwards_to_the_channel(): void
    {
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

        $this->assertSame(
            ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
            $channel->levels(),
        );
    }

    /** @test */
    public function every_level_is_a_safe_noop_without_a_channel(): void
    {
        // The logger mu-plugin is not deployed: wp_log() answers with nothing,
        // and every forwarder has to fall through its null-safe call.
        Functions\when('wp_log')->justReturn(null);

        $this->assertNull(IntegrityLoggerHost::log());

        IntegrityLoggerHost::logEmergency('m', ['k' => 'v']);
        IntegrityLoggerHost::logAlert('m');
        IntegrityLoggerHost::logCritical('m');
        IntegrityLoggerHost::logError('m');
        IntegrityLoggerHost::logWarning('m');
        IntegrityLoggerHost::logNotice('m');
        IntegrityLoggerHost::logInfo('m');
        IntegrityLoggerHost::logDebug('m');

        $this->assertSame([], \BleedingDeacons\WpMocks\WpState::$logs, 'nothing was logged');
    }

    /**
     * Reset the trait's per-class static channel cache so each test starts
     * from a clean slate (the property is private static on the trait).
     */
    private function resetChannel(): void
    {
        $ref = new ReflectionClass(IntegrityLoggerHost::class);
        if ($ref->hasProperty('loggerChannel')) {
            // No setAccessible() call: it has been a no-op since PHP 8.1 —
            // which this plugin requires — and is deprecated from 8.5.
            $ref->getProperty('loggerChannel')->setValue(null, null);
        }
    }
}

/** A class that uses the trait without overriding logChannel(). */
class IntegrityLoggerHost
{
    use HasLogger;
}

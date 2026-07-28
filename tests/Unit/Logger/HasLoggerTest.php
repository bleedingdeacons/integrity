<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Logger;

use Integrity\Logger\HasLogger;
use Integrity\Tests\TestCase;

/**
 * The HasLogger trait resolves the shared Sentinel logger via wp_log() and
 * degrades to a silent no-op when it is unavailable (as in the unit run).
 * A host class drives every level forwarder to prove the bodies run against a
 * null channel without error.
 *
 * @covers \Integrity\Logger\HasLogger
 */
class HasLoggerTest extends TestCase
{
    /** @test */
    public function log_is_null_and_every_level_is_a_safe_noop_without_the_channel(): void
    {
        $this->assertNull(IntegrityLoggerHost::log());

        IntegrityLoggerHost::logEmergency('m', ['k' => 'v']);
        IntegrityLoggerHost::logAlert('m');
        IntegrityLoggerHost::logCritical('m');
        IntegrityLoggerHost::logError('m');
        IntegrityLoggerHost::logWarning('m');
        IntegrityLoggerHost::logNotice('m');
        IntegrityLoggerHost::logInfo('m');
        IntegrityLoggerHost::logDebug('m');

        $this->assertTrue(true);
    }
}

/** A class that uses the trait without overriding logChannel(). */
class IntegrityLoggerHost
{
    use HasLogger;
}

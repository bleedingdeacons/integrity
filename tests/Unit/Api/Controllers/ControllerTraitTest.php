<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Api\Controllers;

use Integrity\Api\Controllers\ControllerTrait;
use Integrity\Tests\TestCase;

/**
 * Tests for ControllerTrait's shared helpers.
 *
 * formatUpdatedTimestamp() lives here because the trait is where it lives in
 * the source; it used to be a RestController method, and its tests moved with
 * it.
 *
 * The detection methods below guard the update path in MemberController.
 *
 * These detection methods guard the update path in MemberController against
 * round-tripping a masked value back into storage. They must:
 *
 *   (a) correctly identify the sentinel shape emitted by {@see \Integrity\Utils\Mask},
 *   (b) NOT flag RFC-valid emails whose local part contains consecutive
 *       underscores (the M-10 regression),
 *   (c) NOT flag real phone numbers, even unusual ones with an embedded
 *       asterisk (e.g. an extension marker).
 */
class ControllerTraitTest extends TestCase
{
    /**
     * Anonymous subclass exposing the protected detection methods.
     */
    private function subject(): object
    {
        return new class {
            use ControllerTrait {
                isObscuredEmail as public;
                isObscuredPhone as public;
                formatUpdatedTimestamp as public;
            }
        };
    }

    // ── Timestamp formatting ──────────────────────────────────────────

    /**
     * @test
     * @dataProvider timestampProvider
     */
    public function formatUpdatedTimestamp_returns_iso_format(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->subject()->formatUpdatedTimestamp($input));
    }

    public static function timestampProvider(): array
    {
        return [
            'standard WP datetime' => ['2025-03-09 14:30:00', '2025-03-09T14:30:00.000Z'],
            'empty string'         => ['', ''],
            'date only'            => ['2025-01-01', '2025-01-01T00:00:00.000Z'],
        ];
    }

    // ── Email ─────────────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider emailProvider
     */
    public function isObscuredEmail_detects_mask_shape(string $input, bool $expected): void
    {
        $this->assertSame($expected, $this->subject()->isObscuredEmail($input));
    }

    public static function emailProvider(): array
    {
        return [
            // Positives: exact Mask::email() output shape
            'mask shape'                 => ['j___@e______.com', true],
            'mask shape short domain'    => ['j__@e__.co', true],
            'mask shape long underscores' => ['a____________@b____.io', true],

            // Negatives: RFC-valid emails that the old /__+/ regex falsely
            // flagged as masked (M-10)
            'valid double underscore'    => ['user__name@example.com', false],
            'valid triple underscore'    => ['j___@example.com', false],
            'valid dunder style'         => ['__init__@python.org', false],
            'valid mixed underscores'    => ['a_b__c@example.com', false],
            'valid short local'          => ['j__n@example.com', false],

            // Negatives: ordinary emails
            'normal email'               => ['john@example.com', false],
            'single underscore'          => ['john_doe@example.com', false],

            // Edge cases
            'empty'                      => ['', false],
            'no at sign'                 => ['j___e______.com', false],
            'no tld'                     => ['j___@e______', false],
        ];
    }

    // ── Phone ─────────────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider phoneProvider
     */
    public function isObscuredPhone_detects_mask_shape(string $input, bool $expected): void
    {
        $this->assertSame($expected, $this->subject()->isObscuredPhone($input));
    }

    public static function phoneProvider(): array
    {
        return [
            // Positives: exact Mask::phone() output shape
            'mask plain'                 => ['***1234', true],
            'mask formatted'             => ['(***) ***-5309', true],
            'mask international'         => ['+** **** 0123', true],
            'mask dashed'                => ['***-***-4567', true],

            // Negatives: real phone numbers, including unusual forms
            'normal phone'               => ['555-123-4567', false],
            'normal phone with parens'   => ['(555) 867-5309', false],
            'normal plain digits'        => ['5551234567', false],
            'normal international'       => ['+44 7700 900123', false],
            'real with extension marker' => ['555*1234', false],
            'real with double star'      => ['55**1234', false],

            // Edge cases
            'empty'                      => ['', false],
        ];
    }
}
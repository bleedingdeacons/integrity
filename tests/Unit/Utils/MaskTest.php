<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Utils;

use Integrity\Tests\TestCase;
use Integrity\Utils\Mask;

/**
 * Unit tests for Mask utility
 */
class MaskTest extends TestCase
{
    // ─── Email masking ────────────────────────────────────

    /**
     * @test
     */
    public function email_masks_standard_address(): void
    {
        $result = Mask::email('john@example.com');

        // First char of local + underscores, first char of domain + underscores, TLD preserved
        $this->assertStringStartsWith('j', $result);
        $this->assertStringContainsString('@', $result);
        $this->assertStringEndsWith('.com', $result);
        $this->assertStringContainsString('__', $result); // sentinel for isObscuredEmail()
    }

    /**
     * @test
     */
    public function email_returns_empty_for_empty_input(): void
    {
        $this->assertSame('', Mask::email(''));
    }

    /**
     * @test
     */
    public function email_returns_input_without_at_sign(): void
    {
        $this->assertSame('notanemail', Mask::email('notanemail'));
    }

    /**
     * @test
     */
    public function email_handles_short_local_part(): void
    {
        $result = Mask::email('a@b.co');

        $this->assertStringStartsWith('a', $result);
        $this->assertStringEndsWith('.co', $result);
        $this->assertStringContainsString('__', $result); // minimum 2 underscores
    }

    /**
     * @test
     */
    public function email_handles_subdomain(): void
    {
        $result = Mask::email('user@mail.example.co.uk');

        $this->assertStringStartsWith('u', $result);
        $this->assertStringEndsWith('.uk', $result);
        $this->assertStringContainsString('@', $result);
    }

    /**
     * @test
     */
    public function email_preserves_structure(): void
    {
        $result = Mask::email('longuser@longdomain.org');

        // Must contain exactly one @
        $this->assertSame(1, substr_count($result, '@'));
        // Must end with the original TLD
        $this->assertStringEndsWith('.org', $result);
    }

    /**
     * @test
     */
    public function email_output_triggers_isObscuredEmail_detection(): void
    {
        // The RestController uses /__+/ to detect masked emails.
        // Verify Mask output always contains consecutive underscores.
        $masked = Mask::email('test@example.com');

        $this->assertMatchesRegularExpression('/__+/', $masked);
    }

    // ─── Phone masking ────────────────────────────────────

    /**
     * @test
     */
    public function phone_masks_standard_number(): void
    {
        $result = Mask::phone('(555) 867-5309');

        // Last 4 digits visible, preceding digits replaced with *
        $this->assertStringEndsWith('5309', $result);
        $this->assertStringContainsString('*', $result);
        // Non-digit formatting preserved
        $this->assertStringContainsString('(', $result);
        $this->assertStringContainsString(')', $result);
        $this->assertStringContainsString('-', $result);
    }

    /**
     * @test
     */
    public function phone_returns_empty_for_empty_input(): void
    {
        $this->assertSame('', Mask::phone(''));
    }

    /**
     * @test
     */
    public function phone_masks_plain_digits(): void
    {
        $result = Mask::phone('5551234567');

        $this->assertSame('******4567', $result);
    }

    /**
     * @test
     */
    public function phone_handles_short_number(): void
    {
        // 4 or fewer digits — all visible
        $this->assertSame('1234', Mask::phone('1234'));
        $this->assertSame('123', Mask::phone('123'));
    }

    /**
     * @test
     */
    public function phone_handles_international_format(): void
    {
        $result = Mask::phone('+44 7700 900123');

        // Last 4 digits visible
        $this->assertStringEndsWith('0123', $result);
        // Plus sign and spaces preserved
        $this->assertStringStartsWith('+', $result);
        $this->assertStringContainsString(' ', $result);
        $this->assertStringContainsString('**', $result);
    }

    /**
     * @test
     */
    public function phone_output_triggers_isObscuredPhone_detection(): void
    {
        // The RestController uses /\*{2,}/ to detect masked phones.
        // Verify Mask output always contains consecutive asterisks for
        // numbers with more than 4 digits.
        $masked = Mask::phone('5551234567');

        $this->assertMatchesRegularExpression('/\*{2,}/', $masked);
    }

    /**
     * @test
     */
    public function phone_preserves_formatting_characters(): void
    {
        $result = Mask::phone('(555) 123-4567');

        // Parentheses, space, and dash should all survive
        $this->assertSame('(***) ***-4567', $result);
    }
}

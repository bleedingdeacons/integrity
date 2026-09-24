<?php

declare(strict_types=1);

namespace Integrity\Tests\Unit\Utils;

use Integrity\Utils\Mask;

/*
 * Unit tests for Mask utility
 */

// ─── Email masking ────────────────────────────────────
describe('email', function () {
    it('masks a standard address', function () {
        $result = Mask::email('john@example.com');

        // First char of local + underscores, first char of domain + underscores, TLD preserved
        expect($result)
            ->toStartWith('j')
            ->toContain('@')
            ->toEndWith('.com')
            ->toContain('__'); // sentinel for isObscuredEmail()
    });

    it('returns empty for empty input', function () {
        expect(Mask::email(''))->toBe('');
    });

    it('returns the input unchanged without an at sign', function () {
        expect(Mask::email('notanemail'))->toBe('notanemail');
    });

    it('handles a short local part', function () {
        $result = Mask::email('a@b.co');

        expect($result)
            ->toStartWith('a')
            ->toEndWith('.co')
            ->toContain('__'); // minimum 2 underscores
    });

    it('handles a subdomain', function () {
        $result = Mask::email('user@mail.example.co.uk');

        expect($result)
            ->toStartWith('u')
            ->toEndWith('.uk')
            ->toContain('@');
    });

    it('preserves structure', function () {
        $result = Mask::email('longuser@longdomain.org');

        // Must contain exactly one @
        expect(substr_count($result, '@'))->toBe(1)
            // Must end with the original TLD
            ->and($result)->toEndWith('.org');
    });

    it('produces output matching the obscured sentinel shape', function () {
        // ControllerTrait::isObscuredEmail uses an anchored regex that matches
        // the exact sentinel shape: <char><2+ underscores>@<char><2+ underscores>.<tld>
        // Verify Mask output conforms.
        $masked = Mask::email('test@example.com');

        expect($masked)->toMatch('/^.[_]{2,}@.[_]{2,}\.[^._@]+$/');
    });
});

// ─── Phone masking ────────────────────────────────────
describe('phone', function () {
    it('masks a standard number', function () {
        $result = Mask::phone('(555) 867-5309');

        // Last 4 digits visible, preceding digits replaced with *
        expect($result)
            ->toEndWith('5309')
            ->toContain('*')
            // Non-digit formatting preserved
            ->toContain('(')
            ->toContain(')')
            ->toContain('-');
    });

    it('returns empty for empty input', function () {
        expect(Mask::phone(''))->toBe('');
    });

    it('masks plain digits', function () {
        expect(Mask::phone('5551234567'))->toBe('******4567');
    });

    it('handles a short number', function () {
        // 4 or fewer digits — all visible
        expect(Mask::phone('1234'))->toBe('1234')
            ->and(Mask::phone('123'))->toBe('123');
    });

    it('handles international format', function () {
        $result = Mask::phone('+44 7700 900123');

        // Last 4 digits visible
        expect($result)
            ->toEndWith('0123')
            // Plus sign and spaces preserved
            ->toStartWith('+')
            ->toContain(' ')
            ->toContain('**');
    });

    it('produces output matching the obscured sentinel shape', function () {
        // ControllerTrait::isObscuredPhone uses an anchored regex that matches
        // the exact sentinel shape: no digits before a final 1-4 digit suffix,
        // with at least one asterisk present. Verify Mask output conforms.
        $masked = Mask::phone('5551234567');

        expect($masked)->toMatch('/^[^\d]*\*+[^\d*]*\d{0,4}$/');
    });

    it('preserves formatting characters', function () {
        $result = Mask::phone('(555) 123-4567');

        // Parentheses, space, and dash should all survive
        expect($result)->toBe('(***) ***-4567');
    });
});

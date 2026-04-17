<?php

declare(strict_types=1);

namespace Integrity\Utils;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * PII Masking Utility
 *
 * Provides static helpers for masking personal data in REST API responses.
 *
 * Masking conventions (must stay in sync with the round-trip detection in
 * ControllerTrait::isObscuredEmail / isObscuredPhone):
 *
 *   Email:  underscores replace hidden characters   → j___@e______.com
 *   Phone:  asterisks replace hidden digits, last 4 → (***) ***-5309
 *
 * The email sentinel shape is exactly:
 *     <char><2+ underscores>@<char><2+ underscores>.<tld>
 *
 * The phone sentinel shape is exactly:
 *     <non-digit or asterisk>*<asterisks><non-digit non-asterisk>*<up to 4 digits>
 *
 * Both detection regexes are anchored to the full value so that legitimate
 * content containing the sentinel characters (e.g. RFC 5322 emails with
 * consecutive underscores in the local part, phone extensions like "*70")
 * is not mistakenly treated as masked.
 *
 * These conventions differ from Scrutiny's PersonalDataObscurer (which uses
 * fixed-width bullet characters for admin UI display) because the REST API
 * values may be submitted back in update requests, and the round-trip
 * detection here requires ASCII-safe sentinel patterns. Scrutiny does not
 * round-trip its obscured values so it can afford a fixed-width form that
 * reveals no length information.
 */
class Mask
{
    /**
     * Mask an email address for API output.
     *
     * Preserves the first character of the local part, the first character
     * of the domain name, and the full TLD. All other characters are
     * replaced with underscores.
     *
     * Examples:
     *   "john@example.com"  → "j___@e______.com"
     *   ""                  → ""
     *   "x"                 → "x" (no @ sign, returned as-is)
     *
     * @param string $email The plain email address
     * @return string The masked email, or empty string if input is empty
     */
    public static function email(string $email): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        $maskedLocal = mb_substr($local, 0, 1)
            . str_repeat('_', max(mb_strlen($local) - 1, 2));

        $domainParts = explode('.', $domain);
        $tld = array_pop($domainParts);
        $domainName = implode('.', $domainParts);

        $maskedDomain = mb_substr($domainName, 0, 1)
            . str_repeat('_', max(mb_strlen($domainName) - 1, 2))
            . '.' . $tld;

        return $maskedLocal . '@' . $maskedDomain;
    }

    /**
     * Mask a phone number for API output.
     *
     * Keeps the last 4 digits visible and replaces all preceding digits
     * with asterisks. Non-digit characters (dashes, spaces, parentheses)
     * are preserved in their original positions.
     *
     * Examples:
     *   "(555) 867-5309"  → "(***) ***-5309"
     *   "5551234"         → "***1234"
     *   ""                → ""
     *
     * @param string $phone The plain phone number
     * @return string The masked phone number
     */
    public static function phone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }

        // Count total digits to determine how many to mask
        $digits = preg_replace('/[^0-9]/', '', $phone);
        $totalDigits = strlen($digits);
        $visibleCount = min(4, $totalDigits);
        $hideCount = $totalDigits - $visibleCount;

        // Walk through the string, replacing digits from the left
        $digitsSeen = 0;
        $result = '';

        for ($i = 0, $len = strlen($phone); $i < $len; $i++) {
            if (ctype_digit($phone[$i])) {
                $digitsSeen++;
                $result .= ($digitsSeen <= $hideCount) ? '*' : $phone[$i];
            } else {
                $result .= $phone[$i];
            }
        }

        return $result;
    }
}
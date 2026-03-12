<?php

declare(strict_types=1);

namespace Integrity\Utils;

/**
 * Mask Utility
 *
 * Static helpers for masking personal data in API responses.
 *
 * @deprecated Use Scrutiny\Privacy\DataObscurer instead.
 *             This class duplicates masking logic that already exists in the
 *             Scrutiny plugin. It will be removed in a future release.
 *             To migrate: inject DataObscurerInterface from the Unity container
 *             and call $obscurer->obscureEmail() / $obscurer->obscurePhone().
 */
class Mask
{
    /**
     * Mask an email address for API output.
     *
     * Preserves the first character of the local part and domain,
     * replaces the rest with underscores. TLD is kept intact.
     *
     * Example: "john@example.com" → "j___@e______.com"
     *
     * @deprecated Use Scrutiny\Privacy\DataObscurer::obscureEmail() instead.
     *
     * @param string $email The email address to mask
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
     * Replaces all but the last 4 digits with asterisks.
     *
     * Example: "555-123-4567" → "***-***-4567"
     *
     * @deprecated Use Scrutiny\Privacy\DataObscurer::obscurePhone() instead.
     *
     * @param string $phone The phone number to mask
     * @return string The masked phone number, or empty string if input is empty
     */
    public static function phone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);
        $visibleSuffix = substr($digits, -4);
        $hiddenLength = max(strlen($digits) - 4, 0);

        return str_repeat('*', $hiddenLength) . $visibleSuffix;
    }
}
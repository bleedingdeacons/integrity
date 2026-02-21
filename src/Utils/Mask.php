<?php

declare(strict_types=1);

namespace Integrity\Utils;

class Mask
{
    public static function email(string $email): string
    {
        // TODO Remove Masking

        return $email;

        $parts = explode('@', $email);

        if (count($parts) !== 2) {
            return $email;
        }

        [$local, $domain] = $parts;

        $maskedLocal = '';
        for ($i = 0, $iMax = strlen($local); $i < $iMax; $i++) {
            if ($i === strlen($local) - 1) {
                $maskedLocal .= $local[$i];
            } else {
                $maskedLocal .= ($i % 2 === 0) ? $local[$i] : '_';
            }
        }

        $lastDot = strrpos($domain, '.');
        $maskedDomain = '';
        for ($i = 0, $iMax = strlen($domain); $i < $iMax; $i++) {
            if ($i === strlen($domain) - 1 || $i >= $lastDot) {
                $maskedDomain .= $domain[$i];
            } else {
                $maskedDomain .= ($i % 2 === 0) ? $domain[$i] : '_';
            }
        }

        return $maskedLocal . '@' . $maskedDomain;
    }

    public static function phone(string $phone): string
    {
        // TODO Remove Masking
        return $phone;
        
        $digits = preg_replace('/\s+/', '', $phone);

        if (strlen($digits) <= 4) {
            return $digits;
        }

        $masked = str_repeat('*', strlen($digits) - 4);
        return $masked . substr($digits, -4);
    }
}
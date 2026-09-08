<?php

declare(strict_types=1);

namespace Integrity\Auth;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pre-authentication throttle.
 *
 * {@see RateLimiter} is keyed on an API key id, so it can only act on a
 * request that has already authenticated. That left the expensive half of
 * authentication — ApiKeyManager::validateKey(), which runs a deliberately
 * fixed eight Argon2id verifies at memory_cost 65536 — reachable by anyone,
 * unthrottled, with no credential at all.
 *
 * This throttle covers the gap: it counts *failed* authentication attempts
 * per client IP in a fixed window and refuses further ones once the budget
 * is spent, before validateKey() is called. A caller holding a working key
 * never accumulates a count and pays only one transient read.
 *
 * Deliberately transient-backed rather than a fourth table. The plugin has
 * no version-gated migration mechanism — its schema is created once in the
 * activation hook — so a new table would not appear on already-activated
 * installs, which is exactly where this needs to work. Transients are also
 * the cheaper store: with a persistent object cache configured the whole
 * check stays in memory and never touches the database.
 *
 * The counter keys on AuditLogger::getClientIp(), which only honours
 * proxy headers from configured trusted proxies, so the bucket cannot be
 * scattered by forging X-Forwarded-For.
 */
class PreAuthThrottle
{
    /**
     * Length of the counting window, in seconds.
     *
     * Shorter than RateLimiter's hour: this budget exists to make a flood
     * expensive, not to meter legitimate use, and a caller locked out by a
     * misconfigured key should recover without waiting an hour.
     */
    private const WINDOW_SECONDS = 900;

    /**
     * Failed attempts permitted per IP per window.
     *
     * Generous against an integration that deploys a stale key and retries,
     * tight enough that the Argon2id work an unauthenticated caller can
     * command is bounded at MAX_FAILURES per window rather than unbounded.
     */
    private const MAX_FAILURES = 20;

    private const TRANSIENT_PREFIX = 'integrity_preauth_';

    /**
     * Whether this client has already spent its failure budget.
     *
     * Read-only: a request that goes on to authenticate successfully costs
     * nothing but this lookup.
     *
     * @param string $clientIp The client IP, from AuditLogger::getClientIp()
     * @return bool Whether the request should be refused before validation
     */
    public function isBlocked(string $clientIp): bool
    {
        return $this->count($clientIp) >= self::MAX_FAILURES;
    }

    /**
     * Record a failed authentication attempt against this client.
     *
     * Called only on the failure paths, so the budget measures exactly what
     * it is meant to bound.
     *
     * @param string $clientIp The client IP, from AuditLogger::getClientIp()
     */
    public function penalise(string $clientIp): void
    {
        $key = $this->transientKey($clientIp);

        // The key embeds the window index, so it is only ever written
        // within the window it counts and expires on its own afterwards.
        // A read-then-write race here can lose a concurrent increment; that
        // costs at most a few extra attempts against a budget of twenty,
        // which does not change what this defends against.
        set_transient($key, $this->count($clientIp) + 1, self::WINDOW_SECONDS + 60);
    }

    /**
     * Seconds until the current window closes, for a Retry-After header.
     */
    public function retryAfter(): int
    {
        return self::WINDOW_SECONDS - (time() % self::WINDOW_SECONDS);
    }

    /**
     * Failures recorded for this client in the current window.
     */
    private function count(string $clientIp): int
    {
        $value = get_transient($this->transientKey($clientIp));

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Transient key for a client's current window.
     *
     * The IP is hashed rather than interpolated: it keeps the key within the
     * option-name length limit for IPv6, avoids putting a raw address in
     * wp_options where the audit log is the intended record of it, and
     * guarantees the name is well-formed whatever the address looks like.
     */
    private function transientKey(string $clientIp): string
    {
        $window = (int) floor(time() / self::WINDOW_SECONDS);

        return self::TRANSIENT_PREFIX . md5($clientIp) . '_' . $window;
    }
}

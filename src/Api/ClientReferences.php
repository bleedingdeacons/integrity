<?php

declare(strict_types=1);

namespace Integrity\Api;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes POST /members/create safe to repeat.
 *
 * A client that creates a member can name the create with a reference of its
 * own (a UUID it generated when the member was first entered). The reference
 * is stored against the new member as post meta, so a repeat of the same
 * create — a retried batch, or a second attempt after a response that never
 * arrived — finds the member made the first time instead of making another.
 *
 * Two copies of one create can also arrive together: a client times out and
 * retries while the first request is still running. Both would miss the meta
 * lookup and both would create. {@see lock()} closes that window with a
 * MySQL named lock: the second request waits for the first to finish, then
 * finds its member. Named locks belong to the database connection, so one
 * left behind by a request that died is released when its connection closes;
 * nothing is left to expire.
 *
 * The lookup goes straight to postmeta rather than through Unity's member
 * repository: the repository's query arguments are its implementation's to
 * interpret, and this needs one exact-match read whatever that is.
 */
class ClientReferences
{
    /** Post meta key the reference is stored under. Leading underscore keeps it out of the custom-fields UI. */
    public const META_KEY = '_integrity_client_reference';

    /** How long a create waits for another copy of itself to finish, in seconds. */
    public const LOCK_TIMEOUT = 10;

    /** UUID in its canonical 8-4-4-4-12 form, any case. */
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** MySQL caps named-lock names at 64 characters; prefix + 36-char UUID is 60. */
    private const LOCK_PREFIX = 'integrity_mc_';

    /**
     * Whether a value is acceptable as a client reference.
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * The id of the member already created under this reference, or null.
     */
    public function findMemberId(string $reference): ?int
    {
        $wpdb = $this->wpdb();

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id DESC LIMIT 1",
            self::META_KEY,
            $reference
        ));

        return $id === null ? null : (int) $id;
    }

    /**
     * Stores the reference against a newly created member.
     *
     * @return bool False when the meta could not be written; the member
     *              exists regardless, it just cannot be found by reference.
     */
    public function remember(int $memberId, string $reference): bool
    {
        return (bool) update_post_meta($memberId, self::META_KEY, $reference);
    }

    /**
     * Takes the named lock for this reference, waiting up to
     * {@see LOCK_TIMEOUT} seconds for another holder to let go.
     *
     * @return bool True when the lock is held and must be released.
     */
    public function lock(string $reference): bool
    {
        $wpdb = $this->wpdb();

        $acquired = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            self::lockName($reference),
            self::LOCK_TIMEOUT
        ));

        return (string) $acquired === '1';
    }

    /**
     * Releases the lock taken by {@see lock()}.
     */
    public function release(string $reference): void
    {
        $wpdb = $this->wpdb();

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lockName($reference)));
    }

    private static function lockName(string $reference): string
    {
        return self::LOCK_PREFIX . strtolower($reference);
    }

    /**
     * @return \wpdb
     */
    private function wpdb()
    {
        global $wpdb;

        return $wpdb;
    }
}

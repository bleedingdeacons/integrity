<?php

declare(strict_types=1);

namespace Integrity\Api\Controllers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\AuditLogger;
use Integrity\Utils\Mask;
use Unity\Contacts\Interfaces\Contact;
use Unity\Locations\Interfaces\Location;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Shared utilities for REST API controllers.
 *
 * Provides audit logging helpers, error response builders, and
 * common transform methods used across multiple resource controllers.
 */
trait ControllerTrait
{
    private AuditLogger $auditLogger;

    /**
     * Extract timing/key context stored by the permission check.
     *
     * @return array{start_time: float, key_data: array}
     */
    protected function extractRequestContext(WP_REST_Request $request): array
    {
        return [
            'start_time' => (float) $request->get_param('_integrity_start_time'),
            'key_data'   => $request->get_param('_integrity_key_data'),
        ];
    }

    /**
     * Log a request via the audit logger.
     */
    protected function logRequest(
        int $apiKeyId,
        WP_REST_Request $request,
        ?array $params,
        int $statusCode,
        float $startTime
    ): void {
        $this->auditLogger->log(
            $apiKeyId,
            $request->get_route(),
            $request->get_method(),
            $params,
            $statusCode,
            microtime(true) - $startTime
        );
    }

    /**
     * Build a standard success response.
     */
    protected function successResponse(array $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    /**
     * Build a standard paginated success response.
     */
    protected function paginatedResponse(array $data, int $total, int $page, int $perPage, int $status = 200): WP_REST_Response
    {
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
            ],
        ], $status);
    }

    /**
     * Build a standard error response.
     */
    protected function errorResponse(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /**
     * Build a 404 not-found response.
     */
    protected function notFoundResponse(string $entity): WP_REST_Response
    {
        return $this->errorResponse('not_found', "{$entity} not found", 404);
    }

    /**
     * Build a 500 internal-error response.
     */
    protected function internalErrorResponse(): WP_REST_Response
    {
        return $this->errorResponse('internal_error', 'An internal error occurred', 500);
    }

    /**
     * Transform a Contact object to API response format.
     *
     * @param Contact|array $contact
     */
    protected function transformContact($contact): array
    {
        if ($contact instanceof Contact) {
            return [
                'name' => $contact->getName(),
                'email' => Mask::email($contact->getEmail()),
                'phone' => Mask::phone($contact->getPhone()),
                'updated' => $this->formatUpdatedTimestamp($contact->getUpdated()),
            ];
        }

        // Handle legacy array format for backwards compatibility
        if (is_array($contact)) {
            return [
                'name' => $contact['name'] ?? '',
                'email' => Mask::email($contact['email'] ?? ''),
                'phone' => Mask::phone($contact['phone'] ?? ''),
                'updated' => $this->formatUpdatedTimestamp($contact['updated'] ?? ''),
            ];
        }

        return [
            'name' => '',
            'email' => '',
            'phone' => '',
            'updated' => '',
        ];
    }

    /**
     * Transform a Location object to API response format.
     */
    protected function transformLocation(Location $location): array
    {
        return [
            'id' => $location->getId(),
            'name' => $location->getName(),
            'address' => $location->getAddress(),
            'city' => $location->getCity(),
            'state' => $location->getState(),
            'postal_code' => $location->getPostalCode(),
            'country' => $location->getCountry(),
            'region' => $location->getRegion(),
            'notes' => $location->getNotes(),
            'link' => $location->getLink(),
            'latitude' => $location->getLatitude(),
            'longitude' => $location->getLongitude(),
            'timezone' => $location->getTimezone(),
            'formatted_address' => $location->getFormattedAddress(),
            'updated' => $this->formatUpdatedTimestamp($location->getUpdated()),
        ];
    }

    /**
     * Format a WordPress datetime string to ISO 8601 UTC with milliseconds.
     *
     * Converts values like "2025-03-09 14:30:00" (WordPress post_modified_gmt)
     * to "2025-03-09T14:30:00.000Z".
     *
     * Returns an empty string when the input is empty or unparseable.
     */
    protected function formatUpdatedTimestamp(string $datetime): string
    {
        if (empty($datetime)) {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s') . '.000Z';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Detect whether an email value is an obscured sentinel produced by Mask::email().
     *
     * Matches the exact sentinel shape: one character, two or more underscores,
     * an '@' sign, one character, two or more underscores, a dot, and a TLD.
     * For example: "j___@e______.com" or "j__@e__.co".
     *
     * A previous implementation used /__+/ which matched any two consecutive
     * underscores. This incorrectly flagged valid RFC 5322 addresses containing
     * double underscores in the local part (e.g. "user__name@example.com",
     * "__init__@python.org") as masked, causing update mutations to be silently
     * discarded. The anchored pattern here requires that the *entire* value
     * conform to the mask shape, which no RFC-valid email can — DNS labels
     * (the portion before the TLD) may not contain underscores, so a real
     * address whose domain name is "<char>__..." is already rejected by
     * is_email() before reaching this method.
     */
    protected function isObscuredEmail(string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        return (bool) preg_match('/^.[_]{2,}@.[_]{2,}\.[^._@]+$/', $value);
    }

    /**
     * Detect whether a phone number value is an obscured sentinel produced by Mask::phone().
     *
     * Matches the exact sentinel shape: optional formatting characters and
     * asterisks (with no embedded digits), followed by up to four trailing
     * digits. For example: "***1234", "(***) ***-5309", "+** **** 0123".
     *
     * A previous implementation used /\*{2,}/ which matched any two consecutive
     * asterisks anywhere in the value. That was narrower than the email case
     * in practice (asterisks are rare in real phone strings), but still a
     * soundness bug of the same class. The anchored pattern here requires the
     * entire value to conform to the mask shape: no digits may appear before
     * the final 1-4 digit suffix, and at least one asterisk must be present.
     */
    protected function isObscuredPhone(string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        return (bool) preg_match('/^[^\d]*\*+[^\d*]*\d{0,4}$/', $value);
    }
}
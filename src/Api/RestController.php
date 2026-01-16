<?php

declare(strict_types=1);

namespace Integrity\Api;

use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\RateLimiter;
use Integrity\Auth\AuditLogger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API Controller
 *
 * Handles all REST API routes with authentication, rate limiting, and audit logging.
 */
class RestController
{
    private const NAMESPACE = 'integrity/v1';

    /**
     * Register REST API routes
     */
    public static function register(): void
    {
        // Groups endpoints
        register_rest_route(self::NAMESPACE, '/groups', [
            'methods' => 'GET',
            'callback' => [self::class, 'getGroups'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => self::getGroupsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/groups/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'getGroup'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
                'expand' => [
                    'default' => '',
                    'validate_callback' => function ($param) {
                        $allowed = ['meetings'];
                        $requested = array_filter(array_map('trim', explode(',', $param)));
                        return empty(array_diff($requested, $allowed));
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Meetings endpoints
        register_rest_route(self::NAMESPACE, '/meetings', [
            'methods' => 'GET',
            'callback' => [self::class, 'getMeetings'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => self::getMeetingsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/meetings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'getMeeting'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        // Health check endpoint (no auth required)
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [self::class, 'healthCheck'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get arguments for groups endpoint
     */
    private static function getGroupsArgs(): array
    {
        return [
            'per_page' => [
                'default' => 100,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0 && $param <= 500;
                },
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'default' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'search' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'district_id' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param > 0);
                },
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : absint($param);
                },
            ],
            'expand' => [
                'default' => '',
                'validate_callback' => function ($param) {
                    $allowed = ['meetings'];
                    $requested = array_filter(array_map('trim', explode(',', $param)));
                    return empty(array_diff($requested, $allowed));
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get arguments for meetings endpoint
     */
    private static function getMeetingsArgs(): array
    {
        return [
            'per_page' => [
                'default' => 100,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0 && $param <= 500;
                },
                'sanitize_callback' => 'absint',
            ],
            'page' => [
                'default' => 1,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'day' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param >= 0 && $param <= 6);
                },
            ],
            'online' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || in_array($param, ['true', 'false', '1', '0', true, false], true);
                },
            ],
            'group_id' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param > 0);
                },
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : absint($param);
                },
            ],
            'search' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Check permission and authenticate request
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public static function checkPermission(WP_REST_Request $request)
    {
        $startTime = microtime(true);

        // Require HTTPS in production
        if (get_option('integrity_require_https', true) && !is_ssl() && !defined('WP_DEBUG') || !WP_DEBUG) {
            self::logFailedRequest($request, 403, $startTime);
            return new WP_Error(
                'https_required',
                'HTTPS is required for API access',
                ['status' => 403]
            );
        }

        // Get API key from header
        $apiKey = self::extractApiKey($request);

        if (!$apiKey) {
            self::logFailedRequest($request, 401, $startTime);
            return new WP_Error(
                'missing_api_key',
                'API key is required. Provide it in the Authorization header as: Bearer <api_key>',
                ['status' => 401]
            );
        }

        // Validate API key
        $clientIp = AuditLogger::getClientIp();
        $keyData = ApiKeyManager::validateKey($apiKey, $clientIp);

        if (!$keyData) {
            self::logFailedRequest($request, 401, $startTime);
            return new WP_Error(
                'invalid_api_key',
                'Invalid or expired API key',
                ['status' => 401]
            );
        }

        // Check rate limits (cast to int as database returns strings)
        $apiKeyId = (int) $keyData['id'];
        $rateLimit = (int) $keyData['rate_limit'];
        $rateLimitResult = RateLimiter::checkLimit($apiKeyId, $rateLimit);

        if (!$rateLimitResult['allowed']) {
            self::logFailedRequest($request, 429, $startTime, $apiKeyId);

            $response = new WP_Error(
                'rate_limit_exceeded',
                'Rate limit exceeded. Try again later.',
                ['status' => 429]
            );

            // Add rate limit headers
            add_filter('rest_post_dispatch', function ($result) use ($rateLimitResult, $rateLimit) {
                if ($result instanceof WP_REST_Response) {
                    foreach (RateLimiter::getHeaders($rateLimit, $rateLimitResult['remaining'], $rateLimitResult['reset']) as $header => $value) {
                        $result->header($header, $value);
                    }
                }
                return $result;
            });

            return $response;
        }

        // Increment rate limit counter
        RateLimiter::incrementCount($apiKeyId);

        // Check endpoint-specific permissions
        $endpoint = $request->get_route();
        $requiredPermission = self::getRequiredPermission($endpoint);

        if ($requiredPermission && !in_array($requiredPermission, $keyData['permissions'], true) && !in_array('*', $keyData['permissions'], true)) {
            self::logFailedRequest($request, 403, $startTime, $apiKeyId);
            return new WP_Error(
                'insufficient_permissions',
                "This API key does not have permission to access: {$requiredPermission}",
                ['status' => 403]
            );
        }

        // Store key data for use in callbacks (cast id to int for type safety)
        $keyData['api_key_id'] = $apiKeyId;
        $request->set_param('_integrity_key_data', $keyData);
        $request->set_param('_integrity_start_time', $startTime);
        $request->set_param('_integrity_rate_limit', $rateLimitResult);

        // Add rate limit headers to successful responses
        add_filter('rest_post_dispatch', function ($result) use ($rateLimitResult, $rateLimit) {
            if ($result instanceof WP_REST_Response) {
                foreach (RateLimiter::getHeaders($rateLimit, $rateLimitResult['remaining'], $rateLimitResult['reset']) as $header => $value) {
                    $result->header($header, $value);
                }
            }
            return $result;
        });

        return true;
    }

    /**
     * Extract API key from request
     */
    private static function extractApiKey(WP_REST_Request $request): ?string
    {
        // Try Authorization header first (preferred)
        $authHeader = $request->get_header('Authorization');

        if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        // Fallback to X-API-Key header
        $apiKeyHeader = $request->get_header('X-API-Key');
        if ($apiKeyHeader) {
            return trim($apiKeyHeader);
        }

        return null;
    }

    /**
     * Get required permission for an endpoint
     */
    private static function getRequiredPermission(string $endpoint): ?string
    {
        if (strpos($endpoint, '/groups') !== false) {
            return 'groups:read';
        }

        if (strpos($endpoint, '/meetings') !== false) {
            return 'meetings:read';
        }

        return null;
    }

    /**
     * Log a failed request
     */
    private static function logFailedRequest(WP_REST_Request $request, int $code, float $startTime, ?int $keyId = null): void
    {
        AuditLogger::log(
            $keyId,
            $request->get_route(),
            $request->get_method(),
            $request->get_params(),
            $code,
            microtime(true) - $startTime
        );
    }

    /**
     * Get all groups
     */
    public static function getGroups(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            // Get Unity container
            $container = \Unity\Plugin::getContainer();
            $groupRepo = $container->get(\Unity\Groups\Interfaces\GroupRepositoryInterface::class);

            // Build query args
            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            // Get groups
            $groups = $groupRepo->findAll($args);

            // Filter by district if specified
            $districtId = $request->get_param('district_id');
            if ($districtId !== null) {
                $groups = array_filter($groups, function ($group) use ($districtId) {
                    return $group->getDistrictId() === $districtId;
                });
            }

            // Parse expand parameter
            $expandParam = $request->get_param('expand');
            $expand = !empty($expandParam) ? array_filter(array_map('trim', explode(',', $expandParam))) : [];

            // Transform to API response format
            $data = array_map(function($group) use ($expand) {
                return self::transformGroup($group, $expand);
            }, $groups);

            // Log successful request
            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['per_page' => $args['posts_per_page'], 'page' => $args['paged']],
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => array_values($data),
                'meta' => [
                    'total' => count($data),
                    'page' => $request->get_param('page'),
                    'per_page' => $request->get_param('per_page'),
                ],
            ], 200);

        } catch (\Exception $e) {
            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                null,
                500,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An internal error occurred',
                ],
            ], 500);
        }
    }

    /**
     * Get a single group
     */
    public static function getGroup(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = \Unity\Plugin::getContainer();
            $groupRepo = $container->get(\Unity\Groups\Interfaces\GroupRepositoryInterface::class);

            $group = $groupRepo->findById($id);

            if (!$group || !$group->isValid()) {
                AuditLogger::log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $id],
                    404,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'not_found',
                        'message' => 'Group not found',
                    ],
                ], 404);
            }

            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                200,
                microtime(true) - $startTime
            );

            // Parse expand parameter
            $expandParam = $request->get_param('expand');
            $expand = !empty($expandParam) ? array_filter(array_map('trim', explode(',', $expandParam))) : [];

            return new WP_REST_Response([
                'success' => true,
                'data' => self::transformGroup($group, $expand),
            ], 200);

        } catch (\Exception $e) {
            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                500,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An internal error occurred',
                ],
            ], 500);
        }
    }

    /**
     * Get all meetings
     */
    public static function getMeetings(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = \Unity\Plugin::getContainer();
            $meetingRepo = $container->get(\Unity\Meetings\Interfaces\MeetingRepositoryInterface::class);

            // Build query args
            $args = [];

            $day = $request->get_param('day');
            if ($day !== null) {
                $args['day'] = (int) $day;
            }

            $groupId = $request->get_param('group_id');
            if ($groupId !== null) {
                $args['group_id'] = (int) $groupId;
            }

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            // Get meetings
            $meetings = $meetingRepo->findAll($args);

            // Filter by online status if specified
            $online = $request->get_param('online');
            if ($online !== null) {
                $onlineFilter = in_array($online, ['true', '1', true], true);
                $meetings = array_filter($meetings, function ($meeting) use ($onlineFilter) {
                    return $meeting->isOnline() === $onlineFilter;
                });
            }

            // Apply pagination
            $perPage = $request->get_param('per_page');
            $page = $request->get_param('page');
            $total = count($meetings);
            $offset = ($page - 1) * $perPage;
            $meetings = array_slice($meetings, $offset, $perPage);

            // Transform to API response format
            $data = array_map([self::class, 'transformMeeting'], $meetings);

            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['per_page' => $perPage, 'page' => $page],
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => array_values($data),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => (int) ceil($total / $perPage),
                ],
            ], 200);

        } catch (\Exception $e) {
            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                null,
                500,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An internal error occurred',
                ],
            ], 500);
        }
    }

    /**
     * Get a single meeting
     */
    public static function getMeeting(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = \Unity\Plugin::getContainer();
            $meetingRepo = $container->get(\Unity\Meetings\Interfaces\MeetingRepositoryInterface::class);

            $meeting = $meetingRepo->find($id);

            if (!$meeting) {
                AuditLogger::log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $id],
                    404,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'not_found',
                        'message' => 'Meeting not found',
                    ],
                ], 404);
            }

            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => self::transformMeeting($meeting),
            ], 200);

        } catch (\Exception $e) {
            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                500,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => false,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'An internal error occurred',
                ],
            ], 500);
        }
    }

    /**
     * Health check endpoint
     */
    public static function healthCheck(WP_REST_Request $request): WP_REST_Response
    {
        $unityAvailable = class_exists('Unity\\Plugin');

        return new WP_REST_Response([
            'status' => $unityAvailable ? 'healthy' : 'degraded',
            'timestamp' => gmdate('c'),
            'version' => INTEGRITY_VERSION,
            'unity_available' => $unityAvailable,
        ], $unityAvailable ? 200 : 503);
    }

    /**
     * Transform a Group object to API response format
     *
     * @param \Unity\Groups\Interfaces\GroupInterface $group
     * @param array $expand Array of fields to expand (e.g., ['meetings'])
     * @return array
     */
    private static function transformGroup(\Unity\Groups\Interfaces\GroupInterface $group, array $expand = []): array
    {
        $contacts = $group->getContacts();
        $meetings = $group->getMeetings();

        // Check if meetings should be expanded with full data
        $expandMeetings = in_array('meetings', $expand, true);

        if ($expandMeetings) {
            // Return full meeting data
            $meetingData = array_map([self::class, 'transformMeeting'], $meetings);
        } else {
            // Return just meeting IDs for backwards compatibility
            $meetingData = array_map(function($meeting) {
                return $meeting->getId();
            }, $meetings);
        }

        return [
            'id' => $group->getId(),
            'title' => $group->getTitle(),
            'email' => $group->getEmail(),
            'phone' => $group->getPhone(),
            'website' => $group->getWebsite(),
            'link' => $group->getLink(),
            'notes' => $group->getGroupNotes(),
            'district_id' => $group->getDistrictId(),
            'last_contact' => $group->getLastContact(),
            $expandMeetings ? 'meetings' : 'meeting_ids' => $meetingData,
            'contacts' => !empty($contacts) ? array_map([self::class, 'transformContact'], $contacts) : [],
            'contribution_options' => [
                'venmo' => $group->getVenmo(),
                'paypal' => $group->getPaypal(),
                'square' => $group->getSquare(),
                'has_options' => $group->hasContributionOptions(),
            ],
        ];
    }

    /**
     * Transform a Meeting object to API response format
     */
    private static function transformMeeting(\Unity\Meetings\Interfaces\MeetingInterface $meeting): array
    {
        $contacts = $meeting->getContacts();
        $location = $meeting->getLocation();

        return [
            'id' => $meeting->getId(),
            'name' => $meeting->getName(),
            'slug' => $meeting->getSlug(),
            'location' => $location !== null ? self::transformLocation($location) : null,
            'url' => $meeting->getUrl(),
            'day' => $meeting->getDay(),
            'day_of_week' => $meeting->getDayOfWeek(),
            'time' => $meeting->getTime(),
            'end_time' => $meeting->getEndTime(),
            'types' => $meeting->getTypes(),
            'state' => $meeting->getState(),
            'is_online' => $meeting->isOnline(),
            'online_link' => $meeting->getOnlineLink(),
            'online_notes' => $meeting->getOnlineNotes(),
            'contacts' => !empty($contacts) ? array_map([self::class, 'transformContact'], $contacts) : [],
            'meta' => $meeting->getMeta(),
        ];
    }

    /**
     * Transform a Location object to API response format
     *
     * @param \Unity\Locations\Interfaces\LocationInterface $location
     * @return array
     */
    private static function transformLocation(\Unity\Locations\Interfaces\LocationInterface $location): array
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
        ];
    }

    /**
     * Transform a Contact object to API response format
     *
     * @param \Unity\Contact\Interfaces\ContactInterface|array $contact
     * @return array
     */
    private static function transformContact($contact): array
    {
        if ($contact instanceof \Unity\Contact\Interfaces\ContactInterface) {
            return [
                'name' => $contact->getName(),
                'email' => $contact->getEmail(),
                'phone' => $contact->getPhone(),
            ];
        }

        // Handle legacy array format for backwards compatibility
        if (is_array($contact)) {
            return [
                'name' => $contact['name'] ?? '',
                'email' => $contact['email'] ?? '',
                'phone' => $contact['phone'] ?? '',
            ];
        }

        return [
            'name' => '',
            'email' => '',
            'phone' => '',
        ];
    }
}
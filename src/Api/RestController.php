<?php

declare(strict_types=1);

namespace Integrity\Api;

use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\RateLimiter;
use Integrity\Auth\AuditLogger;
use Integrity\Utils\Mask;
use Unity\Plugin;
use Unity\Contact\Interfaces\ContactInterface;
use Unity\Groups\Interfaces\GroupInterface;
use Unity\Groups\Interfaces\GroupRepositoryInterface;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingInterface;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepositoryInterface;
use Unity\Locations\Interfaces\LocationInterface;
use Unity\Meetings\Interfaces\MeetingInterface;
use Unity\Meetings\Interfaces\MeetingRepositoryInterface;
use Unity\Members\Interfaces\MemberInterface;
use Unity\Members\Interfaces\MemberRepositoryInterface;
use Unity\Positions\Interfaces\PositionInterface;
use Unity\Positions\Interfaces\PositionRepositoryInterface;
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

        // Positions endpoints
        register_rest_route(self::NAMESPACE, '/positions', [
            'methods' => 'GET',
            'callback' => [self::class, 'getPositions'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => self::getPositionsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/positions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'getPosition'],
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

        // Members endpoints
        register_rest_route(self::NAMESPACE, '/members', [
            'methods' => 'GET',
            'callback' => [self::class, 'getMembers'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => self::getMembersArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/members/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'getMember'],
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

        // Intergroup Meetings endpoints
        register_rest_route(self::NAMESPACE, '/intergroup-meetings', [
            'methods' => 'GET',
            'callback' => [self::class, 'getIntergroupMeetings'],
            'permission_callback' => [self::class, 'checkPermission'],
            'args' => self::getIntergroupMeetingsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [self::class, 'getIntergroupMeeting'],
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
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : (int) $param;
                },
            ],
            'online' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || in_array($param, ['true', 'false', '1', '0', true, false], true);
                },
                'sanitize_callback' => function ($param) {
                    if ($param === null) {
                        return null;
                    }
                    // Convert string 'true'/'false' to boolean
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
     * Get arguments for positions endpoint
     */
    private static function getPositionsArgs(): array
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
        ];
    }

    /**
     * Get arguments for members endpoint
     */
    private static function getMembersArgs(): array
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
            'home_group_id' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    return $param === null || (is_numeric($param) && $param > 0);
                },
                'sanitize_callback' => function ($param) {
                    return $param === null ? null : absint($param);
                },
            ],
        ];
    }

    /**
     * Get arguments for intergroup meetings endpoint
     */
    private static function getIntergroupMeetingsArgs(): array
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
            'date_from' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    if ($param === null) {
                        return true;
                    }
                    // Validate date format Y-m-d
                    $date = \DateTime::createFromFormat('Y-m-d', $param);
                    return $date && $date->format('Y-m-d') === $param;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'date_to' => [
                'default' => null,
                'validate_callback' => function ($param) {
                    if ($param === null) {
                        return true;
                    }
                    // Validate date format Y-m-d
                    $date = \DateTime::createFromFormat('Y-m-d', $param);
                    return $date && $date->format('Y-m-d') === $param;
                },
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
            $container = Plugin::getContainer();
            $groupRepo = $container->get(GroupRepositoryInterface::class);

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
            $container = Plugin::getContainer();
            $groupRepo = $container->get(GroupRepositoryInterface::class);

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
            $container = Plugin::getContainer();
            $meetingRepo = $container->get(MeetingRepositoryInterface::class);

            // Build query args
            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $groupId = $request->get_param('group_id');
            if ($groupId !== null) {
                $args['meta_query'] = $args['meta_query'] ?? [];
                $args['meta_query'][] = [
                    'key' => 'group_id',
                    'value' => (int) $groupId,
                    'compare' => '=',
                ];
            }

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            // Get day parameter
            $day = $request->get_param('day');
            $online = $request->get_param('online');

            // Determine which repository method to use based on filters
            $meetings = [];

            if ($day !== null && $online !== null) {
                // Both day AND online filter
                $onlineFilter = in_array($online, ['true', '1', true], true);

                // Add attendance_option to meta_query
                $args['meta_query'] = $args['meta_query'] ?? [];
                $args['meta_query'][] = [
                    'key' => 'attendance_option',
                    'value' => $onlineFilter ? 'online' : 'in_person',
                    'compare' => '=',
                ];

                $meetings = $meetingRepo->findByDay((int) $day, $args);
            } elseif ($day !== null) {
                // Only day filter
                $meetings = $meetingRepo->findByDay((int) $day, $args);
            } elseif ($online !== null) {
                // Only online filter
                $onlineFilter = in_array($online, ['true', '1', true], true);
                if ($onlineFilter) {
                    $meetings = $meetingRepo->findOnline($args);
                } else {
                    $meetings = $meetingRepo->findInPerson($args);
                }
            } else {
                // No special filters
                $meetings = $meetingRepo->findAll($args);
            }

            // Get total count for pagination
            $total = count($meetings);

            // Transform to API response format
            $data = array_map([self::class, 'transformMeeting'], $meetings);

            $perPage = $request->get_param('per_page');
            $page = $request->get_param('page');
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['per_page' => $perPage, 'page' => $page, 'day' => $day, 'online' => $online],
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
                    'total_pages' => $totalPages,
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
            $container = Plugin::getContainer();
            $meetingRepo = $container->get(MeetingRepositoryInterface::class);

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
     * Get all positions
     */
    public static function getPositions(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $positionRepo = $container->get(PositionRepositoryInterface::class);

            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            $positions = $positionRepo->findAll($args);
            $total = $positionRepo->count($args);

            $perPage = (int) $request->get_param('per_page');
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                $args,
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => array_map([self::class, 'transformPosition'], $positions),
                'meta' => [
                    'total' => $total,
                    'page' => (int) $request->get_param('page'),
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
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
     * Get a single position
     */
    public static function getPosition(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $positionRepo = $container->get(PositionRepositoryInterface::class);

            // Use findById instead of find for positions
            $position = $positionRepo->findById($id);

            if (!$position) {
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
                        'message' => 'Position not found',
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
                'data' => self::transformPosition($position),
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
     * Get all members
     */
    public static function getMembers(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepositoryInterface::class);
            $groupRepo = $container->get(GroupRepositoryInterface::class);
            $positionRepo = $container->get(PositionRepositoryInterface::class);
            $meetingRepo = $container->get(MeetingRepositoryInterface::class);

            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            $homeGroupId = $request->get_param('home_group_id');
            if ($homeGroupId !== null) {
                $args['meta_query'] = [
                    [
                        'key' => 'home_group',
                        'value' => $homeGroupId,
                        'compare' => '=',
                    ],
                ];
            }

            $members = $memberRepo->findAll($args);
            $total = $memberRepo->count($args);

            $perPage = (int) $request->get_param('per_page');
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

            // Collect all IDs needed for transformation
            $groupIds = [];
            $positionIds = [];
            $meetingIds = [];
            foreach ($members as $member) {
                $homeGroup = $member->getHomeGroup();
                if ($homeGroup > 0) {
                    $groupIds[] = $homeGroup;
                }
                $intergroupPosition = $member->getIntergroupPosition();
                if ($intergroupPosition > 0) {
                    $positionIds[] = $intergroupPosition;
                }
                $meetingPo = $member->getMeetingPO();
                if (is_numeric($meetingPo) && (int) $meetingPo > 0) {
                    $meetingIds[] = (int) $meetingPo;
                }
            }

            // Batch fetch using repositories
            $groupCache = self::batchGetGroups($groupRepo, array_unique($groupIds));
            $positionCache = self::batchGetPositions($positionRepo, array_unique($positionIds));
            $meetingCache = self::batchGetMeetings($meetingRepo, array_unique($meetingIds));

            // Transform with cached data
            $transformedMembers = array_map(function ($member) use ($groupCache, $positionCache, $meetingCache) {
                return self::transformMemberWithCache($member, $groupCache, $positionCache, $meetingCache);
            }, $members);

            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                $args,
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $transformedMembers,
                'meta' => [
                    'total' => $total,
                    'page' => (int) $request->get_param('page'),
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
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
     * Get a single member
     */
    public static function getMember(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepositoryInterface::class);

            $member = $memberRepo->find($id);

            if (!$member) {
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
                        'message' => 'Member not found',
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
                'data' => self::transformMember($member),
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
     * Get all intergroup meetings
     */
    public static function getIntergroupMeetings(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepositoryInterface::class);
            $memberRepo = $container->get(MemberRepositoryInterface::class);

            $args = [
                'posts_per_page' => $request->get_param('per_page'),
                'paged' => $request->get_param('page'),
            ];

            $dateFrom = $request->get_param('date_from');
            $dateTo = $request->get_param('date_to');

            if ($dateFrom !== null || $dateTo !== null) {
                $metaQuery = [];
                if ($dateFrom !== null) {
                    // Convert Y-m-d to Ymd format (ACF stores dates as Ymd)
                    $dateFromFormatted = str_replace('-', '', $dateFrom);
                    $metaQuery[] = [
                        'key' => 'intergroup-meeting_date',
                        'value' => $dateFromFormatted,
                        'compare' => '>=',
                    ];
                }
                if ($dateTo !== null) {
                    // Convert Y-m-d to Ymd format (ACF stores dates as Ymd)
                    $dateToFormatted = str_replace('-', '', $dateTo);
                    $metaQuery[] = [
                        'key' => 'intergroup-meeting_date',
                        'value' => $dateToFormatted,
                        'compare' => '<=',
                    ];
                }
                if (count($metaQuery) > 1) {
                    $metaQuery['relation'] = 'AND';
                }
                $args['meta_query'] = $metaQuery;
            }

            $intergroupMeetings = $intergroupMeetingRepo->findAll($args);
            $total = $intergroupMeetingRepo->count($args);

            $perPage = (int) $request->get_param('per_page');
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

            // Collect all member IDs needed for transformation
            $allMemberIds = [];
            foreach ($intergroupMeetings as $meeting) {
                $allMemberIds = array_merge($allMemberIds, $meeting->getGroupAttendees(), $meeting->getOfficersAttending());
            }
            $allMemberIds = array_unique(array_filter($allMemberIds));

            // Batch fetch all members at once using repository
            $memberCache = self::batchGetMembers($memberRepo, $allMemberIds);

            // Transform with cached members
            $transformedMeetings = array_map(function ($meeting) use ($memberCache) {
                return self::transformIntergroupMeetingWithCache($meeting, $memberCache);
            }, $intergroupMeetings);

            AuditLogger::log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                $args,
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $transformedMeetings,
                'meta' => [
                    'total' => $total,
                    'page' => (int) $request->get_param('page'),
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
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
     * Get a single intergroup meeting
     */
    public static function getIntergroupMeeting(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepositoryInterface::class);

            $intergroupMeeting = $intergroupMeetingRepo->find($id);

            if (!$intergroupMeeting) {
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
                        'message' => 'Intergroup meeting not found',
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
                'data' => self::transformIntergroupMeeting($intergroupMeeting),
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
     * @param GroupInterface $group
     * @param array $expand Array of fields to expand (e.g., ['meetings'])
     * @return array
     */
    private static function transformGroup(GroupInterface $group, array $expand = []): array
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
    private static function transformMeeting(MeetingInterface $meeting): array
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
     * @param LocationInterface $location
     * @return array
     */
    private static function transformLocation(LocationInterface $location): array
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
     * @param ContactInterface|array $contact
     * @return array
     */
    private static function transformContact($contact): array
    {
        if ($contact instanceof ContactInterface) {
            return [
                'name' => $contact->getName(),
                'email' => Mask::email($contact->getEmail()),
                'phone' => Mask::phone($contact->getPhone()),
            ];
        }

        // Handle legacy array format for backwards compatibility
        if (is_array($contact)) {
            return [
                'name' => $contact['name'] ?? '',
                'email' => Mask::email($contact['email'] ?? ''),
                'phone' => Mask::phone($contact['phone'] ?? ''),
            ];
        }

        return [
            'name' => '',
            'email' => '',
            'phone' => '',
        ];
    }

    /**
     * Transform a Position object to API response format
     *
     * @param PositionInterface $position
     * @return array
     */
    private static function transformPosition(PositionInterface $position): array
    {
        return [
            'id' => $position->getId(),
            'long_name' => $position->getLongName(),
            'short_description' => $position->getShortDescription(),
            'summary' => $position->getSummary(),
            'email' => $position->getEmail(),
            'minimum_sobriety' => $position->getMinimumSobriety(),
            'term_years' => $position->getTermYears(),
            'link' => $position->getLink(),
        ];
    }

    /**
     * Transform a Member object to API response format
     *
     * @param MemberInterface $member
     * @return array
     * @deprecated Use transformMemberWithCache for better performance
     */
    private static function transformMember(MemberInterface $member): array
    {
        $container = Plugin::getContainer();
        $groupRepo = $container->get(GroupRepositoryInterface::class);
        $positionRepo = $container->get(PositionRepositoryInterface::class);
        $meetingRepo = $container->get(MeetingRepositoryInterface::class);

        // Collect IDs
        $groupIds = [];
        $positionIds = [];
        $meetingIds = [];

        $homeGroup = $member->getHomeGroup();
        if ($homeGroup > 0) {
            $groupIds[] = $homeGroup;
        }
        $intergroupPosition = $member->getIntergroupPosition();
        if ($intergroupPosition > 0) {
            $positionIds[] = $intergroupPosition;
        }
        $meetingPo = $member->getMeetingPO();
        if (is_numeric($meetingPo) && (int) $meetingPo > 0) {
            $meetingIds[] = (int) $meetingPo;
        }

        // Batch fetch
        $groupCache = self::batchGetGroups($groupRepo, $groupIds);
        $positionCache = self::batchGetPositions($positionRepo, $positionIds);
        $meetingCache = self::batchGetMeetings($meetingRepo, $meetingIds);

        return self::transformMemberWithCache($member, $groupCache, $positionCache, $meetingCache);
    }

    /**
     * Transform a Member object to API response format using cached entities
     *
     * @param MemberInterface $member
     * @param array<int, GroupInterface> $groupCache
     * @param array<int, PositionInterface> $positionCache
     * @param array<int, MeetingInterface> $meetingCache
     * @return array
     */
    private static function transformMemberWithCache(
        MemberInterface $member,
        array $groupCache,
        array $positionCache,
        array $meetingCache
    ): array {
        // Get home group details
        $homeGroupId = null;
        $homeGroupName = '';
        $homeGroup = $member->getHomeGroup();
        if ($homeGroup > 0) {
            $homeGroupId = $homeGroup;
            if (isset($groupCache[$homeGroupId])) {
                $homeGroupName = $groupCache[$homeGroupId]->getTitle();
            }
        }

        // Get intergroup position details
        $intergroupPositionId = null;
        $intergroupPositionName = '';
        $intergroupPositionIdValue = $member->getIntergroupPosition();
        if ($intergroupPositionIdValue > 0) {
            $intergroupPositionId = $intergroupPositionIdValue;
            if (isset($positionCache[$intergroupPositionId])) {
                $intergroupPositionName = $positionCache[$intergroupPositionId]->getLongName();
            }
        }

        // Get meeting PO
        $meetingPo = '';
        $meetingPoValue = $member->getMeetingPO();
        if (is_numeric($meetingPoValue)) {
            $meetingPoId = (int) $meetingPoValue;
            if (isset($meetingCache[$meetingPoId])) {
                $meetingPo = $meetingCache[$meetingPoId]->getName();
            }
        } elseif (is_string($meetingPoValue)) {
            $meetingPo = $meetingPoValue;
        }

        // Get permalink
        $link = '';
        if (function_exists('get_permalink')) {
            $permalink = get_permalink($member->getId());
            $link = is_string($permalink) ? $permalink : '';
        }

        return [
            'id' => $member->getId(),
            'private_name' => $member->getPrivateName(),
            'anonymous_name' => $member->getAnonymousName(),
            'email' => $member->getEmail(),
            'personal_email' => $member->getPersonalEmail(),
            'mobile_number' => $member->getMobileNumber(),
            'show_anonymous_name' => $member->showAnonymousName(),
            'show_member_profile' => $member->showMemberProfile(),
            'anonymous_profile' => $member->getAnonymousProfile(),
            'home_group_id' => $homeGroupId,
            'home_group_name' => $homeGroupName,
            'is_gsr' => $member->isGSR(),
            'meeting_po' => $meetingPo,
            'intergroup_position_id' => $intergroupPositionId,
            'intergroup_position_name' => $intergroupPositionName,
            'intergroup_position_rotation' => $member->getIntergroupPositionRotation(),
            'link' => $link,
        ];
    }

    /**
     * Batch fetch members by IDs using repository
     *
     * @param MemberRepositoryInterface $memberRepo
     * @param array<int> $memberIds
     * @return array<int, MemberInterface> Map of member ID to member object
     */
    private static function batchGetMembers(MemberRepositoryInterface $memberRepo, array $memberIds): array
    {
        if (empty($memberIds)) {
            return [];
        }

        $members = $memberRepo->findAll([
            'post__in' => $memberIds,
            'posts_per_page' => -1,
        ]);

        $memberMap = [];
        foreach ($members as $member) {
            $memberMap[$member->getId()] = $member;
        }

        return $memberMap;
    }

    /**
     * Batch fetch groups by IDs using repository
     *
     * @param GroupRepositoryInterface $groupRepo
     * @param array<int> $groupIds
     * @return array<int, GroupInterface> Map of group ID to group object
     */
    private static function batchGetGroups(GroupRepositoryInterface $groupRepo, array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }

        $groups = $groupRepo->findAll([
            'post__in' => $groupIds,
            'posts_per_page' => -1,
        ]);

        $groupMap = [];
        foreach ($groups as $group) {
            $groupMap[$group->getId()] = $group;
        }

        return $groupMap;
    }

    /**
     * Batch fetch positions by IDs using repository
     *
     * @param PositionRepositoryInterface $positionRepo
     * @param array<int> $positionIds
     * @return array<int, PositionInterface> Map of position ID to position object
     */
    private static function batchGetPositions(PositionRepositoryInterface $positionRepo, array $positionIds): array
    {
        if (empty($positionIds)) {
            return [];
        }

        $positions = $positionRepo->findAll([
            'post__in' => $positionIds,
            'posts_per_page' => -1,
        ]);

        $positionMap = [];
        foreach ($positions as $position) {
            $positionMap[$position->getId()] = $position;
        }

        return $positionMap;
    }

    /**
     * Batch fetch meetings by IDs using repository
     *
     * @param MeetingRepositoryInterface $meetingRepo
     * @param array<int> $meetingIds
     * @return array<int, MeetingInterface> Map of meeting ID to meeting object
     */
    private static function batchGetMeetings(MeetingRepositoryInterface $meetingRepo, array $meetingIds): array
    {
        if (empty($meetingIds)) {
            return [];
        }

        $meetings = $meetingRepo->findAll([
            'post__in' => $meetingIds,
            'posts_per_page' => -1,
        ]);

        $meetingMap = [];
        foreach ($meetings as $meeting) {
            $meetingMap[$meeting->getId()] = $meeting;
        }

        return $meetingMap;
    }

    /**
     * Transform an IntergroupMeeting object to API response format using cached members
     *
     * @param IntergroupMeetingInterface $intergroupMeeting
     * @param array<int, MemberInterface> $memberCache
     * @return array
     */
    private static function transformIntergroupMeetingWithCache(IntergroupMeetingInterface $intergroupMeeting, array $memberCache): array
    {
        $groupAttendeeIds = $intergroupMeeting->getGroupAttendees();
        $groupAttendees = [];

        foreach ($groupAttendeeIds as $attendeeId) {
            if (isset($memberCache[$attendeeId])) {
                $groupAttendees[] = [
                    'id' => $attendeeId,
                    'name' => $memberCache[$attendeeId]->getAnonymousName(),
                ];
            }
        }

        $officersAttendingIds = $intergroupMeeting->getOfficersAttending();
        $officersAttending = [];

        foreach ($officersAttendingIds as $officerId) {
            if (isset($memberCache[$officerId])) {
                $officersAttending[] = [
                    'id' => $officerId,
                    'name' => $memberCache[$officerId]->getAnonymousName(),
                ];
            }
        }

        return [
            'id' => $intergroupMeeting->getId(),
            'date' => $intergroupMeeting->getDate(),
            'group_attendee_ids' => $groupAttendeeIds,
            'group_attendees' => $groupAttendees,
            'officers_attending_ids' => $officersAttendingIds,
            'officers_attending' => $officersAttending,
        ];
    }

    /**
     * Transform an IntergroupMeeting object to API response format
     *
     * @param IntergroupMeetingInterface $intergroupMeeting
     * @return array
     * @deprecated Use transformIntergroupMeetingWithCache for better performance
     */
    private static function transformIntergroupMeeting(IntergroupMeetingInterface $intergroupMeeting): array
    {
        $container = Plugin::getContainer();
        $memberRepo = $container->get(MemberRepositoryInterface::class);

        $groupAttendeeIds = $intergroupMeeting->getGroupAttendees();
        $officersAttendingIds = $intergroupMeeting->getOfficersAttending();

        $allMemberIds = array_unique(array_merge($groupAttendeeIds, $officersAttendingIds));
        $memberCache = self::batchGetMembers($memberRepo, $allMemberIds);

        return self::transformIntergroupMeetingWithCache($intergroupMeeting, $memberCache);
    }


}
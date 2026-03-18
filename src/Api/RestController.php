<?php

declare(strict_types=1);

namespace Integrity\Api;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\RateLimiter;
use Integrity\Auth\AuditLogger;
use Integrity\Utils\Mask;
use Unity\Plugin;
use Unity\Contacts\Interfaces\Contact;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\Locations\Interfaces\Location;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API Controller
 *
 * Handles all REST API routes with authentication, rate limiting, and audit logging.
 * Dependencies are injected via constructor following the Scrutiny DI pattern.
 */
class RestController
{
    private const NAMESPACE = 'integrity/v1';

    private ApiKeyManager $apiKeyManager;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    /**
     * The currently registered rest_post_dispatch filter callback.
     *
     * Stored so it can be removed after each request, preventing closure
     * accumulation when WordPress handles multiple REST calls in one process.
     *
     * @var callable|null
     */
    private $rateLimitFilter = null;

    public function __construct(
        ApiKeyManager $apiKeyManager,
        AuditLogger $auditLogger,
        RateLimiter $rateLimiter
    ) {
        $this->apiKeyManager = $apiKeyManager;
        $this->auditLogger = $auditLogger;
        $this->rateLimiter = $rateLimiter;
    }


    /**
     * Register REST API routes
     */
    public function register(): void
    {
        // Groups endpoints
        register_rest_route(self::NAMESPACE, '/groups', [
            'methods' => 'GET',
            'callback' => [$this, 'getGroups'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getGroupsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/groups/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getGroup'],
            'permission_callback' => [$this, 'checkPermission'],
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
            'callback' => [$this, 'getMeetings'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getMeetingsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/meetings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getMeeting'],
            'permission_callback' => [$this, 'checkPermission'],
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
            'callback' => [$this, 'getPositions'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getPositionsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/positions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getPosition'],
            'permission_callback' => [$this, 'checkPermission'],
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
            'callback' => [$this, 'getMembers'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getMembersArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/members/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getMember'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/members/(?P<id>\d+)/update', [
            'methods' => 'POST',
            'callback' => [$this, 'updateMember'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getUpdateMemberArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/members/create', [
            'methods' => 'POST',
            'callback' => [$this, 'createMember'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getCreateMemberArgs(),
        ]);

        // Intergroup Meetings endpoints
        register_rest_route(self::NAMESPACE, '/intergroup-meetings', [
            'methods' => 'GET',
            'callback' => [$this, 'getIntergroupMeetings'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getIntergroupMeetingsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getIntergroupMeeting'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param) && $param > 0;
                    },
                ],
            ],
        ]);

        // Intergroup Meeting Group Registration endpoint
        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/register-group', [
            'methods' => 'POST',
            'callback' => [$this, 'registerIntergroupMeetingAttendee'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getRegisterAttendeeArgs(),
        ]);

        // Intergroup Meeting Group Unregister endpoint
        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/unregister-group', [
            'methods' => 'POST',
            'callback' => [$this, 'unregisterIntergroupMeetingAttendee'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getUnregisterAttendeeArgs(),
        ]);

        // Intergroup Meeting Officer Registration endpoint
        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/register-officer', [
            'methods' => 'POST',
            'callback' => [$this, 'registerIntergroupMeetingOfficer'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getRegisterOfficerArgs(),
        ]);

        // Intergroup Meeting Officer Unregister endpoint
        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/unregister-officer', [
            'methods' => 'POST',
            'callback' => [$this, 'unregisterIntergroupMeetingOfficer'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getUnregisterOfficerArgs(),
        ]);

        // Health check endpoint (no auth required)
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'healthCheck'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Get arguments for groups endpoint
     */
    private function getGroupsArgs(): array
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
    private function getMeetingsArgs(): array
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
    private function getPositionsArgs(): array
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
    private function getMembersArgs(): array
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
     * Get arguments for update member endpoint
     */
    private function getUpdateMemberArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'anonymous_name' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen($param) <= 255;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'personal_email' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && ($param === '' || is_email($param));
                },
                'sanitize_callback' => 'sanitize_email',
            ],
            'mobile_number' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen($param) <= 50;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'show_anonymous_name' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'show_member_profile' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'anonymous_profile' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param);
                },
                'sanitize_callback' => 'wp_kses_post',
            ],
            'home_group_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'is_gsr' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'intergroup_position_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'intergroup_position_rotation' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    if ($param === '' || $param === null) {
                        return true;
                    }
                    // Validate date format Y-m-d if provided
                    $date = \DateTime::createFromFormat('Y-m-d', $param);
                    return $date && $date->format('Y-m-d') === $param;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get arguments for create member endpoint
     */
    private function getCreateMemberArgs(): array
    {
        return [
            'anonymous_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0 && strlen($param) <= 255;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'personal_email' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && ($param === '' || is_email($param));
                },
                'sanitize_callback' => 'sanitize_email',
            ],
            'mobile_number' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen($param) <= 50;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'home_group_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'is_gsr' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || in_array($param, ['true', 'false', '1', '0', 1, 0], true);
                },
                'sanitize_callback' => function ($param) {
                    return filter_var($param, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
                },
            ],
            'intergroup_position_id' => [
                'required' => false,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Get arguments for intergroup meetings endpoint
     */
    private function getIntergroupMeetingsArgs(): array
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
     * Get arguments for register attendee endpoint
     */
    private function getRegisterAttendeeArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'group_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'member_id' => [
                'required' => false,
                'default' => 0,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param >= 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'gsr_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'gsr_proxy' => [
                'required' => false,
                'default' => false,
                'validate_callback' => function ($param) {
                    return is_bool($param) || $param === '0' || $param === '1'
                        || $param === 'true' || $param === 'false';
                },
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'gsr_proxy_name' => [
                'required' => false,
                'default' => '',
                'validate_callback' => function ($param) {
                    return is_string($param);
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get arguments for unregister attendee endpoint
     */
    private function getUnregisterAttendeeArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'group_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Get arguments for register officer endpoint
     */
    private function getRegisterOfficerArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'officer_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'position_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'officer_name' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen(trim($param)) > 0;
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * Get arguments for unregister officer endpoint
     */
    private function getUnregisterOfficerArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
            'officer_id' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ];
    }

    /**
     * Check permission and authenticate request
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function checkPermission(WP_REST_Request $request)
    {
        $startTime = microtime(true);

        // Require HTTPS in production
        if (get_option('integrity_require_https', true) && !is_ssl() && !(defined('WP_DEBUG') && WP_DEBUG)) {
            $this->logFailedRequest($request, 403, $startTime);
            return new WP_Error(
                'https_required',
                'HTTPS is required for API access',
                ['status' => 403]
            );
        }

        // Get API key from header
        $apiKey = $this->extractApiKey($request);

        if (!$apiKey) {
            $this->logFailedRequest($request, 401, $startTime);
            return new WP_Error(
                'missing_api_key',
                'API key is required. Provide it in the Authorization header as: Bearer <api_key>',
                ['status' => 401]
            );
        }

        // Validate API key
        $clientIp = $this->auditLogger->getClientIp();
        $keyData = $this->apiKeyManager->validateKey($apiKey, $clientIp);

        if (!$keyData) {
            $this->logFailedRequest($request, 401, $startTime);
            return new WP_Error(
                'invalid_api_key',
                'Invalid or expired API key',
                ['status' => 401]
            );
        }

        // Check rate limits (cast to int as database returns strings)
        $apiKeyId = (int) $keyData['id'];
        $rateLimit = (int) $keyData['rate_limit'];
        $rateLimitResult = $this->rateLimiter->checkLimit($apiKeyId, $rateLimit);

        if (!$rateLimitResult['allowed']) {
            $this->logFailedRequest($request, 429, $startTime, $apiKeyId);

            $response = new WP_Error(
                'rate_limit_exceeded',
                'Rate limit exceeded. Try again later.',
                ['status' => 429]
            );

            // Add rate limit headers
            $this->attachRateLimitFilter($rateLimit, $rateLimitResult);

            return $response;
        }

        // Increment rate limit counter
        $this->rateLimiter->incrementCount($apiKeyId);

        // Check endpoint-specific permissions
        $endpoint = $request->get_route();
        $requiredPermission = $this->getRequiredPermission($endpoint);

        if ($requiredPermission && !in_array($requiredPermission, $keyData['permissions'], true) && !in_array('*', $keyData['permissions'], true)) {
            $this->logFailedRequest($request, 403, $startTime, $apiKeyId);
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
        $this->attachRateLimitFilter($rateLimit, $rateLimitResult);

        return true;
    }

    /**
     * Attach rate limit headers via rest_post_dispatch, replacing any previous filter.
     *
     * The callback is stored in $this->rateLimitFilter so that:
     *  1. Any previously registered callback is removed first (prevents accumulation).
     *  2. The callback removes itself after firing (one-shot per request).
     *
     * @param int   $rateLimit       The rate limit ceiling for this API key
     * @param array $rateLimitResult Result from RateLimiter::checkLimit()
     */
    private function attachRateLimitFilter(int $rateLimit, array $rateLimitResult): void
    {
        // Remove any filter left over from a previous call in the same process
        if ($this->rateLimitFilter !== null) {
            remove_filter('rest_post_dispatch', $this->rateLimitFilter);
        }

        $this->rateLimitFilter = function (WP_REST_Response $result) use ($rateLimit, $rateLimitResult): WP_REST_Response {
            foreach ($this->rateLimiter->getHeaders($rateLimit, $rateLimitResult['remaining'], $rateLimitResult['reset']) as $header => $value) {
                $result->header($header, $value);
            }

            // Self-remove so this closure never fires again
            remove_filter('rest_post_dispatch', $this->rateLimitFilter);
            $this->rateLimitFilter = null;

            return $result;
        };

        add_filter('rest_post_dispatch', $this->rateLimitFilter);
    }

    /**
     * Extract API key from request
     */
    private function extractApiKey(WP_REST_Request $request): ?string
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
    private function getRequiredPermission(string $endpoint): ?string
    {
        // Check registration endpoints before general intergroup-meetings (more specific first)
        if (strpos($endpoint, '/intergroup-meetings') !== false
            && (strpos($endpoint, '/register') !== false || strpos($endpoint, '/unregister') !== false)
        ) {
            return 'intergroup-meetings:write';
        }

        // Check intergroup-meetings before meetings/members (substring match)
        if (strpos($endpoint, '/intergroup-meetings') !== false) {
            return 'intergroup-meetings:read';
        }

        if (strpos($endpoint, '/groups') !== false) {
            return 'groups:read';
        }

        if (strpos($endpoint, '/meetings') !== false) {
            return 'meetings:read';
        }

        if (strpos($endpoint, '/members') !== false) {
            if (strpos($endpoint, '/update') !== false || strpos($endpoint, '/create') !== false) {
                return 'members:write';
            }
            return 'members:read';
        }

        if (strpos($endpoint, '/positions') !== false) {
            return 'positions:read';
        }

        return null;
    }

    /**
     * Log a failed request
     */
    private function logFailedRequest(WP_REST_Request $request, int $code, float $startTime, ?int $keyId = null): void
    {
        $this->auditLogger->log(
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
    public function getGroups(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            // Get Unity container
            $container = Plugin::getContainer();
            $groupRepo = $container->get(GroupRepository::class);

            $perPage = (int) $request->get_param('per_page');
            $page = (int) $request->get_param('page');

            // Build query args
            $args = [
                'posts_per_page' => $perPage,
                'paged' => $page,
            ];

            $search = $request->get_param('search');
            if (!empty($search)) {
                $args['s'] = $search;
            }

            // Get groups for the current page
            $groups = $groupRepo->findAll($args);

            // Filter by district if specified
            $districtId = $request->get_param('district_id');
            if ($districtId !== null) {
                $groups = array_filter($groups, function ($group) use ($districtId) {
                    return $group->getDistrictId() === $districtId;
                });
            }

            // Get the true total across all pages (lightweight ID-only query)
            $countArgs = array_diff_key($args, ['posts_per_page' => 0, 'paged' => 0]);
            $countArgs['posts_per_page'] = -1;
            $countArgs['fields'] = 'ids';
            $total = count($groupRepo->findAll($countArgs));
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

            // Parse expand parameter
            $expandParam = $request->get_param('expand');
            $expand = !empty($expandParam) ? array_filter(array_map('trim', explode(',', $expandParam))) : [];

            // Transform to API response format
            $data = array_map(function($group) use ($expand) {
                return $this->transformGroup($group, $expand);
            }, $groups);

            // Log successful request
            $this->auditLogger->log(
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
                    'total_pages' => $totalPages,
                ],
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
    public function getGroup(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $groupRepo = $container->get(GroupRepository::class);

            $group = $groupRepo->findById($id);

            if (!$group || !$group->isValid()) {
                $this->auditLogger->log(
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

            $this->auditLogger->log(
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
                'data' => $this->transformGroup($group, $expand),
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
    public function getMeetings(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $meetingRepo = $container->get(MeetingRepository::class);

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

            // Get the true total across all pages.
            // Build count args: same filters, but remove pagination keys so we
            // count every matching record (count() sets posts_per_page=-1 and
            // fields=ids internally).
            $countArgs = array_diff_key($args, ['posts_per_page' => 0, 'paged' => 0]);

            if ($day !== null && $online !== null) {
                // day + online: both filters are in meta_query, so count() is accurate
                $total = $meetingRepo->count($countArgs);
            } elseif ($day !== null) {
                // day only: filter is added by findByDay via meta_query, count() is accurate
                $total = $meetingRepo->count($countArgs);
            } elseif ($online !== null) {
                // online/in-person: these use PHP-level array_filter inside the
                // repository, so we must replicate that approach for the count.
                $onlineFilter = in_array($online, ['true', '1', true], true);
                if ($onlineFilter) {
                    $total = count($meetingRepo->findOnline($countArgs));
                } else {
                    $total = count($meetingRepo->findInPerson($countArgs));
                }
            } else {
                // No special filters: count() is accurate
                $total = $meetingRepo->count($countArgs);
            }

            // Transform to API response format
            $data = array_map([$this, 'transformMeeting'], $meetings);

            $perPage = $request->get_param('per_page');
            $page = $request->get_param('page');
            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

            $this->auditLogger->log(
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
            $this->auditLogger->log(
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
    public function getMeeting(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $meetingRepo = $container->get(MeetingRepository::class);

            $meeting = $meetingRepo->find($id);

            if (!$meeting) {
                $this->auditLogger->log(
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

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->transformMeeting($meeting),
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
    public function getPositions(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $positionRepo = $container->get(PositionRepository::class);

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

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                $args,
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => array_map([$this, 'transformPosition'], $positions),
                'meta' => [
                    'total' => $total,
                    'page' => (int) $request->get_param('page'),
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
                ],
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
    public function getPosition(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $positionRepo = $container->get(PositionRepository::class);

            // Use findById instead of find for positions
            $position = $positionRepo->findById($id);

            if (!$position) {
                $this->auditLogger->log(
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

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->transformPosition($position),
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
    public function getMembers(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);
            $groupRepo = $container->get(GroupRepository::class);
            $positionRepo = $container->get(PositionRepository::class);
            $meetingRepo = $container->get(MeetingRepository::class);

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
            $groupCache = $this->batchGetGroups($groupRepo, array_unique($groupIds));
            $positionCache = $this->batchGetPositions($positionRepo, array_unique($positionIds));
            $meetingCache = $this->batchGetMeetings($meetingRepo, array_unique($meetingIds));

            // Transform with cached data
            $transformedMembers = array_map(function ($member) use ($groupCache, $positionCache, $meetingCache) {
                return $this->transformMemberWithCache($member, $groupCache, $positionCache, $meetingCache);
            }, $members);

            $this->auditLogger->log(
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
            $this->auditLogger->log(
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
    public function getMember(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);

            $member = $memberRepo->find($id);

            if (!$member) {
                $this->auditLogger->log(
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

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                200,
                microtime(true) - $startTime
            );

            // Resolve related entities in one pass (avoids N+1 from the deprecated transformMember)
            $groupRepo = $container->get(GroupRepository::class);
            $positionRepo = $container->get(PositionRepository::class);
            $meetingRepo = $container->get(MeetingRepository::class);

            $groupCache = $this->batchGetGroups($groupRepo, $member->getHomeGroup() > 0 ? [$member->getHomeGroup()] : []);
            $positionCache = $this->batchGetPositions($positionRepo, $member->getIntergroupPosition() > 0 ? [$member->getIntergroupPosition()] : []);
            $meetingPo = $member->getMeetingPO();
            $meetingCache = $this->batchGetMeetings($meetingRepo, is_numeric($meetingPo) && (int) $meetingPo > 0 ? [(int) $meetingPo] : []);

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->transformMemberWithCache($member, $groupCache, $positionCache, $meetingCache),
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
     * Update a member
     */
    public function updateMember(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);
            $memberFactory = $container->get(MemberFactory::class);

            // Fetch existing member
            $existingMember = $memberRepo->find($id);

            if (!$existingMember) {
                $this->auditLogger->log(
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

            // Validate referenced entities exist
            $homeGroupId = $request->has_param('home_group_id')
                ? (int) $request->get_param('home_group_id')
                : $existingMember->getHomeGroup();

            if ($request->has_param('home_group_id') && $homeGroupId > 0) {
                $groupRepo = $container->get(GroupRepository::class);
                $group = $groupRepo->findById($homeGroupId);
                if (!$group || !$group->isValid()) {
                    $this->auditLogger->log(
                        $keyData['api_key_id'],
                        $request->get_route(),
                        $request->get_method(),
                        ['id' => $id, 'home_group_id' => $homeGroupId],
                        422,
                        microtime(true) - $startTime
                    );

                    return new WP_REST_Response([
                        'success' => false,
                        'error' => [
                            'code' => 'invalid_home_group',
                            'message' => 'The specified home group does not exist',
                        ],
                    ], 422);
                }
            }

            $intergroupPositionId = $request->has_param('intergroup_position_id')
                ? (int) $request->get_param('intergroup_position_id')
                : $existingMember->getIntergroupPosition();

            if ($request->has_param('intergroup_position_id') && $intergroupPositionId > 0) {
                $positionRepo = $container->get(PositionRepository::class);
                $positions = $positionRepo->findAll([
                    'post__in' => [$intergroupPositionId],
                    'posts_per_page' => 1,
                ]);
                if (empty($positions)) {
                    $this->auditLogger->log(
                        $keyData['api_key_id'],
                        $request->get_route(),
                        $request->get_method(),
                        ['id' => $id, 'intergroup_position_id' => $intergroupPositionId],
                        422,
                        microtime(true) - $startTime
                    );

                    return new WP_REST_Response([
                        'success' => false,
                        'error' => [
                            'code' => 'invalid_intergroup_position',
                            'message' => 'The specified intergroup position does not exist',
                        ],
                    ], 422);
                }
            }

            // Resolve personal_email: skip if the submitted value is obscured
            $personalEmail = $existingMember->getPersonalEmail();
            if ($request->has_param('personal_email')) {
                $submittedEmail = $request->get_param('personal_email');
                if (!$this->isObscuredEmail($submittedEmail)) {
                    $personalEmail = $submittedEmail;
                }
            }

            // Resolve mobile_number: skip if the submitted value is obscured
            $mobileNumber = $existingMember->getMobileNumber();
            if ($request->has_param('mobile_number')) {
                $submittedMobile = $request->get_param('mobile_number');
                if (!$this->isObscuredPhone($submittedMobile)) {
                    $mobileNumber = $submittedMobile;
                }
            }

            // Build updated member using existing values as defaults (partial update)
            $updatedMember = $memberFactory->createNew(
                $id,
                $request->has_param('anonymous_name')
                    ? $request->get_param('anonymous_name')
                    : $existingMember->getAnonymousName(),
                $request->has_param('show_anonymous_name')
                    ? $request->get_param('show_anonymous_name')
                    : $existingMember->showAnonymousName(),
                $request->has_param('show_member_profile')
                    ? $request->get_param('show_member_profile')
                    : $existingMember->showMemberProfile(),
                $request->has_param('anonymous_profile')
                    ? $request->get_param('anonymous_profile')
                    : $existingMember->getAnonymousProfile(),
                $intergroupPositionId,
                $request->has_param('intergroup_position_rotation')
                    ? $request->get_param('intergroup_position_rotation')
                    : $existingMember->getIntergroupPositionRotation(),
                $homeGroupId,
                $request->has_param('is_gsr')
                    ? $request->get_param('is_gsr')
                    : $existingMember->isGSR(),
                $existingMember->getMeetingPO(),
                $personalEmail,
                $mobileNumber,
            );

            // Save
            $saved = $memberRepo->save($updatedMember);

            if (!$saved) {
                $this->auditLogger->log(
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
                        'code' => 'save_failed',
                        'message' => 'Failed to update member',
                    ],
                ], 500);
            }

            // Re-fetch the saved member to return the latest state
            $savedMember = $memberRepo->find($id);

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                200,
                microtime(true) - $startTime
            );

            $returnMember = $savedMember ?? $updatedMember;
            $groupRepo = $container->get(GroupRepository::class);
            $positionRepo = $container->get(PositionRepository::class);
            $meetingRepo = $container->get(MeetingRepository::class);

            $groupCache = $this->batchGetGroups($groupRepo, $returnMember->getHomeGroup() > 0 ? [$returnMember->getHomeGroup()] : []);
            $positionCache = $this->batchGetPositions($positionRepo, $returnMember->getIntergroupPosition() > 0 ? [$returnMember->getIntergroupPosition()] : []);
            $meetingPo = $returnMember->getMeetingPO();
            $meetingCache = $this->batchGetMeetings($meetingRepo, is_numeric($meetingPo) && (int) $meetingPo > 0 ? [(int) $meetingPo] : []);

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->transformMemberWithCache($returnMember, $groupCache, $positionCache, $meetingCache),
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
     * Create a new member
     */
    public function createMember(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $memberRepo = $container->get(MemberRepository::class);
            $memberFactory = $container->get(MemberFactory::class);

            $anonymousName = $request->get_param('anonymous_name');
            $personalEmail = $request->get_param('personal_email') ?? '';
            $mobileNumber = $request->get_param('mobile_number') ?? '';
            $homeGroupId = $request->has_param('home_group_id')
                ? (int) $request->get_param('home_group_id')
                : 0;
            $isGsr = $request->has_param('is_gsr')
                ? $request->get_param('is_gsr')
                : false;
            $intergroupPositionId = $request->has_param('intergroup_position_id')
                ? (int) $request->get_param('intergroup_position_id')
                : 0;

            // Validate referenced entities exist
            if ($homeGroupId > 0) {
                $groupRepo = $container->get(GroupRepository::class);
                $group = $groupRepo->findById($homeGroupId);
                if (!$group || !$group->isValid()) {
                    $this->auditLogger->log(
                        $keyData['api_key_id'],
                        $request->get_route(),
                        $request->get_method(),
                        ['home_group_id' => $homeGroupId],
                        422,
                        microtime(true) - $startTime
                    );

                    return new WP_REST_Response([
                        'success' => false,
                        'error' => [
                            'code' => 'invalid_home_group',
                            'message' => 'The specified home group does not exist',
                        ],
                    ], 422);
                }
            }

            if ($intergroupPositionId > 0) {
                $positionRepo = $container->get(PositionRepository::class);
                $positions = $positionRepo->findAll([
                    'post__in' => [$intergroupPositionId],
                    'posts_per_page' => 1,
                ]);
                if (empty($positions)) {
                    $this->auditLogger->log(
                        $keyData['api_key_id'],
                        $request->get_route(),
                        $request->get_method(),
                        ['intergroup_position_id' => $intergroupPositionId],
                        422,
                        microtime(true) - $startTime
                    );

                    return new WP_REST_Response([
                        'success' => false,
                        'error' => [
                            'code' => 'invalid_intergroup_position',
                            'message' => 'The specified intergroup position does not exist',
                        ],
                    ], 422);
                }
            }

            // Create the WordPress post for the new member
            $postId = wp_insert_post([
                'post_type' => 'intergroup-member',
                'post_title' => $anonymousName,
                'post_status' => 'publish',
            ], true);

            if (is_wp_error($postId)) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['anonymous_name' => $anonymousName],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'create_failed',
                        'message' => 'Failed to create member post: ' . $postId->get_error_message(),
                    ],
                ], 500);
            }

            // Build the member object with all fields via the factory
            $newMember = $memberFactory->createNew(
                $postId,
                $anonymousName,
                false,   // show_anonymous_name
                false,   // show_member_profile
                '',      // anonymous_profile
                $intergroupPositionId,
                '',      // intergroup_position_rotation
                $homeGroupId,
                $isGsr,
                null,    // meeting_po
                $personalEmail,
                $mobileNumber,
            );

            // Save ACF / meta fields
            $saved = $memberRepo->save($newMember);

            if (!$saved) {
                // Clean up the orphaned post
                wp_delete_post($postId, true);

                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['post_id' => $postId],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'save_failed',
                        'message' => 'Failed to save member fields',
                    ],
                ], 500);
            }

            // Re-fetch the saved member to return the latest state
            $savedMember = $memberRepo->find($postId);

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $postId, 'anonymous_name' => $anonymousName],
                201,
                microtime(true) - $startTime
            );

            $returnMember = $savedMember ?? $newMember;
            $groupRepo = $container->get(GroupRepository::class);
            $positionRepo = $container->get(PositionRepository::class);
            $meetingRepo = $container->get(MeetingRepository::class);

            $groupCache = $this->batchGetGroups($groupRepo, $returnMember->getHomeGroup() > 0 ? [$returnMember->getHomeGroup()] : []);
            $positionCache = $this->batchGetPositions($positionRepo, $returnMember->getIntergroupPosition() > 0 ? [$returnMember->getIntergroupPosition()] : []);
            $meetingPo = $returnMember->getMeetingPO();
            $meetingCache = $this->batchGetMeetings($meetingRepo, is_numeric($meetingPo) && (int) $meetingPo > 0 ? [(int) $meetingPo] : []);

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->transformMemberWithCache($returnMember, $groupCache, $positionCache, $meetingCache),
            ], 201);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
     * Get all intergroup meetings
     */
    public function getIntergroupMeetings(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $memberRepo = $container->get(MemberRepository::class);

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
            $memberCache = $this->batchGetMembers($memberRepo, $allMemberIds);

            // Transform with cached members
            $transformedMeetings = array_map(function ($meeting) use ($memberCache) {
                return $this->transformIntergroupMeetingWithCache($meeting, $memberCache);
            }, $intergroupMeetings);

            $this->auditLogger->log(
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
            $this->auditLogger->log(
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
    public function getIntergroupMeeting(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $id = (int) $request->get_param('id');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);

            $intergroupMeeting = $intergroupMeetingRepo->find($id);

            if (!$intergroupMeeting) {
                $this->auditLogger->log(
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

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $id],
                200,
                microtime(true) - $startTime
            );

            // Batch-fetch members for the attendee lists (avoids N+1 from deprecated transformIntergroupMeeting)
            $memberRepo = $container->get(MemberRepository::class);
            $allMemberIds = array_unique(array_merge(
                $intergroupMeeting->getGroupAttendees(),
                $intergroupMeeting->getOfficersAttending()
            ));
            $memberCache = $this->batchGetMembers($memberRepo, array_filter($allMemberIds));

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->transformIntergroupMeetingWithCache($intergroupMeeting, $memberCache),
            ], 200);

        } catch (\Exception $e) {
            $this->auditLogger->log(
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
     * Register a group as an attendee of an intergroup meeting
     */
    public function registerIntergroupMeetingAttendee(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $meetingId = (int) $request->get_param('id');
        $groupId = (int) $request->get_param('group_id');
        $memberId = (int) $request->get_param('member_id');
        $gsrName = (string) $request->get_param('gsr_name');
        $gsrProxy = (bool) $request->get_param('gsr_proxy');
        $gsrProxyName = (string) $request->get_param('gsr_proxy_name');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $groupRepo = $container->get(GroupRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingGroupAttendanceRepository::class);
            $attendanceFactory = $container->get(
                'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingGroupAttendanceFactory'
            );

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->find($meetingId);

            if (!$intergroupMeeting) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
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

            // Validate group exists and look up the group name
            $group = $groupRepo->findById($groupId);

            if (!$group) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
                    404,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'group_not_found',
                        'message' => 'Group not found',
                    ],
                ], 404);
            }

            $meetingGroup = $group->getTitle();

            // Check if group is already registered (DB-level check via unique index)
            if ($attendanceRepo->existsForMeetingAndGroup($meetingId, $groupId)) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
                    409,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'already_registered',
                        'message' => 'Group is already registered for this intergroup meeting',
                    ],
                ], 409);
            }

            // Create the attendance record in the custom table first.
            // The UNIQUE constraint on (intergroup_meeting_id, group_id) is the
            // authoritative guard against duplicates — if a concurrent request
            // slips past the check above, the INSERT will fail here instead of
            // creating a duplicate row.
            $attendance = $attendanceFactory->createNew(
                $meetingId,
                $groupId,
                $memberId,
                $meetingGroup,
                $gsrName,
                $gsrProxy,
                $gsrProxyName
            );

            $attendanceSaved = $attendanceRepo->save($attendance);

            if (!$attendanceSaved) {
                // Distinguish a genuine duplicate (concurrent race) from other failures
                global $wpdb;
                if ($wpdb->last_error && str_contains($wpdb->last_error, 'Duplicate entry')) {
                    $this->auditLogger->log(
                        $keyData['api_key_id'],
                        $request->get_route(),
                        $request->get_method(),
                        ['id' => $meetingId, 'group_id' => $groupId],
                        409,
                        microtime(true) - $startTime
                    );

                    return new WP_REST_Response([
                        'success' => false,
                        'error' => [
                            'code' => 'already_registered',
                            'message' => 'Group is already registered for this intergroup meeting',
                        ],
                    ], 409);
                }

                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'attendance_save_failed',
                        'message' => 'Failed to save attendance record',
                    ],
                ], 500);
            }

            // Add the group to the ACF relationship field (post meta).
            // This is done after the attendance row succeeds so the two
            // stores don't diverge on a duplicate-key rejection above.
            $intergroupMeeting->addGroupAttendee($groupId);

            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'save_failed',
                        'message' => 'Failed to register attendee',
                    ],
                ], 500);
            }

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'group_id' => $groupId],
                201,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'intergroup_meeting_id' => $meetingId,
                    'group_id' => $groupId,
                    'member_id' => $memberId,
                    'meeting_group' => $meetingGroup,
                    'gsr_name' => $gsrName,
                    'gsr_proxy' => $gsrProxy,
                    'gsr_proxy_name' => $gsrProxyName,
                    'registered' => true,
                ],
            ], 201);

        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: registerIntergroupMeetingAttendee error: ' . $e->getMessage());
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: Stack trace: ' . $e->getTraceAsString());

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'group_id' => $groupId],
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
    public function unregisterIntergroupMeetingAttendee(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $meetingId = (int) $request->get_param('id');
        $groupId = (int) $request->get_param('group_id');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingGroupAttendanceRepository::class);

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->find($meetingId);

            if (!$intergroupMeeting) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
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

            // Check if group is actually registered
            if (!$intergroupMeeting->hasGroupAttendee($groupId)) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
                    404,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'not_registered',
                        'message' => 'Group is not registered for this intergroup meeting',
                    ],
                ], 404);
            }

            // Remove the group
            $intergroupMeeting->removeGroupAttendee($groupId);

            // Save the updated intergroup meeting
            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'group_id' => $groupId],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'save_failed',
                        'message' => 'Failed to unregister attendee',
                    ],
                ], 500);
            }

            // Delete the attendance record for this group at this meeting
            $attendanceRepo->deleteByIntergroupMeetingAndGroup($meetingId, $groupId);

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'group_id' => $groupId],
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'intergroup_meeting_id' => $meetingId,
                    'group_id' => $groupId,
                    'registered' => false,
                ],
            ], 200);

        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: unregisterIntergroupMeetingAttendee error: ' . $e->getMessage());
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: Stack trace: ' . $e->getTraceAsString());

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'group_id' => $groupId],
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
     * Register an officer as an attendee of an intergroup meeting
     */
    public function registerIntergroupMeetingOfficer(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $meetingId = (int) $request->get_param('id');
        $officerId = (int) $request->get_param('officer_id');
        $positionName = (string) $request->get_param('position_name');
        $officerName = (string) $request->get_param('officer_name');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $memberRepo = $container->get(MemberRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingOfficerAttendanceRepository::class);
            $attendanceFactory = $container->get(
                'Unity\\IntergroupMeetings\\Interfaces\\IntergroupMeetingOfficerAttendanceFactory'
            );

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->find($meetingId);

            if (!$intergroupMeeting) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
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

            // Validate officer (member) exists
            $member = $memberRepo->find($officerId);

            if (!$member) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
                    404,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'officer_not_found',
                        'message' => 'Officer not found',
                    ],
                ], 404);
            }

            // Check if officer is already registered (DB-level check via unique index)
            if ($attendanceRepo->existsForMeetingAndOfficer($meetingId, $officerId)) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
                    409,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'already_registered',
                        'message' => 'Officer is already registered for this intergroup meeting',
                    ],
                ], 409);
            }

            // Create the attendance record in the custom table first.
            // The UNIQUE constraint on (intergroup_meeting_id, officer_id) is the
            // authoritative guard against duplicates — if a concurrent request
            // slips past the check above, the INSERT will fail here instead of
            // creating a duplicate row.
            $attendance = $attendanceFactory->createNew(
                $meetingId,
                $officerId,
                $positionName,
                $officerName
            );

            $attendanceSaved = $attendanceRepo->save($attendance);

            if (!$attendanceSaved) {
                // Distinguish a genuine duplicate (concurrent race) from other failures
                global $wpdb;
                if ($wpdb->last_error && str_contains($wpdb->last_error, 'Duplicate entry')) {
                    $this->auditLogger->log(
                        $keyData['api_key_id'],
                        $request->get_route(),
                        $request->get_method(),
                        ['id' => $meetingId, 'officer_id' => $officerId],
                        409,
                        microtime(true) - $startTime
                    );

                    return new WP_REST_Response([
                        'success' => false,
                        'error' => [
                            'code' => 'already_registered',
                            'message' => 'Officer is already registered for this intergroup meeting',
                        ],
                    ], 409);
                }

                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'attendance_save_failed',
                        'message' => 'Failed to save officer attendance record',
                    ],
                ], 500);
            }

            // Add the officer to the ACF relationship field (post meta).
            // This is done after the attendance row succeeds so the two
            // stores don't diverge on a duplicate-key rejection above.
            $intergroupMeeting->addOfficerAttendee($officerId);

            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'save_failed',
                        'message' => 'Failed to register officer',
                    ],
                ], 500);
            }

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'officer_id' => $officerId],
                201,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'intergroup_meeting_id' => $meetingId,
                    'officer_id' => $officerId,
                    'officer_name' => $officerName,
                    'position_name' => $positionName,
                    'registered' => true,
                ],
            ], 201);

        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: registerIntergroupMeetingOfficer error: ' . $e->getMessage());
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: Stack trace: ' . $e->getTraceAsString());

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'officer_id' => $officerId],
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
     * Unregister an officer from an intergroup meeting
     */
    public function unregisterIntergroupMeetingOfficer(WP_REST_Request $request): WP_REST_Response
    {
        $startTime = $request->get_param('_integrity_start_time');
        $keyData = $request->get_param('_integrity_key_data');
        $meetingId = (int) $request->get_param('id');
        $officerId = (int) $request->get_param('officer_id');

        try {
            $container = Plugin::getContainer();
            $intergroupMeetingRepo = $container->get(IntergroupMeetingRepository::class);
            $attendanceRepo = $container->get(IntergroupMeetingOfficerAttendanceRepository::class);

            // Validate intergroup meeting exists
            $intergroupMeeting = $intergroupMeetingRepo->find($meetingId);

            if (!$intergroupMeeting) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
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

            // Check if officer is actually registered
            if (!$intergroupMeeting->hasOfficerAttendee($officerId)) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
                    404,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'not_registered',
                        'message' => 'Officer is not registered for this intergroup meeting',
                    ],
                ], 404);
            }

            // Remove the officer
            $intergroupMeeting->removeOfficerAttendee($officerId);

            // Save the updated intergroup meeting
            $saved = $intergroupMeetingRepo->save($intergroupMeeting);

            if (!$saved) {
                $this->auditLogger->log(
                    $keyData['api_key_id'],
                    $request->get_route(),
                    $request->get_method(),
                    ['id' => $meetingId, 'officer_id' => $officerId],
                    500,
                    microtime(true) - $startTime
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => [
                        'code' => 'save_failed',
                        'message' => 'Failed to unregister officer',
                    ],
                ], 500);
            }

            // Delete the attendance record for this officer at this meeting
            $attendanceRepo->deleteByIntergroupMeetingAndOfficer($meetingId, $officerId);

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'officer_id' => $officerId],
                200,
                microtime(true) - $startTime
            );

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'intergroup_meeting_id' => $meetingId,
                    'officer_id' => $officerId,
                    'registered' => false,
                ],
            ], 200);

        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: unregisterIntergroupMeetingOfficer error: ' . $e->getMessage());
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Integrity: Stack trace: ' . $e->getTraceAsString());

            $this->auditLogger->log(
                $keyData['api_key_id'],
                $request->get_route(),
                $request->get_method(),
                ['id' => $meetingId, 'officer_id' => $officerId],
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
    public function healthCheck(WP_REST_Request $request): WP_REST_Response
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
     * @param Group $group
     * @param array $expand Array of fields to expand (e.g., ['meetings'])
     * @return array
     */
    private function transformGroup(Group $group, array $expand = []): array
    {
        $contacts = $group->getContacts();
        $meetings = $group->getMeetings();

        // Check if meetings should be expanded with full data
        $expandMeetings = in_array('meetings', $expand, true);

        if ($expandMeetings) {
            // Return full meeting data
            $meetingData = array_map([$this, 'transformMeeting'], $meetings);
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
            'group_email' => $group->getEmail(),
            'district_id' => $group->getDistrictId(),
            'last_contact' => $group->getLastContact(),
            $expandMeetings ? 'meetings' : 'meeting_ids' => $meetingData,
            'contacts' => !empty($contacts) ? array_map([$this, 'transformContact'], $contacts) : [],
            'contribution_options' => [
                'venmo' => $group->getVenmo(),
                'paypal' => $group->getPaypal(),
                'square' => $group->getSquare(),
                'has_options' => $group->hasContributionOptions(),
            ],
            'updated' => $this->formatUpdatedTimestamp($group->getUpdated()),
        ];
    }

    /**
     * Transform a Meeting object to API response format
     */
    private function transformMeeting(Meeting $meeting): array
    {
        $contacts = $meeting->getContacts();
        $location = $meeting->getLocation();

        return [
            'id' => $meeting->getId(),
            'name' => $meeting->getName(),
            'slug' => $meeting->getSlug(),
            'location' => $location !== null ? $this->transformLocation($location) : null,
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
            'contacts' => !empty($contacts) ? array_map([$this, 'transformContact'], $contacts) : [],
            'meta' => $meeting->getMeta(),
            'updated' => $this->formatUpdatedTimestamp($meeting->getUpdated()),
        ];
    }

    /**
     * Transform a Location object to API response format
     *
     * @param Location $location
     * @return array
     */
    private function transformLocation(Location $location): array
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
     * Transform a Contact object to API response format
     *
     * @param Contact|array $contact
     * @return array
     */
    private function transformContact($contact): array
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
     * Transform a Position object to API response format
     *
     * @param Position $position
     * @return array
     */
    private function transformPosition(Position $position): array
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
            'updated' => $this->formatUpdatedTimestamp($position->getUpdated()),
        ];
    }

    /**
     * Transform a Member object to API response format using cached entities
     *
     * @param Member $member
     * @param array<int, Group> $groupCache
     * @param array<int, Position> $positionCache
     * @param array<int, Meeting> $meetingCache
     * @return array
     */
    private function transformMemberWithCache(
        Member $member,
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
            'anonymous_name' => $member->getAnonymousName(),
            'personal_email' => Mask::email($member->getPersonalEmail()),
            'mobile_number' => Mask::phone($member->getMobileNumber()),
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
            'updated' => $this->formatUpdatedTimestamp($member->getUpdated()),
        ];
    }

    /**
     * Batch fetch members by IDs using repository
     *
     * @param MemberRepository $memberRepo
     * @param array<int> $memberIds
     * @return array<int, Member> Map of member ID to member object
     */
    private function batchGetMembers(MemberRepository $memberRepo, array $memberIds): array
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
     * @param GroupRepository $groupRepo
     * @param array<int> $groupIds
     * @return array<int, Group> Map of group ID to group object
     */
    private function batchGetGroups(GroupRepository $groupRepo, array $groupIds): array
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
     * @param PositionRepository $positionRepo
     * @param array<int> $positionIds
     * @return array<int, Position> Map of position ID to position object
     */
    private function batchGetPositions(PositionRepository $positionRepo, array $positionIds): array
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
     * @param MeetingRepository $meetingRepo
     * @param array<int> $meetingIds
     * @return array<int, Meeting> Map of meeting ID to meeting object
     */
    private function batchGetMeetings(MeetingRepository $meetingRepo, array $meetingIds): array
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
     * @param IntergroupMeeting $intergroupMeeting
     * @param array<int, Member> $memberCache
     * @return array
     */
    private function transformIntergroupMeetingWithCache(IntergroupMeeting $intergroupMeeting, array $memberCache): array
    {
        $groupAttendeeIds = $intergroupMeeting->getGroupAttendees();
        $groupAttendees = [];

        // Resolve group names from the group CPT
        foreach ($groupAttendeeIds as $groupId) {
            $groupTitle = get_the_title($groupId);
            $groupAttendees[] = [
                'id' => $groupId,
                'name' => is_string($groupTitle) ? $groupTitle : '',
            ];
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
            'title' => $intergroupMeeting->getTitle(),
            'date' => $intergroupMeeting->getDate(),
            'group_attendee_ids' => $groupAttendeeIds,
            'group_attendees' => $groupAttendees,
            'officers_attending_ids' => $officersAttendingIds,
            'officers_attending' => $officersAttending,
            'attending_groups' => $groupAttendeeIds,
            'attending_officers' => $officersAttendingIds,
            'updated' => $this->formatUpdatedTimestamp($intergroupMeeting->getUpdated()),
        ];
    }

    /**
     * Detect whether an email value is obscured (contains consecutive underscores)
     *
     * Masked emails use underscores to replace characters, e.g. "j___@e_____.com".
     * A real email address would not contain two or more consecutive underscores.
     *
     * @param string $value The submitted email value
     * @return bool True if the value appears to be obscured
     */
    private function isObscuredEmail(string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Two or more consecutive underscores indicate an obscured email
        return (bool) preg_match('/__+/', $value);
    }

    /**
     * Detect whether a phone number value is obscured (contains consecutive asterisks)
     *
     * Masked phone numbers use asterisks to replace digits, e.g. "***-***-1234".
     * A real phone number would not contain two or more consecutive asterisks.
     *
     * @param string $value The submitted phone value
     * @return bool True if the value appears to be obscured
     */
    private function isObscuredPhone(string $value): bool
    {
        if (empty($value)) {
            return false;
        }

        // Two or more consecutive asterisks indicate an obscured phone number
        return (bool) preg_match('/\*{2,}/', $value);
    }

    /**
     * Format a WordPress datetime string to ISO 8601 UTC with milliseconds
     *
     * Converts values like "2025-03-09 14:30:00" (WordPress post_modified_gmt)
     * to "2025-03-09T14:30:00.000Z".
     *
     * Returns an empty string when the input is empty or unparseable.
     *
     * @param string $datetime The datetime string to format
     * @return string Formatted as YYYY-MM-DDTHH:mm:ss.fffZ or empty string
     */
    private function formatUpdatedTimestamp(string $datetime): string
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


}
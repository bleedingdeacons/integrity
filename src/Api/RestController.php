<?php

declare(strict_types=1);

namespace Integrity\Api;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Api\Controllers\GroupController;
use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Api\Controllers\MemberController;
use Integrity\Api\Controllers\PositionController;
use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\RateLimiter;
use Integrity\Auth\AuditLogger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API Controller
 *
 * Coordinates route registration, authentication, rate limiting, and audit
 * logging. Business logic for each resource lives in dedicated controllers
 * under the Integrity\Api\Controllers namespace.
 *
 * Dependencies are injected via constructor following the Scrutiny DI pattern.
 */
class RestController
{
    private const NAMESPACE = 'integrity/v1';

    private ApiKeyManager $apiKeyManager;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    private GroupController $groupController;
    private MeetingController $meetingController;
    private PositionController $positionController;
    private MemberController $memberController;
    private IntergroupMeetingController $intergroupMeetingController;

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

        // Build domain controllers
        $this->groupController = new GroupController($auditLogger);
        $this->meetingController = new MeetingController($auditLogger);
        $this->positionController = new PositionController($auditLogger);
        $this->memberController = new MemberController(
            $auditLogger,
            $this->groupController,
            $this->positionController,
            $this->meetingController
        );
        $this->intergroupMeetingController = new IntergroupMeetingController($auditLogger);
    }

    /**
     * Register REST API routes.
     */
    public function register(): void
    {
        // Groups endpoints
        register_rest_route(self::NAMESPACE, '/groups', [
            'methods' => 'GET',
            'callback' => [$this->groupController, 'getGroups'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->groupController->getGroupsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/groups/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this->groupController, 'getGroup'],
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
            'callback' => [$this->meetingController, 'getMeetings'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->meetingController->getMeetingsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/meetings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this->meetingController, 'getMeeting'],
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
            'callback' => [$this->positionController, 'getPositions'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->positionController->getPositionsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/positions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this->positionController, 'getPosition'],
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
            'callback' => [$this->memberController, 'getMembers'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->memberController->getMembersArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/members/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this->memberController, 'getMember'],
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
            'callback' => [$this->memberController, 'updateMember'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->memberController->getUpdateMemberArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/members/create', [
            'methods' => 'POST',
            'callback' => [$this->memberController, 'createMember'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->memberController->getCreateMemberArgs(),
        ]);

        // Intergroup Meetings endpoints
        register_rest_route(self::NAMESPACE, '/intergroup-meetings', [
            'methods' => 'GET',
            'callback' => [$this->intergroupMeetingController, 'getIntergroupMeetings'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getIntergroupMeetingsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this->intergroupMeetingController, 'getIntergroupMeeting'],
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
            'callback' => [$this->intergroupMeetingController, 'registerIntergroupMeetingAttendee'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getRegisterAttendeeArgs(),
        ]);

        // Intergroup Meeting Group Unregister endpoint
        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/unregister-group', [
            'methods' => 'POST',
            'callback' => [$this->intergroupMeetingController, 'unregisterIntergroupMeetingAttendee'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getUnregisterAttendeeArgs(),
        ]);

        // Intergroup Meeting Officer Registration endpoint
        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/register-officer', [
            'methods' => 'POST',
            'callback' => [$this->intergroupMeetingController, 'registerIntergroupMeetingOfficer'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getRegisterOfficerArgs(),
        ]);

        // Intergroup Meeting Officer Unregister endpoint
        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/unregister-officer', [
            'methods' => 'POST',
            'callback' => [$this->intergroupMeetingController, 'unregisterIntergroupMeetingOfficer'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getUnregisterOfficerArgs(),
        ]);

        // Health check endpoint (no auth required)
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'healthCheck'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Check permission and authenticate request.
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
     * Health check endpoint.
     */
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
     * Extract API key from request.
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
     * Get required permission for an endpoint.
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
     * Log a failed request.
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
}
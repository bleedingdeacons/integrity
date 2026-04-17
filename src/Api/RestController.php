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
use Integrity\Logger\HasLogger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST API Controller
 *
 * Thin routing layer that handles authentication, rate limiting, and audit
 * logging, then delegates request handling to resource-specific controllers.
 *
 * Dependencies are injected via constructor following the Scrutiny DI pattern.
 *
 * Resource controllers:
 * - GroupController              → /groups
 * - MeetingController            → /meetings
 * - PositionController           → /positions
 * - MemberController             → /members
 * - IntergroupMeetingController  → /intergroup-meetings
 */
class RestController
{
    use HasLogger;

    private const NAMESPACE = 'integrity/v1';

    private ApiKeyManager $apiKeyManager;
    private AuditLogger $auditLogger;
    private RateLimiter $rateLimiter;

    // Resource controllers
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
        RateLimiter $rateLimiter,
        GroupController $groupController,
        MeetingController $meetingController,
        PositionController $positionController,
        MemberController $memberController,
        IntergroupMeetingController $intergroupMeetingController
    ) {
        $this->apiKeyManager = $apiKeyManager;
        $this->auditLogger = $auditLogger;
        $this->rateLimiter = $rateLimiter;
        $this->groupController = $groupController;
        $this->meetingController = $meetingController;
        $this->positionController = $positionController;
        $this->memberController = $memberController;
        $this->intergroupMeetingController = $intergroupMeetingController;
    }

    // ── Route Registration ──────────────────────────────────────────────

    /**
     * Register REST API routes.
     *
     * Each route delegates to the appropriate resource controller for
     * argument definitions and request handling.
     */
    public function register(): void
    {
        $this->registerGroupRoutes();
        $this->registerMeetingRoutes();
        $this->registerPositionRoutes();
        $this->registerMemberRoutes();
        $this->registerIntergroupMeetingRoutes();
        $this->registerHealthRoute();
    }

    private function registerGroupRoutes(): void
    {
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
    }

    private function registerMeetingRoutes(): void
    {
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
    }

    private function registerPositionRoutes(): void
    {
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
    }

    private function registerMemberRoutes(): void
    {
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
    }

    private function registerIntergroupMeetingRoutes(): void
    {
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

        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/register-group', [
            'methods' => 'POST',
            'callback' => [$this->intergroupMeetingController, 'registerIntergroupMeetingAttendee'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getRegisterAttendeeArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/unregister-group', [
            'methods' => 'POST',
            'callback' => [$this->intergroupMeetingController, 'unregisterIntergroupMeetingAttendee'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getUnregisterAttendeeArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/register-officer', [
            'methods' => 'POST',
            'callback' => [$this->intergroupMeetingController, 'registerIntergroupMeetingOfficer'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getRegisterOfficerArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/intergroup-meetings/(?P<id>\d+)/unregister-officer', [
            'methods' => 'POST',
            'callback' => [$this->intergroupMeetingController, 'unregisterIntergroupMeetingOfficer'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->intergroupMeetingController->getUnregisterOfficerArgs(),
        ]);
    }

    private function registerHealthRoute(): void
    {
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [$this, 'healthCheck'],
            'permission_callback' => '__return_true',
        ]);
    }

    // ── Authentication & Rate Limiting ──────────────────────────────────

    /**
     * Check permission and authenticate request.
     *
     * This is the single permission_callback for all authenticated routes.
     * It validates the API key, enforces rate limits, checks endpoint-level
     * permissions, and stores request context for use by controllers.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function checkPermission(WP_REST_Request $request)
    {
        $startTime = microtime(true);

        // Base context attached to every auth log line for this request.
        $route     = $request->get_route();
        $method    = $request->get_method();
        $clientIp  = $this->auditLogger->getClientIp();
        $userAgent = $request->get_header('User-Agent');

        $baseContext = [
            'route'      => $route,
            'method'     => $method,
            'client_ip'  => $clientIp,
            'user_agent' => $userAgent,
            'headers'    => $this->collectRequestHeaders($request),
        ];

        self::logDebug('Auth check started', $baseContext);

        // Require HTTPS in production
        if (get_option('integrity_require_https', true) && !is_ssl() && !(defined('WP_DEBUG') && WP_DEBUG)) {
            self::logWarning('Auth rejected: HTTPS required but request was not secure', $baseContext);
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
            self::logWarning('Auth rejected: no API key in Authorization or X-API-Key header', $baseContext + [
                    'has_authorization_header' => $request->get_header('Authorization') !== null,
                    'has_x_api_key_header'     => $request->get_header('X-API-Key') !== null,
                ]);
            $this->logFailedRequest($request, 401, $startTime);
            return new WP_Error(
                'missing_api_key',
                'API key is required. Provide it in the Authorization header as: Bearer <api_key>',
                ['status' => 401]
            );
        }

        // Fingerprint the key for logging — never log the raw secret.
        $keyFingerprint = substr(hash('sha256', $apiKey), 0, 8);
        $baseContext['key_fingerprint'] = $keyFingerprint;

        self::logDebug('API key extracted, validating', $baseContext);

        // Validate API key
        $keyData = $this->apiKeyManager->validateKey($apiKey, $clientIp);

        if (!$keyData) {
            self::logWarning('Auth rejected: API key invalid, expired, or IP not allowlisted', $baseContext);
            $this->logFailedRequest($request, 401, $startTime);
            return new WP_Error(
                'invalid_api_key',
                'Invalid or expired API key',
                ['status' => 401]
            );
        }

        // Check rate limits (cast to int as database returns strings)
        $apiKeyId  = (int) $keyData['id'];
        $rateLimit = (int) $keyData['rate_limit'];

        $baseContext['api_key_id'] = $apiKeyId;
        self::logDebug('API key validated, checking rate limit', $baseContext + [
                'rate_limit' => $rateLimit,
            ]);

        $rateLimitResult = $this->rateLimiter->checkAndIncrement($apiKeyId, $rateLimit);

        if (!$rateLimitResult['allowed']) {
            self::logNotice('Auth rejected: rate limit exceeded', $baseContext + [
                    'rate_limit' => $rateLimit,
                    'remaining'  => $rateLimitResult['remaining'] ?? 0,
                    'reset'      => $rateLimitResult['reset'] ?? null,
                ]);
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

        // Check endpoint-specific permissions
        $requiredPermission = $this->getRequiredPermission($route);

        if ($requiredPermission && !in_array($requiredPermission, $keyData['permissions'], true) && !in_array('*', $keyData['permissions'], true)) {
            self::logWarning('Auth rejected: insufficient permissions for endpoint', $baseContext + [
                    'required_permission' => $requiredPermission,
                    'granted_permissions' => $keyData['permissions'],
                ]);
            $this->logFailedRequest($request, 403, $startTime, $apiKeyId);
            return new WP_Error(
                'insufficient_permissions',
                "This API key does not have permission to access: {$requiredPermission}",
                ['status' => 403]
            );
        }

        // Store key data for use in controller callbacks (cast id to int for type safety)
        $keyData['api_key_id'] = $apiKeyId;
        $request->set_param('_integrity_key_data', $keyData);
        $request->set_param('_integrity_start_time', $startTime);
        $request->set_param('_integrity_rate_limit', $rateLimitResult);

        // Add rate limit headers to successful responses
        $this->attachRateLimitFilter($rateLimit, $rateLimitResult);

        self::logInfo('Auth succeeded', $baseContext + [
                'required_permission' => $requiredPermission,
                'rate_limit_remaining' => $rateLimitResult['remaining'] ?? null,
                'duration_ms'          => (int) round((microtime(true) - $startTime) * 1000),
            ]);

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
     * @param array $rateLimitResult Result from RateLimiter::checkAndIncrement()
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
     * Collect request headers for logging, exposing only a small allowlist
     * of non-sensitive headers useful for debugging.
     *
     * An allowlist is used (rather than a denylist of sensitive headers)
     * so that credential-bearing headers introduced by future integrations
     * — or uncommon variants like X-Auth-Token, X-Forwarded-Authorization,
     * X-Amz-Security-Token — cannot silently leak into logs. Anything not
     * on the list is replaced with "[REDACTED]".
     *
     * Note: WP_REST_Request::get_headers() returns header names lowercased
     * with hyphens converted to underscores (e.g. "X-API-Key" becomes
     * "x_api_key"). The allowlist is keyed in that normalised form.
     *
     * @return array<string, string>
     */
    private function collectRequestHeaders(WP_REST_Request $request): array
    {
        static $allowlist = [
            'accept'            => true,
            'accept_encoding'   => true,
            'accept_language'   => true,
            'content_length'    => true,
            'content_type'      => true,
            'host'              => true,
            'origin'            => true,
            'referer'           => true,
            'user_agent'        => true,
            'x_forwarded_for'   => true,
            'x_forwarded_host'  => true,
            'x_forwarded_proto' => true,
            'x_real_ip'         => true,
            'x_request_id'      => true,
        ];

        $out = [];
        foreach ($request->get_headers() as $name => $values) {
            $joined = is_array($values) ? implode(', ', $values) : (string) $values;
            $key = strtolower((string) $name);
            // Defensive: normalise hyphens in case upstream ever changes behaviour.
            $key = str_replace('-', '_', $key);

            if (isset($allowlist[$key])) {
                $out[$name] = $joined;
            } else {
                $out[$name] = '[REDACTED]';
            }
        }
        return $out;
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

    // ── Health Check ────────────────────────────────────────────────────

    /**
     * Health check endpoint (no auth required).
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
}
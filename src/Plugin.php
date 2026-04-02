<?php

declare(strict_types=1);

namespace Integrity;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Integrity\Admin\SettingsPage;
use Integrity\Api\Controllers\GroupController;
use Integrity\Api\Controllers\IntergroupMeetingController;
use Integrity\Api\Controllers\MeetingController;
use Integrity\Api\Controllers\MemberController;
use Integrity\Api\Controllers\PositionController;
use Integrity\Api\RestController;
use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\AuditLogger;
use Integrity\Auth\RateLimiter;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Unity\Core\Interfaces\Container;

use function add_action;
use function is_admin;

/**
 * Main Plugin Class
 *
 * Follows the same DI pattern as Scrutiny: receives Unity's container,
 * registers Integrity services into it, and resolves them on init.
 */
class Plugin
{
    use \Integrity\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'integrity';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Initialize the plugin
     *
     * @param Container $unityContainer The Unity dependency container
     */
    public static function init(Container $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;
        self::registerServices($unityContainer);
        self::$initialized = true;

        // Register REST API routes (must resolve the instance so hooks are registered)
        add_action('rest_api_init', function () {
            self::$container->get(RestController::class)->register();
        });

        // Initialize admin
        if (is_admin()) {
            self::$container->get(SettingsPage::class)->init();
        }

        // Add security headers
        add_action('rest_api_init', [self::class, 'addSecurityHeaders']);

        self::logDebug('Initialised', ['version' => defined('INTEGRITY_VERSION') ? INTEGRITY_VERSION : 'unknown']);

    }

    /**
     * Register all Integrity services in Unity's container
     *
     * @param Container $container
     */
    private static function registerServices(Container $container): void
    {
        // ── Auth services ───────────────────────────────────────────────

        $container->register(ApiKeyManager::class, function () {
            return new ApiKeyManager();
        });

        $container->register(AuditLogger::class, function () {
            return new AuditLogger();
        });

        $container->register(RateLimiter::class, function () {
            return new RateLimiter();
        });

        // ── Resource controllers ────────────────────────────────────────

        $container->register(GroupController::class, function (ContainerInterface $c) {
            return new GroupController(
                $c->get(AuditLogger::class)
            );
        });

        $container->register(MeetingController::class, function (ContainerInterface $c) {
            return new MeetingController(
                $c->get(AuditLogger::class)
            );
        });

        $container->register(PositionController::class, function (ContainerInterface $c) {
            return new PositionController(
                $c->get(AuditLogger::class)
            );
        });

        $container->register(MemberController::class, function (ContainerInterface $c) {
            return new MemberController(
                $c->get(AuditLogger::class),
                $c->get(GroupController::class),
                $c->get(PositionController::class),
                $c->get(MeetingController::class)
            );
        });

        $container->register(IntergroupMeetingController::class, function (ContainerInterface $c) {
            return new IntergroupMeetingController(
                $c->get(AuditLogger::class)
            );
        });

        // ── REST Controller (router) ────────────────────────────────────

        $container->register(RestController::class, function (ContainerInterface $c) {
            return new RestController(
                $c->get(ApiKeyManager::class),
                $c->get(AuditLogger::class),
                $c->get(RateLimiter::class),
                $c->get(GroupController::class),
                $c->get(MeetingController::class),
                $c->get(PositionController::class),
                $c->get(MemberController::class),
                $c->get(IntergroupMeetingController::class)
            );
        });

        // ── Admin ───────────────────────────────────────────────────────

        $container->register(SettingsPage::class, function (ContainerInterface $c) {
            return new SettingsPage(
                $c->get(ApiKeyManager::class),
                $c->get(AuditLogger::class)
            );
        });
    }

    /**
     * Add security headers to REST API responses
     */
    public static function addSecurityHeaders(): void
    {
        add_filter('rest_pre_serve_request', function ($served, $result, $request) {
            if (strpos($request->get_route(), '/integrity/') === 0) {
                header('X-Content-Type-Options: nosniff');
                header('X-Frame-Options: DENY');
                header('X-XSS-Protection: 1; mode=block');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }
            return $served;
        }, 10, 3);
    }

    /**
     * Get the dependency container
     *
     * @return ContainerInterface
     * @throws RuntimeException If plugin is not initialized
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Integrity Plugin not initialized');
        }
        return self::$container;
    }
}
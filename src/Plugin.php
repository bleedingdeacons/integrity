<?php

declare(strict_types=1);

namespace Integrity;

use Integrity\Api\RestController;
use Integrity\Admin\SettingsPage;

/**
 * Main Plugin Class
 */
class Plugin
{
    private static bool $initialized = false;

    /**
     * Initialize the plugin
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        // Initialize REST API
        add_action('rest_api_init', [RestController::class, 'register']);

        // Initialize admin
        if (is_admin()) {
            SettingsPage::init();
        }

        // Add security headers
        add_action('rest_api_init', [self::class, 'addSecurityHeaders']);
    }

    /**
     * Add security headers to REST API responses
     */
    public static function addSecurityHeaders(): void
    {
        // Only apply to our namespace
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
}

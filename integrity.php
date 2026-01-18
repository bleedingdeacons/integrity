<?php

declare(strict_types=1);

/**
 * Plugin Name: Integrity
 * Plugin URI: https://example.com/integrity
 * Description: Secure REST API bridge for Unity plugin - provides authenticated access to Groups and Meetings for external applications.
 * Version: 1.2.2
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: The Bleeding Deacons
 * Author URI: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */


// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$integrity_plugin_data = get_plugin_data(__FILE__, false, false);
define('INTEGRITY_VERSION', $integrity_plugin_data['Version']);
define('INTEGRITY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('INTEGRITY_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader for Integrity namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Integrity\\';
        $base_dir = INTEGRITY_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        error_log('Integrity Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        error_log('Integrity Autoloader Fatal Error: ' . $e->getMessage());
    }
});

// Initialize plugin after Unity is fully loaded
add_action('unity_loaded', function ($container): void {
    try {
        // Initialize Integrity
        \Integrity\Plugin::init();

        /**
         * Fires after Integrity is fully loaded.
         * Use this hook for code that depends on Integrity being available.
         */
        do_action('integrity_loaded');

    } catch (\Exception $e) {
        error_log('Integrity Plugin Initialization Error: ' . $e->getMessage());
        error_log('Integrity Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                $message = sprintf(
                    '<strong>Integrity Plugin Error:</strong> %s',
                    esc_html($e->getMessage())
                );
                echo '<div class="notice notice-error is-dismissible"><p>' . $message . '</p></div>';
            });
        }

    } catch (\Throwable $e) {
        error_log('Integrity Plugin Fatal Error: ' . $e->getMessage());
        error_log('Integrity Plugin Stack Trace: ' . $e->getTraceAsString());

        if (is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Integrity Plugin Fatal Error:</strong> Plugin failed to load. Check error logs.</p></div>';
            });
        }
    }
});

// Show error if Unity is not active (check after plugins_loaded)
add_action('plugins_loaded', function (): void {
    if (!class_exists('Unity\\Plugin')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo '<strong>Integrity Plugin Error:</strong> ';
            echo esc_html__('Integrity requires the Unity plugin to be installed and activated.', 'integrity');
            echo '</p></div>';
        });
    }
}, 20); // Priority 20 to check after Unity would have loaded

// Activation hook
register_activation_hook(__FILE__, function (): void {
    // Create API keys table
    global $wpdb;
    $tableName = $wpdb->prefix . 'integrity_api_keys';
    $charsetCollate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $tableName (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        api_key_hash varchar(255) NOT NULL,
        api_key_prefix varchar(8) NOT NULL,
        permissions text NOT NULL,
        rate_limit int(11) NOT NULL DEFAULT 1000,
        last_used datetime DEFAULT NULL,
        request_count bigint(20) unsigned NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        expires_at datetime DEFAULT NULL,
        is_active tinyint(1) NOT NULL DEFAULT 1,
        created_by bigint(20) unsigned NOT NULL,
        ip_whitelist text DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY api_key_hash (api_key_hash),
        KEY api_key_prefix (api_key_prefix),
        KEY is_active (is_active)
    ) $charsetCollate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Create rate limiting table
    $rateLimitTable = $wpdb->prefix . 'integrity_rate_limits';
    $sql = "CREATE TABLE IF NOT EXISTS $rateLimitTable (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_key_id bigint(20) unsigned NOT NULL,
        window_start datetime NOT NULL,
        request_count int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY api_key_window (api_key_id, window_start),
        KEY window_start (window_start)
    ) $charsetCollate;";
    dbDelta($sql);

    // Create audit log table
    $auditTable = $wpdb->prefix . 'integrity_audit_log';
    $sql = "CREATE TABLE IF NOT EXISTS $auditTable (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        api_key_id bigint(20) unsigned DEFAULT NULL,
        endpoint varchar(255) NOT NULL,
        method varchar(10) NOT NULL,
        ip_address varchar(45) NOT NULL,
        user_agent text,
        request_params text,
        response_code int(11) NOT NULL,
        response_time float NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY api_key_id (api_key_id),
        KEY created_at (created_at),
        KEY ip_address (ip_address)
    ) $charsetCollate;";
    dbDelta($sql);

    // Set default options
    add_option('integrity_enable_audit_log', true);
    add_option('integrity_audit_log_retention_days', 90);
    add_option('integrity_default_rate_limit', 1000);
    add_option('integrity_require_https', true);

    // Schedule cleanup cron
    if (!wp_next_scheduled('integrity_cleanup_cron')) {
        wp_schedule_event(time(), 'daily', 'integrity_cleanup_cron');
    }

    // Flush rewrite rules
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function (): void {
    wp_clear_scheduled_hook('integrity_cleanup_cron');
    flush_rewrite_rules();
});

// Cleanup cron handler
add_action('integrity_cleanup_cron', function (): void {
    global $wpdb;
    
    // Clean old rate limit records
    $rateLimitTable = $wpdb->prefix . 'integrity_rate_limits';
    $wpdb->query("DELETE FROM $rateLimitTable WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    
    // Clean old audit logs based on retention setting
    $retentionDays = (int) get_option('integrity_audit_log_retention_days', 90);
    $auditTable = $wpdb->prefix . 'integrity_audit_log';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM $auditTable WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $retentionDays
    ));
});

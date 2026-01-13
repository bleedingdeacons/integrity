<?php

declare(strict_types=1);

namespace Integrity\Admin;

use Integrity\Auth\ApiKeyManager;
use Integrity\Auth\AuditLogger;

/**
 * Admin Settings Page
 * 
 * Provides WordPress admin interface for managing Integrity API keys and settings.
 */
class SettingsPage
{
    private const CAPABILITY = 'manage_options';
    private const MENU_SLUG = 'integrity-settings';
    private const NONCE_ACTION = 'integrity_admin_action';

    /**
     * Initialize admin hooks
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_integrity_create_key', [self::class, 'handleCreateKey']);
        add_action('admin_post_integrity_revoke_key', [self::class, 'handleRevokeKey']);
        add_action('admin_post_integrity_delete_key', [self::class, 'handleDeleteKey']);
        add_action('admin_post_integrity_clear_logs', [self::class, 'handleClearLogs']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueueAssets(string $hook): void
    {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        wp_enqueue_style(
            'integrity-admin',
            INTEGRITY_PLUGIN_URL . 'assets/admin.css',
            [],
            INTEGRITY_VERSION
        );
    }

    /**
     * Add menu page
     */
    public static function addMenuPage(): void
    {
        add_menu_page(
            __('Integrity API', 'integrity'),
            __('Integrity API', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'renderPage'],
            'dashicons-rest-api',
            80
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('API Keys', 'integrity'),
            __('API Keys', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'renderPage']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Audit Log', 'integrity'),
            __('Audit Log', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG . '-audit',
            [self::class, 'renderAuditPage']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'integrity'),
            __('Settings', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG . '-config',
            [self::class, 'renderSettingsPage']
        );
    }

    /**
     * Register settings
     */
    public static function registerSettings(): void
    {
        register_setting('integrity_settings', 'integrity_enable_audit_log');
        register_setting('integrity_settings', 'integrity_audit_log_retention_days');
        register_setting('integrity_settings', 'integrity_default_rate_limit');
        register_setting('integrity_settings', 'integrity_require_https');
    }

    /**
     * Render main API keys page
     */
    public static function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to access this page.', 'integrity'));
        }

        $keys = ApiKeyManager::getAllKeys();
        $newKey = get_transient('integrity_new_key_' . get_current_user_id());
        
        if ($newKey) {
            delete_transient('integrity_new_key_' . get_current_user_id());
        }

        include INTEGRITY_PLUGIN_DIR . 'templates/admin-keys.php';
    }

    /**
     * Render audit log page
     */
    public static function renderAuditPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to access this page.', 'integrity'));
        }

        $page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $perPage = 50;
        
        $filters = [
            'api_key_id' => isset($_GET['api_key_id']) ? (int) $_GET['api_key_id'] : null,
            'response_code' => isset($_GET['response_code']) ? (int) $_GET['response_code'] : null,
            'ip_address' => isset($_GET['ip_address']) ? sanitize_text_field($_GET['ip_address']) : null,
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : null,
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : null,
        ];
        
        $result = AuditLogger::getLogs(array_merge($filters, [
            'page' => $page,
            'per_page' => $perPage,
        ]));
        
        $stats = AuditLogger::getStats(30);
        $keys = ApiKeyManager::getAllKeys();

        include INTEGRITY_PLUGIN_DIR . 'templates/admin-audit.php';
    }

    /**
     * Render settings page
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to access this page.', 'integrity'));
        }

        include INTEGRITY_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    /**
     * Handle API key creation
     */
    public static function handleCreateKey(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to perform this action.', 'integrity'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $name = isset($_POST['key_name']) ? sanitize_text_field($_POST['key_name']) : '';
        
        if (empty($name)) {
            wp_redirect(add_query_arg('error', 'name_required', admin_url('admin.php?page=' . self::MENU_SLUG)));
            exit;
        }

        // Parse permissions
        $permissions = [];
        if (!empty($_POST['perm_groups'])) {
            $permissions[] = 'groups:read';
        }
        if (!empty($_POST['perm_meetings'])) {
            $permissions[] = 'meetings:read';
        }
        if (!empty($_POST['perm_all'])) {
            $permissions = ['*'];
        }

        if (empty($permissions)) {
            $permissions = ['groups:read', 'meetings:read'];
        }

        // Parse optional settings
        $rateLimit = !empty($_POST['rate_limit']) ? (int) $_POST['rate_limit'] : null;
        $expiresAt = !empty($_POST['expires_at']) ? sanitize_text_field($_POST['expires_at']) . ' 23:59:59' : null;
        
        // Parse IP whitelist
        $ipWhitelist = null;
        if (!empty($_POST['ip_whitelist'])) {
            $ips = array_map('trim', explode("\n", sanitize_textarea_field($_POST['ip_whitelist'])));
            $ips = array_filter($ips);
            if (!empty($ips)) {
                $ipWhitelist = $ips;
            }
        }

        $result = ApiKeyManager::createKey($name, $permissions, $rateLimit, $expiresAt, $ipWhitelist);

        if ($result['success']) {
            // Store the new key temporarily so we can show it once
            set_transient('integrity_new_key_' . get_current_user_id(), $result['key'], 60);
            wp_redirect(add_query_arg('created', '1', admin_url('admin.php?page=' . self::MENU_SLUG)));
        } else {
            wp_redirect(add_query_arg('error', 'create_failed', admin_url('admin.php?page=' . self::MENU_SLUG)));
        }
        exit;
    }

    /**
     * Handle API key revocation
     */
    public static function handleRevokeKey(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to perform this action.', 'integrity'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $keyId = isset($_POST['key_id']) ? (int) $_POST['key_id'] : 0;

        if ($keyId > 0 && ApiKeyManager::revokeKey($keyId)) {
            wp_redirect(add_query_arg('revoked', '1', admin_url('admin.php?page=' . self::MENU_SLUG)));
        } else {
            wp_redirect(add_query_arg('error', 'revoke_failed', admin_url('admin.php?page=' . self::MENU_SLUG)));
        }
        exit;
    }

    /**
     * Handle API key deletion
     */
    public static function handleDeleteKey(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to perform this action.', 'integrity'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $keyId = isset($_POST['key_id']) ? (int) $_POST['key_id'] : 0;

        if ($keyId > 0 && ApiKeyManager::deleteKey($keyId)) {
            wp_redirect(add_query_arg('deleted', '1', admin_url('admin.php?page=' . self::MENU_SLUG)));
        } else {
            wp_redirect(add_query_arg('error', 'delete_failed', admin_url('admin.php?page=' . self::MENU_SLUG)));
        }
        exit;
    }

    /**
     * Handle clearing audit logs
     */
    public static function handleClearLogs(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to perform this action.', 'integrity'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $olderThanDays = isset($_POST['older_than_days']) && $_POST['older_than_days'] !== '' 
            ? (int) $_POST['older_than_days'] 
            : null;
        
        $apiKeyId = isset($_POST['api_key_id']) && $_POST['api_key_id'] !== '' 
            ? (int) $_POST['api_key_id'] 
            : null;

        $deleted = AuditLogger::clearLogs($olderThanDays, $apiKeyId);

        wp_redirect(add_query_arg(
            ['logs_cleared' => $deleted],
            admin_url('admin.php?page=' . self::MENU_SLUG . '-audit')
        ));
        exit;
    }

    /**
     * Get nonce field for forms
     */
    public static function getNonceField(): string
    {
        return wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false);
    }
}

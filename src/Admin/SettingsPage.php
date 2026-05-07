<?php

declare(strict_types=1);

namespace Integrity\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

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
    private const AJAX_REFRESH_LOGS = 'integrity_audit_refresh_logs';

    /** @var string The hook suffix returned by add_submenu_page() for the audit page. */
    private string $auditHookSuffix = '';

    private ApiKeyManager $apiKeyManager;
    private AuditLogger $auditLogger;

    public function __construct(ApiKeyManager $apiKeyManager, AuditLogger $auditLogger)
    {
        $this->apiKeyManager = $apiKeyManager;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Initialize admin hooks
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_integrity_create_key', [$this, 'handleCreateKey']);
        add_action('admin_post_integrity_revoke_key', [$this, 'handleRevokeKey']);
        add_action('admin_post_integrity_delete_key', [$this, 'handleDeleteKey']);
        add_action('admin_post_integrity_clear_logs', [$this, 'handleClearLogs']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_' . self::AJAX_REFRESH_LOGS, [$this, 'ajaxRefreshLogs']);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets(string $hook): void
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

        // Audit page: enqueue the partial-refresh script.
        if ($this->auditHookSuffix !== '' && $hook === $this->auditHookSuffix) {
            wp_enqueue_script(
                'integrity-admin-audit',
                INTEGRITY_PLUGIN_URL . 'assets/admin-audit.js',
                [],
                INTEGRITY_VERSION,
                true
            );

            wp_localize_script('integrity-admin-audit', 'integrityAuditRefresh', [
                'url'    => admin_url('admin-ajax.php'),
                'action' => self::AJAX_REFRESH_LOGS,
                'nonce'  => wp_create_nonce(self::AJAX_REFRESH_LOGS),
            ]);
        }
    }

    /**
     * Add menu page
     */
    public function addMenuPage(): void
    {
        add_menu_page(
            __('Integrity API', 'integrity'),
            __('Integrity API', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage'],
            'dashicons-rest-api',
            80
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('API Keys', 'integrity'),
            __('API Keys', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );

        $this->auditHookSuffix = (string) add_submenu_page(
            self::MENU_SLUG,
            __('Audit Log', 'integrity'),
            __('Audit Log', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG . '-audit',
            [$this, 'renderAuditPage']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'integrity'),
            __('Settings', 'integrity'),
            self::CAPABILITY,
            self::MENU_SLUG . '-config',
            [$this, 'renderSettingsPage']
        );
    }

    /**
     * Register settings
     */
    public function registerSettings(): void
    {
        register_setting('integrity_settings', 'integrity_enable_audit_log');
        register_setting('integrity_settings', 'integrity_audit_log_retention_days');
        register_setting('integrity_settings', 'integrity_default_rate_limit');
        register_setting('integrity_settings', 'integrity_require_https');
        register_setting('integrity_settings', 'integrity_audit_auto_refresh_enabled');
        register_setting('integrity_settings', 'integrity_audit_auto_refresh_interval', [
            'type' => 'integer',
            'sanitize_callback' => static function ($value) {
                $value = (int) $value;
                if ($value < 5) {
                    $value = 5;
                }
                if ($value > 3600) {
                    $value = 3600;
                }
                return $value;
            },
            'default' => 30,
        ]);
    }

    /**
     * Render main API keys page
     */
    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to access this page.', 'integrity'));
        }

        $keys = $this->apiKeyManager->getAllKeys();
        $newKey = get_transient('integrity_new_key_' . get_current_user_id());

        if ($newKey) {
            delete_transient('integrity_new_key_' . get_current_user_id());
        }

        include INTEGRITY_PLUGIN_DIR . 'templates/admin-keys.php';
    }

    /**
     * Render audit log page
     */
    public function renderAuditPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to access this page.', 'integrity'));
        }

        $data = $this->getAuditPageData();

        // Extract for the template's expected scope.
        $result     = $data['result'];
        $stats      = $data['stats'];
        $keys       = $data['keys'];
        $page       = $data['page'];
        $perPage    = $data['per_page'];
        $filters    = $data['filters'];
        $totalPages = $data['total_pages'];

        include INTEGRITY_PLUGIN_DIR . 'templates/admin-audit.php';
    }

    /**
     * AJAX handler: re-render only the request log partial.
     *
     * Mirrors the sentinel LogViewerPage::ajaxRefresh pattern — the same
     * partial used for the initial page render is re-rendered into a
     * buffer and returned as JSON so the JS can swap the container's
     * innerHTML without a full page reload.
     */
    public function ajaxRefreshLogs(): void
    {
        check_ajax_referer(self::AJAX_REFRESH_LOGS, 'nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error('Unauthorized', 403);
            return;
        }

        $data = $this->getAuditPageData();

        // Variables expected by the partial template.
        $result     = $data['result'];
        $keys       = $data['keys'];
        $page       = $data['page'];
        $totalPages = $data['total_pages'];
        $baseUrl    = $data['base_url'];

        ob_start();
        include INTEGRITY_PLUGIN_DIR . 'templates/admin-audit-logs-partial.php';
        $html = (string) ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Build the data needed to render the audit page or its log partial.
     *
     * Filters and pagination come from $_GET on a normal page load, and
     * from a serialized form payload on AJAX refresh — see admin-audit.js,
     * which posts the audit page's filter form fields alongside `nonce`
     * and `action`.
     *
     * @return array{
     *     result: array{logs: array, total: int},
     *     stats: array,
     *     keys: array,
     *     page: int,
     *     per_page: int,
     *     filters: array{
     *         api_key_id: ?int,
     *         response_code: ?int,
     *         ip_address: ?string,
     *         date_from: ?string,
     *         date_to: ?string,
     *     },
     *     total_pages: int,
     *     base_url: string,
     * }
     */
    private function getAuditPageData(): array
    {
        $page    = isset($_REQUEST['paged']) ? max(1, (int) $_REQUEST['paged']) : 1;
        $perPage = 50;

        $filters = [
            'api_key_id'    => isset($_REQUEST['api_key_id']) && $_REQUEST['api_key_id'] !== ''
                ? (int) $_REQUEST['api_key_id']
                : null,
            'response_code' => isset($_REQUEST['response_code']) && $_REQUEST['response_code'] !== ''
                ? (int) $_REQUEST['response_code']
                : null,
            'ip_address'    => isset($_REQUEST['ip_address']) && $_REQUEST['ip_address'] !== ''
                ? sanitize_text_field(wp_unslash($_REQUEST['ip_address']))
                : null,
            'date_from'     => isset($_REQUEST['date_from']) && $_REQUEST['date_from'] !== ''
                ? sanitize_text_field(wp_unslash($_REQUEST['date_from']))
                : null,
            'date_to'       => isset($_REQUEST['date_to']) && $_REQUEST['date_to'] !== ''
                ? sanitize_text_field(wp_unslash($_REQUEST['date_to']))
                : null,
        ];

        $result = $this->auditLogger->getLogs(array_merge($filters, [
            'page'     => $page,
            'per_page' => $perPage,
        ]));

        $stats = $this->auditLogger->getStats(30);
        $keys  = $this->apiKeyManager->getAllKeys();

        $totalPages = (int) ceil(($result['total'] ?? 0) / $perPage);

        // Stable base URL for pagination links inside the partial — without
        // this, AJAX-rendered pagination links would point at admin-ajax.php.
        $baseUrl = admin_url('admin.php?page=' . self::MENU_SLUG . '-audit');

        return [
            'result'      => $result,
            'stats'       => $stats,
            'keys'        => $keys,
            'page'        => $page,
            'per_page'    => $perPage,
            'filters'     => $filters,
            'total_pages' => $totalPages,
            'base_url'    => $baseUrl,
        ];
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to access this page.', 'integrity'));
        }

        include INTEGRITY_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    /**
     * Handle API key creation
     */
    public function handleCreateKey(): void
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
        if (!empty($_POST['perm_positions'])) {
            $permissions[] = 'positions:read';
        }
        if (!empty($_POST['perm_members'])) {
            $permissions[] = 'members:read';
        }
        if (!empty($_POST['perm_members_write'])) {
            $permissions[] = 'members:write';
        }
        if (!empty($_POST['perm_members_clear'])) {
            $permissions[] = 'members:clear';
            // members:clear is a modifier on members:read; ensure the base
            // read permission is present so the key can actually hit the
            // /members endpoints.
            if (!in_array('members:read', $permissions, true)) {
                $permissions[] = 'members:read';
            }
        }
        if (!empty($_POST['perm_intergroup_meetings'])) {
            $permissions[] = 'intergroup-meetings:read';
        }
        if (!empty($_POST['perm_intergroup_meetings_write'])) {
            $permissions[] = 'intergroup-meetings:write';
        }
        if (!empty($_POST['perm_all'])) {
            $permissions = ['*'];
        }

        if (empty($permissions)) {
            $permissions = ['groups:read', 'meetings:read'];
        }

        $rateLimit = !empty($_POST['rate_limit']) ? (int) $_POST['rate_limit'] : null;
        $expiresAt = !empty($_POST['expires_at']) ? sanitize_text_field($_POST['expires_at']) . ' 23:59:59' : null;

        $ipWhitelist = null;
        if (!empty($_POST['ip_whitelist'])) {
            $ips = array_map('trim', explode("\n", sanitize_textarea_field($_POST['ip_whitelist'])));
            $ips = array_filter($ips);
            if (!empty($ips)) {
                $ipWhitelist = $ips;
            }
        }

        $result = $this->apiKeyManager->createKey($name, $permissions, $rateLimit, $expiresAt, $ipWhitelist);

        if ($result['success']) {
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
    public function handleRevokeKey(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to perform this action.', 'integrity'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $keyId = isset($_POST['key_id']) ? (int) $_POST['key_id'] : 0;

        if ($keyId > 0 && $this->apiKeyManager->revokeKey($keyId)) {
            wp_redirect(add_query_arg('revoked', '1', admin_url('admin.php?page=' . self::MENU_SLUG)));
        } else {
            wp_redirect(add_query_arg('error', 'revoke_failed', admin_url('admin.php?page=' . self::MENU_SLUG)));
        }
        exit;
    }

    /**
     * Handle API key deletion
     */
    public function handleDeleteKey(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to perform this action.', 'integrity'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $keyId = isset($_POST['key_id']) ? (int) $_POST['key_id'] : 0;

        if ($keyId > 0 && $this->apiKeyManager->deleteKey($keyId)) {
            wp_redirect(add_query_arg('deleted', '1', admin_url('admin.php?page=' . self::MENU_SLUG)));
        } else {
            wp_redirect(add_query_arg('error', 'delete_failed', admin_url('admin.php?page=' . self::MENU_SLUG)));
        }
        exit;
    }

    /**
     * Handle clearing audit logs
     */
    public function handleClearLogs(): void
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

        $deleted = $this->auditLogger->clearLogs($olderThanDays, $apiKeyId);

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
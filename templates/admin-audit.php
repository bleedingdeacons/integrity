<?php
/**
 * Admin Audit Log Template
 *
 * Expects the following variables in scope (provided by SettingsPage::renderAuditPage):
 *   $result      array  { logs: array, total: int }
 *   $stats       array  aggregated stats
 *   $keys        array  list of all API keys
 *   $page        int    current page number
 *   $perPage     int    page size
 *   $filters     array  active filter values
 *   $totalPages  int    total number of pages
 */

use Integrity\Admin\SettingsPage;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap integrity-admin">
    <h1><?php echo esc_html__('Integrity API Audit Log', 'integrity'); ?></h1>

    <?php if (isset($_GET['logs_cleared'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(
                        esc_html__('%d log entries have been deleted.', 'integrity'),
                        (int) $_GET['logs_cleared']
                ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Stats Overview -->
    <div class="integrity-stats-grid">
        <?php include INTEGRITY_PLUGIN_DIR . 'templates/admin-audit-stats-partial.php'; ?>
    </div>

    <!-- Clear Logs -->
    <div class="integrity-section">
        <h2><?php echo esc_html__('Clear Logs', 'integrity'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="integrity-clear-logs-form">
            <input type="hidden" name="action" value="integrity_clear_logs">
            <?php echo SettingsPage::getNonceField(); ?>

            <div class="integrity-filters">
                <label>
                    <?php echo esc_html__('Older than:', 'integrity'); ?>
                    <select name="older_than_days">
                        <option value=""><?php echo esc_html__('All logs', 'integrity'); ?></option>
                        <option value="7"><?php echo esc_html__('7 days', 'integrity'); ?></option>
                        <option value="30"><?php echo esc_html__('30 days', 'integrity'); ?></option>
                        <option value="60"><?php echo esc_html__('60 days', 'integrity'); ?></option>
                        <option value="90"><?php echo esc_html__('90 days', 'integrity'); ?></option>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('API Key:', 'integrity'); ?>
                    <select name="api_key_id">
                        <option value=""><?php echo esc_html__('All Keys', 'integrity'); ?></option>
                        <?php foreach ($keys as $key): ?>
                            <option value="<?php echo esc_attr($key['id']); ?>">
                                <?php echo esc_html($key['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit" class="button button-secondary"
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete these log entries? This cannot be undone.', 'integrity')); ?>')">
                    <?php echo esc_html__('Clear Logs', 'integrity'); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Filters -->
    <div class="integrity-section">
        <h2><?php echo esc_html__('Filter Logs', 'integrity'); ?></h2>
        <form id="integrity-audit-filters-form" method="get" action="">
            <input type="hidden" name="page" value="integrity-settings-audit">

            <div class="integrity-filters">
                <label>
                    <?php echo esc_html__('API Key:', 'integrity'); ?>
                    <select name="api_key_id">
                        <option value=""><?php echo esc_html__('All Keys', 'integrity'); ?></option>
                        <?php foreach ($keys as $key): ?>
                            <option value="<?php echo esc_attr($key['id']); ?>" <?php selected($filters['api_key_id'], $key['id']); ?>>
                                <?php echo esc_html($key['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('Status:', 'integrity'); ?>
                    <select name="response_code">
                        <option value=""><?php echo esc_html__('All', 'integrity'); ?></option>
                        <option value="200" <?php selected($filters['response_code'], 200); ?>>200 OK</option>
                        <option value="400" <?php selected($filters['response_code'], 400); ?>>400 Bad Request</option>
                        <option value="401" <?php selected($filters['response_code'], 401); ?>>401 Unauthorized</option>
                        <option value="403" <?php selected($filters['response_code'], 403); ?>>403 Forbidden</option>
                        <option value="404" <?php selected($filters['response_code'], 404); ?>>404 Not Found</option>
                        <option value="429" <?php selected($filters['response_code'], 429); ?>>429 Rate Limited</option>
                        <option value="500" <?php selected($filters['response_code'], 500); ?>>500 Error</option>
                    </select>
                </label>

                <label>
                    <?php echo esc_html__('IP Address:', 'integrity'); ?>
                    <input type="text" name="ip_address" value="<?php echo esc_attr($filters['ip_address'] ?? ''); ?>"
                           placeholder="<?php echo esc_attr__('e.g., 192.168.1.1', 'integrity'); ?>">
                </label>

                <label>
                    <?php echo esc_html__('From:', 'integrity'); ?>
                    <input type="date" name="date_from" value="<?php echo esc_attr($filters['date_from'] ?? ''); ?>">
                </label>

                <label>
                    <?php echo esc_html__('To:', 'integrity'); ?>
                    <input type="date" name="date_to" value="<?php echo esc_attr($filters['date_to'] ?? ''); ?>">
                </label>

                <?php submit_button(__('Filter', 'integrity'), 'secondary', 'submit', false); ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=integrity-settings-audit')); ?>" class="button">
                    <?php echo esc_html__('Reset', 'integrity'); ?>
                </a>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="integrity-section">
        <?php
        $autoRefreshEnabled = (bool) get_option('integrity_audit_auto_refresh_enabled', true);
        $autoRefreshInterval = max(5, (int) get_option('integrity_audit_auto_refresh_interval', 30));
        ?>
        <div class="integrity-log-header">
            <h2><?php echo esc_html__('Request Log', 'integrity'); ?></h2>
            <div class="integrity-log-controls">
                <label class="integrity-auto-refresh-toggle">
                    <input type="checkbox" id="integrity-auto-refresh"
                           <?php checked($autoRefreshEnabled); ?>
                           data-interval="<?php echo esc_attr((string) $autoRefreshInterval); ?>">
                    <?php printf(
                        esc_html__('Auto-refresh every %ds', 'integrity'),
                        $autoRefreshInterval
                    ); ?>
                </label>
                <span id="integrity-refresh-countdown" class="integrity-refresh-countdown" aria-live="polite"></span>
                <button type="button" class="button" id="integrity-refresh-btn">
                    <span class="dashicons dashicons-update" style="vertical-align: text-bottom;"></span>
                    <?php echo esc_html__('Refresh', 'integrity'); ?>
                </button>
            </div>
        </div>

        <div id="integrity-audit-logs-container">
            <?php
            $baseUrl = admin_url('admin.php?page=integrity-settings-audit');
            include INTEGRITY_PLUGIN_DIR . 'templates/admin-audit-logs-partial.php';
            ?>
        </div>
    </div>
</div>

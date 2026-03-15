<?php
/**
 * Admin Audit Log Template
 */

use Integrity\Admin\SettingsPage;

if (!defined('ABSPATH')) {
    exit;
}

$totalPages = (int) ceil($result['total'] / $perPage);
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
        <div class="integrity-stat-box">
            <h3><?php echo esc_html(number_format($stats['total_requests'])); ?></h3>
            <p><?php echo esc_html__('Total Requests', 'integrity'); ?></p>
        </div>
        <div class="integrity-stat-box success">
            <h3><?php echo esc_html(number_format($stats['successful_requests'])); ?></h3>
            <p><?php echo esc_html__('Successful', 'integrity'); ?></p>
        </div>
        <div class="integrity-stat-box warning">
            <h3><?php echo esc_html(number_format($stats['failed_auth'])); ?></h3>
            <p><?php echo esc_html__('Failed Auth', 'integrity'); ?></p>
        </div>
        <div class="integrity-stat-box danger">
            <h3><?php echo esc_html(number_format($stats['rate_limited'])); ?></h3>
            <p><?php echo esc_html__('Rate Limited', 'integrity'); ?></p>
        </div>
        <div class="integrity-stat-box">
            <h3><?php echo esc_html($stats['avg_response_time']); ?>s</h3>
            <p><?php echo esc_html__('Avg Response', 'integrity'); ?></p>
        </div>
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
        <form method="get" action="">
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
        <h2><?php echo esc_html__('Request Log', 'integrity'); ?></h2>

        <?php if (empty($result['logs'])): ?>
            <p><?php echo esc_html__('No log entries found.', 'integrity'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th><?php echo esc_html__('Time', 'integrity'); ?></th>
                    <th><?php echo esc_html__('API Key', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Endpoint', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Method', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Status', 'integrity'); ?></th>
                    <th><?php echo esc_html__('IP Address', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Response Time', 'integrity'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['logs'] as $log): ?>
                    <tr>
                        <td><?php echo esc_html(wp_date('j M Y, H:i', strtotime($log['created_at']))); ?></td>
                        <td>
                            <?php
                            if ($log['api_key_id']) {
                                $keyName = '';
                                foreach ($keys as $key) {
                                    if ($key['id'] == $log['api_key_id']) {
                                        $keyName = $key['name'];
                                        break;
                                    }
                                }
                                echo esc_html($keyName ?: '#' . $log['api_key_id']);
                            } else {
                                echo '<em>' . esc_html__('None', 'integrity') . '</em>';
                            }
                            ?>
                        </td>
                        <td><code><?php echo esc_html($log['endpoint']); ?></code></td>
                        <td><code><?php echo esc_html($log['method']); ?></code></td>
                        <td>
                                <span class="integrity-status-code status-<?php echo esc_attr((int)($log['response_code'] / 100)); ?>xx">
                                    <?php echo esc_html($log['response_code']); ?>
                                </span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(add_query_arg('ip_address', $log['ip_address'])); ?>">
                                <?php echo esc_html($log['ip_address']); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html(round($log['response_time'] * 1000, 2)); ?>ms</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf(
                                    esc_html__('%s items', 'integrity'),
                                    number_format($result['total'])
                            ); ?>
                        </span>
                        <span class="pagination-links">
                            <?php if ($page > 1): ?>
                                <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1)); ?>">
                                    &laquo;
                                </a>
                                <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">
                                    &lsaquo;
                                </a>
                            <?php endif; ?>

                            <span class="paging-input">
                                <?php echo esc_html($page); ?> of <?php echo esc_html($totalPages); ?>
                            </span>

                            <?php if ($page < $totalPages): ?>
                                <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>">
                                    &rsaquo;
                                </a>
                                <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $totalPages)); ?>">
                                    &raquo;
                                </a>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
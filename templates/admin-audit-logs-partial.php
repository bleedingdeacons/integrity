<?php
/**
 * Admin Audit Log — Logs Table + Pagination Partial
 *
 * Expects the following variables in scope:
 *   $result      array  { logs: array, total: int }
 *   $keys        array  list of keys for name lookup
 *   $page        int    current page number
 *   $totalPages  int    total number of pages
 *   $baseUrl     string optional. Base URL that pagination/filter links are
 *                       built relative to. Required when this partial is
 *                       rendered via AJAX, where REQUEST_URI points at
 *                       admin-ajax.php instead of the audit page.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($baseUrl) || $baseUrl === '') {
    // Fallback: REQUEST_URI of the current admin page (works for normal page loads).
    $baseUrl = '';
}

/**
 * Build a URL with one argument added/replaced, scoped to $baseUrl when set.
 * Falls back to add_query_arg's default REQUEST_URI behavior otherwise.
 */
$addArg = static function (string $key, $value) use ($baseUrl) {
    if ($baseUrl !== '') {
        return add_query_arg($key, $value, $baseUrl);
    }
    return add_query_arg($key, $value);
};
?>
<?php if (empty($result['logs'])): ?>
    <p><?php echo esc_html__('No log entries found.', 'integrity'); ?></p>
<?php else: ?>
    <table class="wp-list-table widefat fixed striped integrity-audit-table">
        <thead>
        <tr>
            <th class="col-time"><?php echo esc_html__('Time', 'integrity'); ?></th>
            <th class="col-key-name"><?php echo esc_html__('Key Name', 'integrity'); ?></th>
            <th class="col-endpoint"><?php echo esc_html__('Endpoint', 'integrity'); ?></th>
            <th class="col-method"><?php echo esc_html__('Method', 'integrity'); ?></th>
            <th class="col-status"><?php echo esc_html__('Status', 'integrity'); ?></th>
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
                    <a href="<?php echo esc_url($addArg('ip_address', $log['ip_address'])); ?>">
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
                        <a class="first-page button" href="<?php echo esc_url($addArg('paged', 1)); ?>">
                            &laquo;
                        </a>
                        <a class="prev-page button" href="<?php echo esc_url($addArg('paged', $page - 1)); ?>">
                            &lsaquo;
                        </a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <?php echo esc_html($page); ?> of <?php echo esc_html($totalPages); ?>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a class="next-page button" href="<?php echo esc_url($addArg('paged', $page + 1)); ?>">
                            &rsaquo;
                        </a>
                        <a class="last-page button" href="<?php echo esc_url($addArg('paged', $totalPages)); ?>">
                            &raquo;
                        </a>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

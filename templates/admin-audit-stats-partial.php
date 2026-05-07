<?php
/**
 * Admin Audit Log — Stats Grid Partial
 *
 * Expects the following variables in scope:
 *   $stats array { total_requests, successful_requests, failed_auth, rate_limited, avg_response_time }
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
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

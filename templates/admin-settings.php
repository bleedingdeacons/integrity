<?php
/**
 * Admin Settings Template
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap integrity-admin">
    <h1><?php echo esc_html__('Integrity API Settings', 'integrity'); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields('integrity_settings'); ?>
        
        <div class="integrity-section">
            <h2><?php echo esc_html__('Security Settings', 'integrity'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php echo esc_html__('Require HTTPS', 'integrity'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="integrity_require_https" value="1" 
                                   <?php checked(get_option('integrity_require_https', true)); ?>>
                            <?php echo esc_html__('Require HTTPS for all API requests', 'integrity'); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__('Strongly recommended. Rejecting HTTP requests prevents API keys from being transmitted in plain text.', 'integrity'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="integrity_default_rate_limit">
                            <?php echo esc_html__('Default Rate Limit', 'integrity'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" id="integrity_default_rate_limit" name="integrity_default_rate_limit" 
                               value="<?php echo esc_attr(get_option('integrity_default_rate_limit', 1000)); ?>"
                               min="1" max="100000" class="small-text">
                        <span><?php echo esc_html__('requests per hour', 'integrity'); ?></span>
                        <p class="description">
                            <?php echo esc_html__('Default rate limit for new API keys. Can be overridden per key.', 'integrity'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="integrity-section">
            <h2><?php echo esc_html__('Audit Logging', 'integrity'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php echo esc_html__('Enable Audit Log', 'integrity'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="integrity_enable_audit_log" value="1" 
                                   <?php checked(get_option('integrity_enable_audit_log', true)); ?>>
                            <?php echo esc_html__('Log all API requests for security monitoring', 'integrity'); ?>
                        </label>
                        <p class="description">
                            <?php echo esc_html__('Recommended for security compliance and debugging.', 'integrity'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="integrity_audit_log_retention_days">
                            <?php echo esc_html__('Log Retention', 'integrity'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="number" id="integrity_audit_log_retention_days" name="integrity_audit_log_retention_days" 
                               value="<?php echo esc_attr(get_option('integrity_audit_log_retention_days', 90)); ?>"
                               min="1" max="365" class="small-text">
                        <span><?php echo esc_html__('days', 'integrity'); ?></span>
                        <p class="description">
                            <?php echo esc_html__('Logs older than this will be automatically deleted.', 'integrity'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <?php submit_button(); ?>
    </form>

    <div class="integrity-section">
        <h2><?php echo esc_html__('API Information', 'integrity'); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php echo esc_html__('REST API Base URL', 'integrity'); ?></th>
                <td>
                    <code><?php echo esc_url(rest_url('integrity/v1/')); ?></code>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Plugin Version', 'integrity'); ?></th>
                <td><?php echo esc_html(INTEGRITY_VERSION); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Unity Plugin', 'integrity'); ?></th>
                <td>
                    <?php if (class_exists('Unity\\Plugin')): ?>
                        <span class="integrity-status active"><?php echo esc_html__('Active', 'integrity'); ?></span>
                    <?php else: ?>
                        <span class="integrity-status revoked"><?php echo esc_html__('Not Active', 'integrity'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
</div>

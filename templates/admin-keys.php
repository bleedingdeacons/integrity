<?php
/**
 * Admin API Keys Template
 */

use Integrity\Admin\SettingsPage;

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap integrity-admin">
    <h1><?php echo esc_html__('Integrity API Keys', 'integrity'); ?></h1>

    <?php if (isset($_GET['error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                $errors = [
                        'name_required' => __('API key name is required.', 'integrity'),
                        'create_failed' => __('Failed to create API key.', 'integrity'),
                        'revoke_failed' => __('Failed to revoke API key.', 'integrity'),
                        'delete_failed' => __('Failed to delete API key.', 'integrity'),
                ];
                echo esc_html($errors[$_GET['error']] ?? __('An error occurred.', 'integrity'));
                ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['created']) && $newKey): ?>
        <div class="notice notice-success">
            <p><strong><?php echo esc_html__('API key created successfully!', 'integrity'); ?></strong></p>
            <p><?php echo esc_html__('Copy your API key now. It will not be shown again:', 'integrity'); ?></p>
            <code class="integrity-new-key"><?php echo esc_html($newKey); ?></code>
            <p><small><?php echo esc_html__('Store this key securely. It cannot be recovered.', 'integrity'); ?></small></p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['revoked'])): ?>
        <div class="notice notice-warning is-dismissible">
            <p><?php echo esc_html__('API key has been revoked.', 'integrity'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html__('API key has been permanently deleted.', 'integrity'); ?></p>
        </div>
    <?php endif; ?>

    <div class="integrity-section">
        <h2><?php echo esc_html__('Create New API Key', 'integrity'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="integrity-form">
            <input type="hidden" name="action" value="integrity_create_key">
            <?php echo SettingsPage::getNonceField(); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="key_name"><?php echo esc_html__('Name', 'integrity'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="key_name" name="key_name" class="regular-text" required
                               placeholder="<?php echo esc_attr__('e.g., Mobile App Production', 'integrity'); ?>">
                        <p class="description"><?php echo esc_html__('A descriptive name to identify this API key.', 'integrity'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Permissions', 'integrity'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="perm_groups" value="1" checked>
                                <?php echo esc_html__('Read Groups', 'integrity'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="perm_meetings" value="1" checked>
                                <?php echo esc_html__('Read Meetings', 'integrity'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="perm_positions" value="1" checked>
                                <?php echo esc_html__('Read Positions', 'integrity'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="perm_members" value="1" checked>
                                <?php echo esc_html__('Read Members', 'integrity'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="perm_members_write" value="1">
                                <?php echo esc_html__('Write Members (update member details)', 'integrity'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="perm_intergroup_meetings" value="1" checked>
                                <?php echo esc_html__('Read Intergroup Meetings', 'integrity'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="perm_intergroup_meetings_write" value="1">
                                <?php echo esc_html__('Write Intergroup Meetings (register/unregister attendees)', 'integrity'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="perm_all" value="1">
                                <?php echo esc_html__('Full Access (all permissions)', 'integrity'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="rate_limit"><?php echo esc_html__('Rate Limit', 'integrity'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="rate_limit" name="rate_limit" class="small-text"
                               min="1" max="100000"
                               placeholder="<?php echo esc_attr(get_option('integrity_default_rate_limit', 1000)); ?>">
                        <span><?php echo esc_html__('requests per hour', 'integrity'); ?></span>
                        <p class="description"><?php echo esc_html__('Leave empty to use default rate limit.', 'integrity'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="expires_at"><?php echo esc_html__('Expiration Date', 'integrity'); ?></label>
                    </th>
                    <td>
                        <input type="date" id="expires_at" name="expires_at"
                               min="<?php echo esc_attr(gmdate('Y-m-d')); ?>">
                        <p class="description"><?php echo esc_html__('Optional. Leave empty for no expiration.', 'integrity'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ip_whitelist"><?php echo esc_html__('IP Whitelist', 'integrity'); ?></label>
                    </th>
                    <td>
                        <textarea id="ip_whitelist" name="ip_whitelist" rows="4" class="regular-text"
                                  placeholder="<?php echo esc_attr__("192.168.1.1\n10.0.0.0/8\n2001:db8::/32", 'integrity'); ?>"></textarea>
                        <p class="description"><?php echo esc_html__('Optional. One IP address or CIDR range per line. Leave empty to allow all IPs.', 'integrity'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Create API Key', 'integrity'), 'primary'); ?>
        </form>
    </div>

    <div class="integrity-section">
        <h2><?php echo esc_html__('Existing API Keys', 'integrity'); ?></h2>

        <?php if (empty($keys)): ?>
            <p><?php echo esc_html__('No API keys have been created yet.', 'integrity'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                <tr>
                    <th><?php echo esc_html__('Name', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Key Prefix', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Permissions', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Rate Limit', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Usage', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Status', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Created', 'integrity'); ?></th>
                    <th><?php echo esc_html__('Actions', 'integrity'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($keys as $key): ?>
                    <tr class="<?php echo $key['is_active'] ? '' : 'integrity-revoked'; ?>">
                        <td>
                            <strong><?php echo esc_html($key['name']); ?></strong>
                            <?php if ($key['ip_whitelist']): ?>
                                <br><small title="<?php echo esc_attr(implode(', ', $key['ip_whitelist'])); ?>">
                                    🔒 <?php echo esc_html__('IP restricted', 'integrity'); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html($key['api_key_prefix']); ?>...</code></td>
                        <td>
                            <?php
                            if (in_array('*', $key['permissions'])) {
                                echo '<span class="integrity-badge full">' . esc_html__('Full Access', 'integrity') . '</span>';
                            } else {
                                foreach ($key['permissions'] as $perm) {
                                    echo '<span class="integrity-badge">' . esc_html($perm) . '</span> ';
                                }
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html(number_format($key['rate_limit'])); ?>/hr</td>
                        <td>
                            <?php echo esc_html(number_format($key['request_count'])); ?> requests
                            <?php if ($key['last_used']): ?>
                                <br><small><?php echo esc_html__('Last:', 'integrity'); ?> <?php echo esc_html(wp_date('j M Y, H:i', strtotime($key['last_used']))); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$key['is_active']): ?>
                                <span class="integrity-status revoked"><?php echo esc_html__('Revoked', 'integrity'); ?></span>
                            <?php elseif ($key['expires_at'] && strtotime($key['expires_at']) < time()): ?>
                                <span class="integrity-status expired"><?php echo esc_html__('Expired', 'integrity'); ?></span>
                            <?php else: ?>
                                <span class="integrity-status active"><?php echo esc_html__('Active', 'integrity'); ?></span>
                                <?php if ($key['expires_at']): ?>
                                    <br><small><?php echo esc_html__('Expires:', 'integrity'); ?> <?php echo esc_html(wp_date('j M Y', strtotime($key['expires_at']))); ?></small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo esc_html(wp_date('j M Y, H:i', strtotime($key['created_at']))); ?>
                            <br><small><?php
                                $user = get_user_by('id', $key['created_by']);
                                echo esc_html__('by', 'integrity') . ' ' . ($user ? esc_html($user->display_name) : esc_html__('Unknown', 'integrity'));
                                ?></small>
                        </td>
                        <td>
                            <?php if ($key['is_active']): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <input type="hidden" name="action" value="integrity_revoke_key">
                                    <input type="hidden" name="key_id" value="<?php echo esc_attr($key['id']); ?>">
                                    <?php echo SettingsPage::getNonceField(); ?>
                                    <button type="submit" class="button button-secondary"
                                            onclick="return confirm('<?php echo esc_js(__('Are you sure you want to revoke this API key?', 'integrity')); ?>')">
                                        <?php echo esc_html__('Revoke', 'integrity'); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="integrity_delete_key">
                                <input type="hidden" name="key_id" value="<?php echo esc_attr($key['id']); ?>">
                                <?php echo SettingsPage::getNonceField(); ?>
                                <button type="submit" class="button button-link-delete"
                                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to permanently delete this API key?', 'integrity')); ?>')">
                                    <?php echo esc_html__('Delete', 'integrity'); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="integrity-section integrity-docs">
        <h2><?php echo esc_html__('API Documentation', 'integrity'); ?></h2>
        <p><?php echo esc_html__('Use your API key with the following endpoints:', 'integrity'); ?></p>

        <h3><?php echo esc_html__('Authentication', 'integrity'); ?></h3>
        <p><?php echo esc_html__('Include your API key in the Authorization header:', 'integrity'); ?></p>
        <pre>Authorization: Bearer int_your_api_key_here</pre>

        <h3><?php echo esc_html__('Endpoints', 'integrity'); ?></h3>
        <table class="widefat">
            <thead>
            <tr>
                <th><?php echo esc_html__('Method', 'integrity'); ?></th>
                <th><?php echo esc_html__('Endpoint', 'integrity'); ?></th>
                <th><?php echo esc_html__('Permission', 'integrity'); ?></th>
                <th><?php echo esc_html__('Description', 'integrity'); ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/groups</code></td>
                <td><code>groups:read</code></td>
                <td><?php echo esc_html__('List all groups', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/groups/{id}</code></td>
                <td><code>groups:read</code></td>
                <td><?php echo esc_html__('Get a single group', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/meetings</code></td>
                <td><code>meetings:read</code></td>
                <td><?php echo esc_html__('List all meetings', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/meetings/{id}</code></td>
                <td><code>meetings:read</code></td>
                <td><?php echo esc_html__('Get a single meeting', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/positions</code></td>
                <td><code>positions:read</code></td>
                <td><?php echo esc_html__('List all positions', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/positions/{id}</code></td>
                <td><code>positions:read</code></td>
                <td><?php echo esc_html__('Get a single position', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/members</code></td>
                <td><code>members:read</code></td>
                <td><?php echo esc_html__('List all members', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/members/{id}</code></td>
                <td><code>members:read</code></td>
                <td><?php echo esc_html__('Get a single member', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>POST</code></td>
                <td><code>/wp-json/integrity/v1/members/{id}/update</code></td>
                <td><code>members:write</code></td>
                <td><?php echo esc_html__('Update a member (partial update, all fields optional)', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/intergroup-meetings</code></td>
                <td><code>intergroup-meetings:read</code></td>
                <td><?php echo esc_html__('List all intergroup meetings', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/intergroup-meetings/{id}</code></td>
                <td><code>intergroup-meetings:read</code></td>
                <td><?php echo esc_html__('Get a single intergroup meeting', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>POST</code></td>
                <td><code>/wp-json/integrity/v1/intergroup-meetings/{id}/register</code></td>
                <td><code>intergroup-meetings:write</code></td>
                <td><?php echo esc_html__('Register a member as an attendee (requires member_id in body)', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>POST</code></td>
                <td><code>/wp-json/integrity/v1/intergroup-meetings/{id}/unregister</code></td>
                <td><code>intergroup-meetings:write</code></td>
                <td><?php echo esc_html__('Unregister a member from an intergroup meeting (requires member_id in body)', 'integrity'); ?></td>
            </tr>
            <tr>
                <td><code>GET</code></td>
                <td><code>/wp-json/integrity/v1/health</code></td>
                <td>—</td>
                <td><?php echo esc_html__('Health check (no auth required)', 'integrity'); ?></td>
            </tr>
            </tbody>
        </table>

        <h3><?php echo esc_html__('Example Request (C#)', 'integrity'); ?></h3>
        <pre>
using var client = new HttpClient();
client.DefaultRequestHeaders.Authorization =
    new AuthenticationHeaderValue("Bearer", "int_your_api_key_here");

var response = await client.GetAsync("<?php echo esc_url(rest_url('integrity/v1/groups')); ?>");
var json = await response.Content.ReadAsStringAsync();
        </pre>
    </div>
</div>
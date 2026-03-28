<?php
if (!defined('ABSPATH')) exit;

function gallery_sync_register_settings_menu() {
    add_submenu_page(
        'gallery-sync',
        'Settings',
        'Settings',
        'manage_options',
        'gallery-sync-settings',
        'gallery_sync_settings_page'
    );
}
add_action('admin_menu', 'gallery_sync_register_settings_menu');

function gallery_sync_render_api_endpoint_notice(): void {
    if (function_exists('gallery_sync_is_api_configured') && gallery_sync_is_api_configured()) {
        return;
    }
    echo '<div class="gallery-sync-inline-notice is-warning">';
    echo '<strong>API endpoint is not configured.</strong> Set the Cloudflare Workers API base URL to enable license verification and entitlements.';
    echo '</div>';
}

function gallery_sync_settings_page() {
    if (!current_user_can('manage_options')) return;

    $settings = gallery_sync_settings();
    $license_key = gallery_sync_get_license_key();
    $api_base_url = function_exists('gallery_sync_get_api_base_url') ? gallery_sync_get_api_base_url() : '';
    $features = function_exists('gallery_sync_get_features') ? gallery_sync_get_features(false) : [];
    $is_valid = !empty($features['valid']);
    $plan = !empty($features['plan']) ? (string) $features['plan'] : 'Unknown';
    $expires_at = !empty($features['expires_at']) ? (string) $features['expires_at'] : null;
    $activations_used = isset($features['activations_used']) ? (int) $features['activations_used'] : 0;
    $max_activations = isset($features['max_activations']) ? (int) $features['max_activations'] : 0;
    $instance_id = function_exists('gallery_sync_get_instance_id') ? gallery_sync_get_instance_id() : '';

    $messages = get_settings_errors('gallery_sync_settings_messages');

    if (isset($_POST['gallery_sync_save_settings'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gallery_sync_save_settings')) {
            add_settings_error(
                'gallery_sync_settings_messages',
                'gallery_sync_invalid_nonce',
                'Security check failed. Please try again.',
                'error'
            );
        } else {
            $api_base_url = esc_url_raw(trim($_POST['api_base_url'] ?? ''));
            if ($api_base_url !== '') {
                $parsed = wp_parse_url($api_base_url);
                $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : '';
                if ($scheme !== 'https') {
                    add_settings_error(
                        'gallery_sync_settings_messages',
                        'gallery_sync_invalid_api_base',
                        'API Base URL must be a valid HTTPS URL.',
                        'error'
                    );
                } else {
                    gallery_sync_update_api_base_url($api_base_url);
                }
            } else {
                gallery_sync_update_api_base_url('');
            }

            $license_key = sanitize_text_field($_POST['license_key'] ?? '');
            gallery_sync_update_license_key($license_key);
            $features = function_exists('gallery_sync_refresh_features') ? gallery_sync_refresh_features() : [];
            $is_valid = !empty($features['valid']);
            $plan = !empty($features['plan']) ? (string) $features['plan'] : 'Unknown';
            $expires_at = !empty($features['expires_at']) ? (string) $features['expires_at'] : null;
            $activations_used = isset($features['activations_used']) ? (int) $features['activations_used'] : 0;
            $max_activations = isset($features['max_activations']) ? (int) $features['max_activations'] : 0;

            add_settings_error(
                'gallery_sync_settings_messages',
                'gallery_sync_settings_saved',
                'Settings saved.',
                'updated'
            );
        }
    }
    ?>
    <div class="wrap gallery-sync-admin gallery-sync-settings">
        <?php if (!empty($messages)): ?>
            <div class="gallery-sync-banner-stack">
                <?php foreach ($messages as $message): ?>
                    <?php
                    $type = $message['type'] ?? 'info';
                    $text = $message['message'] ?? '';
                    $banner_class = $type === 'error' ? 'is-error' : ($type === 'updated' ? 'is-success' : 'is-info');
                    ?>
                    <div class="gallery-sync-banner <?= esc_attr($banner_class) ?>">
                        <p><?= wp_kses_post($text) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="gallery-sync-hero">
            <div>
                <h1>Settings</h1>
                <p>Configure the Cloudflare Workers API connection and license access.</p>
            </div>
            <div class="gallery-sync-hero__meta">
                <span class="gallery-sync-badge">Plan: <?= esc_html($plan) ?></span>
                <span class="gallery-sync-badge <?= $is_valid ? 'gallery-sync-badge--success' : 'gallery-sync-badge--muted' ?>">
                    <?= $is_valid ? 'Active' : 'Inactive' ?>
                </span>
            </div>
        </div>

        <form method="post">
            <?php wp_nonce_field('gallery_sync_save_settings'); ?>

            <div class="gallery-sync-grid">
                <div class="gallery-sync-card">
                    <div class="gallery-sync-card__header">
                        <h2>API Connection</h2>
                        <span class="gallery-sync-card__subtitle">Single API endpoint for licensing, entitlements, and advanced integrations.</span>
                    </div>

                    <div class="gallery-sync-field">
                        <label for="gallery-sync-api-base">API Base URL</label>
                        <input id="gallery-sync-api-base" type="url" name="api_base_url"
                               value="<?= esc_attr($api_base_url) ?>"
                               placeholder="https://api.example.com"
                               title="Cloudflare Workers API base URL"
                               class="gallery-sync-tip"
                               data-gallery-sync-tooltip="Cloudflare Workers API base URL. Must be HTTPS.">
                    </div>

                    <div class="gallery-sync-field">
                        <label for="gallery-sync-license-key">License Key</label>
                        <div class="gallery-sync-input-row">
                            <input id="gallery-sync-license-key" type="text" name="license_key" value="<?= esc_attr($license_key) ?>"
                                   placeholder="Enter license key"
                                   title="License key for your subscription."
                                   class="gallery-sync-tip"
                                   data-gallery-sync-tooltip="License key for your subscription.">
                            <div class="gallery-sync-inline-actions">
                                <button type="button" class="button" id="gallery-sync-refresh-license-btn">Validate Now</button>
                                <button type="button" class="button button-primary" id="gallery-sync-purchase-license-btn">Purchase / Upgrade</button>
                                <span id="gallery-sync-refresh-license-result" class="gallery-sync-helper"></span>
                            </div>
                        </div>
                        <span class="gallery-sync-status <?= $is_valid ? 'is-active' : 'is-inactive' ?>">
                            <?= $is_valid ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>

                    <div class="gallery-sync-field">
                        <label>License Details</label>
                        <p class="gallery-sync-helper"><strong>Instance ID:</strong> <code><?= esc_html($instance_id) ?></code></p>
                        <p class="gallery-sync-helper"><strong>Plan:</strong> <?= esc_html($plan) ?></p>
                        <p class="gallery-sync-helper"><strong>Expiry:</strong> <?= $expires_at ? esc_html($expires_at) : 'Never (lifetime)'; ?></p>
                        <p class="gallery-sync-helper"><strong>Activations:</strong> <?= esc_html((string) $activations_used) ?><?= $max_activations > 0 ? ' / ' . esc_html((string) $max_activations) : ''; ?></p>
                    </div>

                    <?php gallery_sync_render_api_endpoint_notice(); ?>
                </div>
            </div>

            <div class="gallery-sync-card__footer">
                <?php submit_button('Save Settings', 'primary', 'gallery_sync_save_settings', false); ?>
            </div>
        </form>
    </div>
    <?php
}

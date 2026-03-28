<?php
if (!defined('ABSPATH')) exit;

function gallery_sync_admin_enqueue_assets() {
    // Only enqueue on Gallery Sync settings pages
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $screen_id = $screen->id ?? '';
    $is_settings_page =
        ($screen && $screen_id === 'gallery-sync_page_gallery-sync-settings') ||
        (isset($_GET['page']) && $_GET['page'] === 'gallery-sync-settings');

    $is_gallery_sync_page =
        ($screen && isset($screen->id) && in_array($screen_id, ['toplevel_page_gallery-sync', 'gallery-sync_page_gallery-sync-pro', 'gallery-sync_page_gallery-sync-settings'], true)) ||
        (isset($_GET['page']) && in_array($_GET['page'], ['gallery-sync', 'gallery-sync-pro', 'gallery-sync-settings'], true));

    if (!$is_gallery_sync_page) {
        return;
    }

    $version = '4.3.0';
    $plugin_url = plugin_dir_url(dirname(__FILE__, 3) . '/gallery-sync.php');

    // Styles (split: progress + tooltips)
    wp_enqueue_style(
        'gallery-sync-admin-ui',
        $plugin_url . 'assets/css/gallery-sync-admin-ui.css',
        [],
        $version
    );

    wp_enqueue_style(
        'gallery-sync-progress-css',
        $plugin_url . 'assets/css/gallery-sync-progress.css',
        [],
        $version
    );

    wp_enqueue_style(
        'gallery-sync-tooltips-css',
        $plugin_url . 'assets/css/gallery-sync-tooltips.css',
        [],
        $version
    );

    // Tooltip JS enhancer (touch/keyboard friendly)
    wp_enqueue_script(
        'gallery-sync-tooltips-js',
        $plugin_url . 'assets/js/gallery-sync-tooltips.js',
        ['jquery'],
        $version,
        true
    );


    // Settings helper (exposes sanitized settings to JS)
    wp_enqueue_script(
        'gallery-sync-common-js',
        $plugin_url . 'assets/js/gallery-sync-common.js',
        ['jquery'],
        $version,
        true
    );

    // Enqueue split JS files
    wp_enqueue_script(
        'gallery-sync-js',
        $plugin_url . 'assets/js/gallery-sync.js',
        ['jquery', 'gallery-sync-common-js'],
        $version,
        true
    );

    wp_enqueue_script(
        'gallery-sync-connection-test-js',
        $plugin_url . 'assets/js/gallery-sync-connection-test.js',
        ['jquery', 'gallery-sync-common-js'],
        $version,
        true
    );

    if ($is_settings_page) {
        wp_enqueue_script(
            'gallery-sync-license-test-js',
            $plugin_url . 'assets/js/gallery-sync-license-test.js',
            ['jquery', 'gallery-sync-common-js'],
            $version,
            true
        );
    }

    // Localize both scripts
    $localized = [
        'rest'      => rest_url('gallery-sync/v1'),
        'rest_pro'  => rest_url('gallery-sync/v1'),
        'nonce'     => wp_create_nonce('wp_rest'),
        'sw'        => $plugin_url . 'assets/js/gallery-sync-sync-worker.js',
    ];
    // Localize admin REST info to all scripts
    wp_localize_script('gallery-sync-common-js', 'GallerySyncAdmin', $localized);
    wp_localize_script('gallery-sync-js', 'GallerySyncAdmin', $localized);
    wp_localize_script('gallery-sync-connection-test-js', 'GallerySyncAdmin', $localized);

    // Expose sanitized settings to JS consumers
    if (function_exists('gallery_sync_settings')) {
        $settings = gallery_sync_settings();
        $client_settings = [
            'api_base_url'    => isset($settings['api_base_url']) ? (string) $settings['api_base_url'] : '',
            'source'          => isset($settings['source']) ? (string) $settings['source'] : 'immich',
            'has_license_key' => function_exists('gallery_sync_get_license_key') && gallery_sync_get_license_key() !== '' ? 1 : 0,
        ];
        // Provide sanitized settings to helper first, then others
        wp_localize_script('gallery-sync-common-js', 'GallerySyncSettings', $client_settings);
        wp_localize_script('gallery-sync-js', 'GallerySyncSettings', $client_settings);
        wp_localize_script('gallery-sync-connection-test-js', 'GallerySyncSettings', $client_settings);
    }

    if ($is_settings_page) {
        wp_localize_script('gallery-sync-license-test-js', 'GallerySyncPro', [
            'rest' => rest_url('gallery-sync/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}
add_action('admin_enqueue_scripts', 'gallery_sync_admin_enqueue_assets');

function gallery_sync_force_relative_ajaxurl() {
    if (!is_admin()) {
        return;
    }
    $relative = admin_url('admin-ajax.php', 'relative');
    if (!$relative) {
        return;
    }
    // Force a same-origin ajaxurl to avoid CORS errors in embedded playgrounds.
    echo '<script>window.ajaxurl=' . wp_json_encode($relative) . ';</script>';
}
add_action('admin_print_footer_scripts', 'gallery_sync_force_relative_ajaxurl', 9999);

function gallery_sync_is_playground_webview(): bool {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
    $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
    $haystack = $origin . ' ' . $referer;
    return $haystack !== '' && (stripos($haystack, 'vscode-webview://') !== false || stripos($haystack, 'vscode://') !== false);
}

function gallery_sync_maybe_disable_heartbeat() {
    if (!is_admin()) {
        return;
    }
    if (!gallery_sync_is_playground_webview()) {
        return;
    }
    // Avoid CORS errors from Heartbeat in embedded VSCode webviews.
    wp_dequeue_script('heartbeat');
    wp_deregister_script('heartbeat');
}
add_action('admin_enqueue_scripts', 'gallery_sync_maybe_disable_heartbeat', 1);

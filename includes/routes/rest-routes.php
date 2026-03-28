<?php
if (!defined('ABSPATH')) exit;

function gallery_sync_register_core_routes(callable $permission_callback): void {
    register_rest_route('gallery-sync/v1', '/features', [
        'methods'             => 'GET',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            return function_exists('gallery_sync_get_features') ? gallery_sync_get_features(false) : [];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/features/refresh', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function (WP_REST_Request $request) {
            $raw_key = $request->get_param('license_key');
            if (is_string($raw_key)) {
                gallery_sync_update_license_key(sanitize_text_field($raw_key));
            }
            return function_exists('gallery_sync_refresh_features') ? gallery_sync_refresh_features() : [];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/license/validate', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function (WP_REST_Request $request) {
            if (!function_exists('gallery_sync_licensing_client')) {
                return new WP_Error('gallery_sync_licensing_missing', 'Licensing client is unavailable.', ['status' => 500]);
            }

            $raw_key = $request->get_param('license_key');
            if (is_string($raw_key)) {
                gallery_sync_update_license_key(sanitize_text_field($raw_key));
            }

            return gallery_sync_licensing_client()->validate(true);
        },
    ]);

    register_rest_route('gallery-sync/v1', '/license/checkout-session', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            if (!function_exists('gallery_sync_licensing_client')) {
                return new WP_Error('gallery_sync_licensing_missing', 'Licensing client is unavailable.', ['status' => 500]);
            }

            $result = gallery_sync_licensing_client()->create_checkout_session();
            if (!is_array($result)) {
                return new WP_Error('gallery_sync_checkout_failed', 'Checkout session could not be created.', ['status' => 500]);
            }

            if (!empty($result['error'])) {
                return new WP_Error(
                    'gallery_sync_checkout_failed',
                    is_string($result['message'] ?? null) ? $result['message'] : 'Checkout session could not be created.',
                    ['status' => 500, 'error' => $result['error']]
                );
            }

            return $result;
        },
    ]);

    register_rest_route('gallery-sync/v1', '/test', [
        'methods'             => 'GET',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('GET', '/v1/connection/test');
            if (is_wp_error($response)) {
                return new WP_Error('gallery_sync_test_failed', $response->get_error_message(), ['status' => 500]);
            }
            return is_array($response) ? $response : [];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/albums', [
        'methods'             => 'GET',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('GET', '/v1/albums');
            if (is_wp_error($response)) {
                return new WP_Error('gallery_sync_albums_failed', $response->get_error_message(), ['status' => 500]);
            }
            return is_array($response) ? $response : [];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/progress', [
        'methods'             => 'GET',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('GET', '/v1/sync/progress');
            if (is_wp_error($response)) {
                return gallery_sync_get_option_value(GALLERY_SYNC_PROGRESS_OPT, []);
            }
            if (is_array($response)) {
                gallery_sync_update_option_value(GALLERY_SYNC_PROGRESS_OPT, $response);
                return $response;
            }
            return [];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/sync-status', [
        'methods'             => 'GET',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('GET', '/v1/sync/status');
            if (is_wp_error($response)) {
                return [
                    'running' => false,
                    'progress_exists' => !empty(gallery_sync_get_option_value(GALLERY_SYNC_PROGRESS_OPT, [])),
                ];
            }
            return is_array($response) ? $response : [];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/run-sync', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $settings = gallery_sync_settings();
            $payload = [
                'source' => $settings['source'] ?? 'immich',
            ];
            $response = gallery_sync_api_request('POST', '/v1/sync/start', $payload);
            if (is_wp_error($response)) {
                return $response;
            }
            return is_array($response) ? $response : [];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/cancel', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function (WP_REST_Request $request) {
            $raw_album = $request->get_param('album');
            if (!is_string($raw_album) || trim($raw_album) === '') {
                return new WP_Error(
                    'gallery_sync_missing_album',
                    __('The "album" parameter is required.', 'gallery-sync'),
                    ['status' => 400]
                );
            }
            $album = sanitize_text_field($raw_album);
            $response = gallery_sync_api_request('POST', '/v1/sync/cancel', [
                'album' => $album,
            ]);
            if (is_wp_error($response)) {
                return $response;
            }
            return is_array($response) ? $response : ['status' => 'cancelled', 'album' => $album];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/skip-asset', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function (WP_REST_Request $request) {
            $raw_asset = $request->get_param('asset_id');
            if (!is_string($raw_asset) || trim($raw_asset) === '') {
                return new WP_Error(
                    'gallery_sync_missing_asset',
                    __('The "asset_id" parameter is required.', 'gallery-sync'),
                    ['status' => 400]
                );
            }

            $asset_id = sanitize_text_field($raw_asset);
            $response = gallery_sync_api_request('POST', '/v1/sync/skip-asset', [
                'asset_id' => $asset_id,
            ]);
            if (is_wp_error($response)) {
                return $response;
            }
            return is_array($response) ? $response : ['status' => 'skipped', 'asset_id' => $asset_id];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/cancel-sync', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('POST', '/v1/sync/cancel');
            if (is_wp_error($response)) {
                return $response;
            }
            gallery_sync_update_option_value(GALLERY_SYNC_PROGRESS_OPT, []);
            return is_array($response) ? $response : ['status' => 'cancelled'];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/reset-sync', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('POST', '/v1/sync/reset');
            if (is_wp_error($response)) {
                return $response;
            }
            gallery_sync_update_option_value(GALLERY_SYNC_PROGRESS_OPT, []);
            return is_array($response) ? $response : ['status' => 'reset_complete'];
        },
    ]);

    register_rest_route('gallery-sync/v1', '/complete', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('POST', '/v1/sync/complete');
            if (is_wp_error($response)) {
                return $response;
            }
            gallery_sync_update_option_value(GALLERY_SYNC_PROGRESS_OPT, []);
            return is_array($response) ? $response : ['status' => 'cleared'];
        },
    ]);
}

function gallery_sync_register_pro_routes(string $namespace, callable $permission_callback): void {
    register_rest_route($namespace, '/albums', [
        'methods'             => 'GET',
        'permission_callback' => $permission_callback,
        'callback'            => function () {
            $response = gallery_sync_api_request('GET', '/v1/albums');
            if (is_wp_error($response)) {
                return new WP_Error('gallery_sync_albums_failed', $response->get_error_message(), ['status' => 500]);
            }
            return is_array($response) ? $response : [];
        },
    ]);

    register_rest_route($namespace, '/license-test', [
        'methods'             => 'POST',
        'permission_callback' => $permission_callback,
        'callback'            => function (WP_REST_Request $request) {
            $raw_key = $request->get_param('license_key');
            if (!is_string($raw_key) || trim($raw_key) === '') {
                return new WP_Error(
                    'gallery_sync_missing_license_key',
                    __('The "license_key" parameter is required.', 'gallery-sync'),
                    ['status' => 400]
                );
            }

            $license_key = sanitize_text_field($raw_key);
            gallery_sync_update_license_key($license_key);

            if (!function_exists('gallery_sync_licensing_client')) {
                return new WP_Error('gallery_sync_license_failed', 'Licensing client unavailable.', ['status' => 500]);
            }

            $response = gallery_sync_licensing_client()->validate(true);
            return is_array($response) ? $response : ['valid' => false];
        },
    ]);
}

function gallery_sync_register_rest_routes(): void {
    $permission_callback = fn() => current_user_can('manage_options');

    gallery_sync_register_core_routes($permission_callback);
    gallery_sync_register_pro_routes('gallery-sync-pro/v1', $permission_callback);
}

add_action('rest_api_init', 'gallery_sync_register_rest_routes');

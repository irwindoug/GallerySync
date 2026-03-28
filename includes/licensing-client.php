<?php
if (!defined('ABSPATH')) exit;

if (!defined('GALLERY_SYNC_INSTANCE_ID_OPT')) {
    define('GALLERY_SYNC_INSTANCE_ID_OPT', 'gallery_sync_instance_id');
}

if (!defined('GALLERY_SYNC_LEGACY_INSTALL_ID_OPT')) {
    define('GALLERY_SYNC_LEGACY_INSTALL_ID_OPT', 'gallery_sync_install_id');
}

if (!defined('GALLERY_SYNC_LICENSE_STATUS_OPT')) {
    define('GALLERY_SYNC_LICENSE_STATUS_OPT', 'gallery_sync_license_status');
}

if (!defined('GALLERY_SYNC_LICENSE_VALIDATE_CACHE_PREFIX')) {
    define('GALLERY_SYNC_LICENSE_VALIDATE_CACHE_PREFIX', 'gallery_sync_validate_');
}

final class Gallery_Sync_Licensing_Client {
    private const DEFAULT_CACHE_TTL = 21600;

    public function get_instance_id(): string {
        $current = get_option(GALLERY_SYNC_INSTANCE_ID_OPT, '');
        if (is_string($current) && $this->is_uuid($current)) {
            return strtolower($current);
        }

        $legacy = get_option(GALLERY_SYNC_LEGACY_INSTALL_ID_OPT, '');
        if (is_string($legacy) && $this->is_uuid($legacy)) {
            $legacy = strtolower($legacy);
            update_option(GALLERY_SYNC_INSTANCE_ID_OPT, $legacy, false);
            return $legacy;
        }

        $instance_id = strtolower(wp_generate_uuid4());
        update_option(GALLERY_SYNC_INSTANCE_ID_OPT, $instance_id, false);
        return $instance_id;
    }

    public function normalize_domain(string $raw_site_url): string {
        $site_url = trim($raw_site_url);
        if ($site_url === '') {
            return '';
        }

        $host = wp_parse_url($site_url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }

    public function validate(bool $force = false): array {
        $license_key = function_exists('gallery_sync_get_license_key') ? trim(gallery_sync_get_license_key()) : '';
        $site_url = site_url();
        $normalized_domain = $this->normalize_domain($site_url);
        $instance_id = $this->get_instance_id();

        if ($license_key === '') {
            return $this->default_response('missing_license_key');
        }

        if (!function_exists('gallery_sync_is_api_configured') || !gallery_sync_is_api_configured()) {
            return $this->default_response('api_not_configured');
        }

        $cache_key = GALLERY_SYNC_LICENSE_VALIDATE_CACHE_PREFIX . md5(
            implode('|', [
                $license_key,
                $instance_id,
                $normalized_domain,
                (string) gallery_sync_get_api_base_url(),
            ])
        );

        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $payload = [
            'license_key' => $license_key,
            'product_slug' => apply_filters('gallery_sync_product_slug', 'gallery-sync-pro'),
            'instance_id' => $instance_id,
            'site_url' => $site_url,
        ];

        $response = gallery_sync_api_request('POST', '/validate-license', $payload, 20);
        if (is_wp_error($response)) {
            $fallback = $this->get_last_valid_if_fresh();
            if (is_array($fallback)) {
                $fallback['source'] = 'last_valid_cache';
                set_transient($cache_key, $fallback, HOUR_IN_SECONDS);
                return $fallback;
            }

            $error = $this->default_response('api_error');
            $error['error'] = $response->get_error_message();
            set_transient($cache_key, $error, MINUTE_IN_SECONDS * 10);
            return $error;
        }

        $normalized = $this->normalize_validation_response(is_array($response) ? $response : []);
        $ttl = isset($normalized['ttl']) && is_numeric($normalized['ttl']) ? (int) $normalized['ttl'] : self::DEFAULT_CACHE_TTL;
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_CACHE_TTL;
        }

        set_transient($cache_key, $normalized, $ttl);
        update_option(GALLERY_SYNC_LICENSE_STATUS_OPT, $normalized, false);

        if (!empty($normalized['valid'])) {
            $snapshot = [
                'validated_at' => time(),
                'ttl' => $ttl,
                'status' => $normalized,
            ];
            update_option('gallery_sync_last_valid_license_status', $snapshot, false);
        }

        return $normalized;
    }

    public function create_checkout_session(): array {
        if (!function_exists('gallery_sync_is_api_configured') || !gallery_sync_is_api_configured()) {
            return ['error' => 'api_not_configured'];
        }

        $payload = [
            'product_slug' => apply_filters('gallery_sync_product_slug', 'gallery-sync-pro'),
            'site_url' => site_url(),
            'success_url' => admin_url('admin.php?page=gallery-sync-settings&checkout=success'),
            'cancel_url' => admin_url('admin.php?page=gallery-sync-settings&checkout=cancelled'),
        ];

        $response = gallery_sync_api_request('POST', '/create-checkout-session', $payload, 20);
        if (is_wp_error($response)) {
            return [
                'error' => 'checkout_failed',
                'message' => $response->get_error_message(),
            ];
        }

        return is_array($response) ? $response : ['error' => 'checkout_failed'];
    }

    public function get_cached_status(): array {
        $status = get_option(GALLERY_SYNC_LICENSE_STATUS_OPT, []);
        return is_array($status) ? $status : [];
    }

    private function normalize_validation_response(array $response): array {
        $features = $response['features'] ?? [];
        if (!is_array($features)) {
            $features = [];
        }

        $default_sources = ['immich'];
        if (!isset($features['sources']) || !is_array($features['sources']) || empty($features['sources'])) {
            $features['sources'] = $default_sources;
        }

        if (!isset($features['integrations']) || !is_array($features['integrations'])) {
            $features['integrations'] = [
                'nextgen' => false,
                'envira' => false,
                'foogallery' => false,
            ];
        }

        return [
            'valid' => !empty($response['valid']),
            'plan' => isset($response['plan']) ? (string) $response['plan'] : 'free',
            'features' => $features,
            'expires_at' => !empty($response['expires_at']) ? (string) $response['expires_at'] : null,
            'ttl' => isset($response['ttl']) ? (int) $response['ttl'] : self::DEFAULT_CACHE_TTL,
            'activations_used' => isset($response['activations_used']) ? (int) $response['activations_used'] : 0,
            'max_activations' => isset($response['max_activations']) ? (int) $response['max_activations'] : 0,
            'error' => isset($response['error']) ? (string) $response['error'] : null,
            'source' => 'worker',
        ];
    }

    private function default_response(string $source): array {
        return [
            'valid' => false,
            'plan' => 'free',
            'features' => [
                'sources' => ['immich'],
                'integrations' => [
                    'nextgen' => false,
                    'envira' => false,
                    'foogallery' => false,
                ],
            ],
            'expires_at' => null,
            'ttl' => self::DEFAULT_CACHE_TTL,
            'activations_used' => 0,
            'max_activations' => 0,
            'error' => null,
            'source' => $source,
        ];
    }

    private function get_last_valid_if_fresh(): ?array {
        $snapshot = get_option('gallery_sync_last_valid_license_status', null);
        if (!is_array($snapshot)) {
            return null;
        }

        $validated_at = isset($snapshot['validated_at']) ? (int) $snapshot['validated_at'] : 0;
        $ttl = isset($snapshot['ttl']) ? (int) $snapshot['ttl'] : self::DEFAULT_CACHE_TTL;
        $status = $snapshot['status'] ?? null;

        if (!is_array($status) || empty($status['valid'])) {
            return null;
        }

        if ($validated_at <= 0 || (time() - $validated_at) > $ttl) {
            return null;
        }

        return $status;
    }

    private function is_uuid(string $value): bool {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
    }
}

function gallery_sync_licensing_client(): Gallery_Sync_Licensing_Client {
    static $instance = null;
    if ($instance instanceof Gallery_Sync_Licensing_Client) {
        return $instance;
    }

    $instance = new Gallery_Sync_Licensing_Client();
    return $instance;
}

function gallery_sync_get_instance_id(): string {
    return gallery_sync_licensing_client()->get_instance_id();
}

function gallery_sync_normalize_domain(string $site_url): string {
    return gallery_sync_licensing_client()->normalize_domain($site_url);
}

<?php
if (!defined('ABSPATH')) exit;

define('GALLERY_SYNC_FEATURES_TRANSIENT', 'gallery_sync_features_cache');

function gallery_sync_get_features(bool $force = false): array {
    if (!function_exists('gallery_sync_licensing_client')) {
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
            'ttl' => 300,
            'activations_used' => 0,
            'max_activations' => 0,
            'source' => 'missing_licensing_client',
        ];
    }

    if (!$force) {
        $cached = get_transient(GALLERY_SYNC_FEATURES_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $response = gallery_sync_licensing_client()->validate($force);
    $ttl = isset($response['ttl']) && is_numeric($response['ttl']) ? (int) $response['ttl'] : (int) HOUR_IN_SECONDS * 6;
    if ($ttl <= 0) {
        $ttl = (int) HOUR_IN_SECONDS * 6;
    }

    set_transient(GALLERY_SYNC_FEATURES_TRANSIENT, $response, $ttl);
    return is_array($response) ? $response : [];
}

function gallery_sync_refresh_features(): array {
    return gallery_sync_get_features(true);
}

function gallery_sync_is_license_valid(): bool {
    $features = gallery_sync_get_features(false);
    return !empty($features['valid']);
}

function gallery_sync_get_feature_sources(): array {
    $features = gallery_sync_get_features(false);
    $sources = $features['features']['sources'] ?? [];
    return is_array($sources) ? array_values(array_filter($sources, 'is_string')) : [];
}

function gallery_sync_is_source_enabled(string $source_key): bool {
    $sources = gallery_sync_get_feature_sources();
    return in_array($source_key, $sources, true);
}

function gallery_sync_get_feature_integrations(): array {
    $features = gallery_sync_get_features(false);
    $integrations = $features['features']['integrations'] ?? [];
    return is_array($integrations) ? $integrations : [];
}

function gallery_sync_has_feature(string $feature_key): bool {
    $features = gallery_sync_get_features(false);
    $flags = $features['features'] ?? [];
    return !empty($flags[$feature_key]);
}

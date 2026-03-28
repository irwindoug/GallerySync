INSERT INTO products (slug, name)
VALUES
  ('gallery-sync-pro', 'Gallery Sync Pro')
ON CONFLICT (slug) DO UPDATE
SET name = EXCLUDED.name;

WITH product AS (
  SELECT id FROM products WHERE slug = 'gallery-sync-pro'
)
INSERT INTO plans (product_id, slug, billing_type, stripe_price_id, max_activations, features_json, is_active)
VALUES
  (
    (SELECT id FROM product),
    'lifetime',
    'lifetime',
    'price_replace_lifetime',
    3,
    '{"sources":["immich","google-drive"],"integrations":{"nextgen":true,"envira":false,"foogallery":false}}'::jsonb,
    true
  ),
  (
    (SELECT id FROM product),
    'pro-monthly',
    'subscription',
    'price_replace_subscription_monthly',
    10,
    '{"sources":["immich","google-drive","dropbox","onedrive","google-photos","flickr","nextcloud","owncloud"],"integrations":{"nextgen":true,"envira":true,"foogallery":true}}'::jsonb,
    true
  )
ON CONFLICT (stripe_price_id) DO UPDATE
SET
  product_id = EXCLUDED.product_id,
  slug = EXCLUDED.slug,
  billing_type = EXCLUDED.billing_type,
  max_activations = EXCLUDED.max_activations,
  features_json = EXCLUDED.features_json,
  is_active = EXCLUDED.is_active;

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS products (
  id BIGSERIAL PRIMARY KEY,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS plans (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
  slug TEXT NOT NULL,
  billing_type TEXT NOT NULL CHECK (billing_type IN ('lifetime', 'subscription')),
  stripe_price_id TEXT NOT NULL UNIQUE,
  max_activations INTEGER NOT NULL CHECK (max_activations > 0),
  features_json JSONB NOT NULL DEFAULT '{}'::jsonb,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  UNIQUE (product_id, slug)
);

CREATE TABLE IF NOT EXISTS licenses (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
  plan_id BIGINT REFERENCES plans(id) ON DELETE SET NULL,
  license_lookup_digest CHAR(64) NOT NULL,
  license_verifier_hash TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'revoked', 'expired')),
  license_type TEXT NOT NULL CHECK (license_type IN ('lifetime', 'subscription')),
  stripe_customer_id TEXT,
  stripe_subscription_id TEXT UNIQUE,
  subscription_status TEXT,
  max_activations INTEGER NOT NULL CHECK (max_activations > 0),
  activations_used INTEGER NOT NULL DEFAULT 0 CHECK (activations_used >= 0),
  expires_at TIMESTAMPTZ,
  metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS activations (
  id BIGSERIAL PRIMARY KEY,
  license_id UUID NOT NULL REFERENCES licenses(id) ON DELETE CASCADE,
  instance_id UUID NOT NULL,
  normalized_domain TEXT NOT NULL,
  site_url TEXT NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  first_validated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  last_validated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  deactivated_at TIMESTAMPTZ,
  UNIQUE (license_id, instance_id)
);

CREATE TABLE IF NOT EXISTS webhook_events (
  id BIGSERIAL PRIMARY KEY,
  stripe_event_id TEXT NOT NULL,
  event_type TEXT NOT NULL,
  payload JSONB NOT NULL,
  processing_result TEXT,
  processed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGSERIAL PRIMARY KEY,
  license_id UUID REFERENCES licenses(id) ON DELETE SET NULL,
  action TEXT NOT NULL,
  actor TEXT NOT NULL DEFAULT 'system',
  details JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS licenses_lookup_digest_uidx
  ON licenses (license_lookup_digest);

CREATE UNIQUE INDEX IF NOT EXISTS webhook_events_stripe_event_uidx
  ON webhook_events (stripe_event_id);

CREATE UNIQUE INDEX IF NOT EXISTS activations_license_instance_uidx
  ON activations (license_id, instance_id);

CREATE INDEX IF NOT EXISTS activations_license_active_idx
  ON activations (license_id, is_active);

CREATE INDEX IF NOT EXISTS licenses_subscription_idx
  ON licenses (stripe_subscription_id);

CREATE INDEX IF NOT EXISTS licenses_status_idx
  ON licenses (status, expires_at);

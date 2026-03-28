import type { PoolClient } from 'pg';
import { query, withTransaction, type WorkerEnv } from './db';

interface ValidatePayload {
  license_key?: string;
  product_slug?: string;
  instance_id?: string;
  site_url?: string;
}

interface StripeEvent {
  id: string;
  type: string;
  data?: { object?: Record<string, unknown> };
}

const JSON_HEADERS = {
  'content-type': 'application/json; charset=utf-8',
};

const SUBSCRIPTION_ACTIVE_STATUSES = new Set(['active', 'trialing', 'past_due']);

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS });
}

function maskLicenseKey(licenseKey: string): string {
  const value = (licenseKey || '').trim();
  if (value.length <= 8) {
    return '****';
  }
  return `${value.slice(0, 4)}****${value.slice(-4)}`;
}

async function sha256Hex(value: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function hmacHex(secret: string, value: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );

  const digest = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(value));
  return Array.from(new Uint8Array(digest)).map((b) => b.toString(16).padStart(2, '0')).join('');
}

function timingSafeEqualsHex(a: string, b: string): boolean {
  if (a.length !== b.length) {
    return false;
  }

  let mismatch = 0;
  for (let i = 0; i < a.length; i += 1) {
    mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return mismatch === 0;
}

function normalizeDomain(siteUrl: string): string {
  try {
    const host = new URL(siteUrl).hostname.trim().toLowerCase();
    if (host.startsWith('www.')) {
      return host.slice(4);
    }
    return host;
  } catch {
    return '';
  }
}

function isUuidV4(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value);
}

async function parseJsonBody<T = Record<string, unknown>>(request: Request): Promise<T> {
  try {
    return (await request.json()) as T;
  } catch {
    return {} as T;
  }
}

function coerceString(value: unknown): string {
  return typeof value === 'string' ? value.trim() : '';
}

function normalizeStructuredError(
  code: 'invalid' | 'expired' | 'activation_limit_reached' | 'product_mismatch',
  details: Record<string, unknown> = {},
): Response {
  return jsonResponse(
    {
      valid: false,
      error: code,
      ...details,
    },
    200,
  );
}

async function createAuditLog(
  env: WorkerEnv,
  action: string,
  details: Record<string, unknown>,
  licenseId: string | null = null,
): Promise<void> {
  try {
    await query(
      env,
      `
      INSERT INTO audit_log (license_id, action, actor, details)
      VALUES ($1, $2, $3, $4::jsonb)
      `,
      [licenseId, action, 'worker', JSON.stringify(details)],
    );
  } catch {
    // Audit logging should never break main request path.
  }
}

async function verifyStripeWebhook(request: Request, secret: string): Promise<StripeEvent | null> {
  const signatureHeader = request.headers.get('stripe-signature') || '';
  const body = await request.text();

  const parts = signatureHeader.split(',').map((part) => part.trim());
  const timestamp = parts.find((part) => part.startsWith('t='))?.slice(2) || '';
  const signatures = parts.filter((part) => part.startsWith('v1=')).map((part) => part.slice(3));

  if (!timestamp || signatures.length === 0) {
    return null;
  }

  const signedPayload = `${timestamp}.${body}`;
  const expected = await hmacHex(secret, signedPayload);

  const now = Math.floor(Date.now() / 1000);
  const age = Math.abs(now - Number.parseInt(timestamp, 10));
  if (!Number.isFinite(age) || age > 300) {
    return null;
  }

  const anyMatch = signatures.some((candidate) => timingSafeEqualsHex(candidate, expected));
  if (!anyMatch) {
    return null;
  }

  try {
    return JSON.parse(body) as StripeEvent;
  } catch {
    return null;
  }
}

function generateLicenseKey(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  const token = Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join('').toUpperCase();
  return `GS-${token.slice(0, 8)}-${token.slice(8, 16)}-${token.slice(16, 24)}-${token.slice(24)}`;
}

async function getPlanByPriceId(env: WorkerEnv, priceId: string): Promise<Record<string, unknown> | null> {
  const result = await query<Record<string, unknown>>(
    env,
    `
    SELECT
      pl.id,
      pl.product_id,
      pl.slug,
      pl.billing_type,
      pl.max_activations,
      pl.features_json,
      p.slug AS product_slug
    FROM plans pl
    INNER JOIN products p ON p.id = pl.product_id
    WHERE pl.stripe_price_id = $1
      AND pl.is_active = TRUE
    LIMIT 1
    `,
    [priceId],
  );

  return result.rows[0] || null;
}

async function stripeRequest(
  env: WorkerEnv,
  method: 'GET' | 'POST',
  path: string,
  body?: URLSearchParams,
): Promise<Record<string, unknown>> {
  if (!env.STRIPE_SECRET_KEY) {
    throw new Error('STRIPE_SECRET_KEY is not configured');
  }

  const response = await fetch(`https://api.stripe.com${path}`, {
    method,
    headers: {
      Authorization: `Bearer ${env.STRIPE_SECRET_KEY}`,
      ...(body ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}),
    },
    body: body ? body.toString() : undefined,
  });

  const data = (await response.json()) as Record<string, unknown>;
  if (!response.ok) {
    const message = typeof data.error === 'object' && data.error !== null
      ? String((data.error as Record<string, unknown>).message || 'Stripe request failed')
      : 'Stripe request failed';
    throw new Error(message);
  }

  return data;
}

async function getCheckoutSessionPriceId(env: WorkerEnv, checkoutSessionId: string): Promise<string> {
  const lineItems = await stripeRequest(env, 'GET', `/v1/checkout/sessions/${checkoutSessionId}/line_items?limit=1`);
  const rows = Array.isArray(lineItems.data) ? lineItems.data : [];
  const first = rows[0] as Record<string, unknown> | undefined;
  const price = (first?.price || {}) as Record<string, unknown>;
  return coerceString(price.id);
}

async function handleCheckoutCompleted(
  env: WorkerEnv,
  eventObject: Record<string, unknown>,
): Promise<void> {
  const checkoutId = coerceString(eventObject.id);
  const subscriptionId = coerceString(eventObject.subscription);
  const customerId = coerceString(eventObject.customer);
  const metadata = (eventObject.metadata || {}) as Record<string, unknown>;

  let priceId = coerceString(metadata.stripe_price_id);
  if (!priceId && checkoutId) {
    priceId = await getCheckoutSessionPriceId(env, checkoutId);
  }
  if (!priceId) {
    throw new Error('Missing stripe price id in checkout session');
  }

  const plan = await getPlanByPriceId(env, priceId);
  if (!plan) {
    throw new Error(`No plan mapping for stripe price ${priceId}`);
  }

  const planId = Number(plan.id);
  const productId = Number(plan.product_id);
  const billingType = String(plan.billing_type || 'lifetime');
  const maxActivations = Number(plan.max_activations || 1);

  for (let attempt = 0; attempt < 4; attempt += 1) {
    const licenseKey = generateLicenseKey();
    const lookupDigest = await sha256Hex(licenseKey);
    const verifierHash = await hmacHex(env.LICENSE_HMAC_SECRET, licenseKey);

    try {
      await query(
        env,
        `
        INSERT INTO licenses (
          product_id,
          plan_id,
          license_lookup_digest,
          license_verifier_hash,
          status,
          license_type,
          stripe_customer_id,
          stripe_subscription_id,
          subscription_status,
          max_activations,
          expires_at,
          metadata
        ) VALUES (
          $1,
          $2,
          $3,
          $4,
          'active',
          $5,
          NULLIF($6, ''),
          NULLIF($7, ''),
          $8,
          $9,
          NULL,
          $10::jsonb
        )
        `,
        [
          productId,
          planId,
          lookupDigest,
          verifierHash,
          billingType,
          customerId,
          subscriptionId,
          billingType === 'subscription' ? 'active' : null,
          maxActivations,
          JSON.stringify({ checkout_session_id: checkoutId, masked_license: maskLicenseKey(licenseKey) }),
        ],
      );

      await createAuditLog(env, 'license_created_from_checkout', {
        checkout_session_id: checkoutId,
        stripe_customer_id: customerId,
        stripe_subscription_id: subscriptionId,
        masked_license: maskLicenseKey(licenseKey),
      });
      return;
    } catch (error) {
      const message = error instanceof Error ? error.message : 'unknown_error';
      if (!message.includes('licenses_license_lookup_digest_key')) {
        throw error;
      }
    }
  }

  throw new Error('Failed to generate a unique license key');
}

function unixToIsoOrNull(value: unknown): string | null {
  const seconds = Number(value);
  if (!Number.isFinite(seconds) || seconds <= 0) {
    return null;
  }
  return new Date(seconds * 1000).toISOString();
}

async function handleSubscriptionUpdate(
  env: WorkerEnv,
  eventType: string,
  eventObject: Record<string, unknown>,
): Promise<void> {
  const subscriptionId = coerceString(eventObject.id);
  if (!subscriptionId) {
    return;
  }

  const status = coerceString(eventObject.status) || 'inactive';
  const currentPeriodEnd = unixToIsoOrNull(eventObject.current_period_end);
  const items = ((eventObject.items || {}) as Record<string, unknown>).data as Array<Record<string, unknown>> | undefined;
  const primaryItem = Array.isArray(items) ? items[0] : null;
  const price = ((primaryItem || {}).price || {}) as Record<string, unknown>;
  const priceId = coerceString(price.id);
  const plan = priceId ? await getPlanByPriceId(env, priceId) : null;

  const isActive = SUBSCRIPTION_ACTIVE_STATUSES.has(status) && eventType !== 'customer.subscription.deleted';

  await query(
    env,
    `
    UPDATE licenses
    SET
      status = $2,
      subscription_status = $3,
      expires_at = CASE
        WHEN $2 = 'inactive' THEN COALESCE($4::timestamptz, NOW())
        ELSE NULL
      END,
      plan_id = COALESCE($5, plan_id),
      product_id = COALESCE($6, product_id),
      max_activations = COALESCE($7, max_activations),
      updated_at = NOW()
    WHERE stripe_subscription_id = $1
    `,
    [
      subscriptionId,
      isActive ? 'active' : 'inactive',
      status,
      currentPeriodEnd,
      plan ? Number(plan.id) : null,
      plan ? Number(plan.product_id) : null,
      plan ? Number(plan.max_activations) : null,
    ],
  );

  await createAuditLog(env, 'subscription_status_updated', {
    stripe_subscription_id: subscriptionId,
    stripe_status: status,
    license_status: isActive ? 'active' : 'inactive',
  });
}

async function handleStripeWebhook(request: Request, env: WorkerEnv): Promise<Response> {
  if (!env.STRIPE_WEBHOOK_SECRET) {
    return jsonResponse({ error: 'stripe_webhook_secret_missing' }, 500);
  }

  const event = await verifyStripeWebhook(request, env.STRIPE_WEBHOOK_SECRET);
  if (!event) {
    return jsonResponse({ error: 'invalid_signature' }, 400);
  }

  const inserted = await query(
    env,
    `
    INSERT INTO webhook_events (stripe_event_id, event_type, payload, processing_result)
    VALUES ($1, $2, $3::jsonb, 'received')
    ON CONFLICT (stripe_event_id) DO NOTHING
    RETURNING id
    `,
    [event.id, event.type, JSON.stringify(event)],
  );

  if (inserted.rowCount === 0) {
    return jsonResponse({ status: 'ignored', reason: 'duplicate_event' });
  }

  try {
    const eventObject = (event.data?.object || {}) as Record<string, unknown>;

    if (event.type === 'checkout.session.completed') {
      await handleCheckoutCompleted(env, eventObject);
    }

    if (
      event.type === 'customer.subscription.created' ||
      event.type === 'customer.subscription.updated' ||
      event.type === 'customer.subscription.deleted'
    ) {
      await handleSubscriptionUpdate(env, event.type, eventObject);
    }

    await query(
      env,
      `
      UPDATE webhook_events
      SET processed_at = NOW(), processing_result = 'processed'
      WHERE stripe_event_id = $1
      `,
      [event.id],
    );

    return jsonResponse({ status: 'ok' });
  } catch (error) {
    const message = error instanceof Error ? error.message : 'unknown_error';

    await query(
      env,
      `
      UPDATE webhook_events
      SET processed_at = NOW(), processing_result = $2
      WHERE stripe_event_id = $1
      `,
      [event.id, `failed:${message.slice(0, 180)}`],
    );

    return jsonResponse({ error: 'webhook_processing_failed' }, 500);
  }
}

async function getLicenseRow(
  env: WorkerEnv,
  licenseLookupDigest: string,
  verifierHash: string,
): Promise<Record<string, unknown> | null> {
  const result = await query<Record<string, unknown>>(
    env,
    `
    SELECT
      l.id,
      l.status,
      l.license_type,
      l.subscription_status,
      l.expires_at,
      l.max_activations,
      l.plan_id,
      p.slug AS product_slug,
      pl.slug AS plan_slug,
      COALESCE(pl.features_json, '{}'::jsonb) AS features_json
    FROM licenses l
    INNER JOIN products p ON p.id = l.product_id
    LEFT JOIN plans pl ON pl.id = l.plan_id
    WHERE l.license_lookup_digest = $1
      AND l.license_verifier_hash = $2
    LIMIT 1
    `,
    [licenseLookupDigest, verifierHash],
  );

  return result.rows[0] || null;
}

async function upsertActivation(
  client: PoolClient,
  licenseId: string,
  instanceId: string,
  normalizedDomain: string,
  siteUrl: string,
  maxActivations: number,
): Promise<{ activationsUsed: number; limitReached: boolean }> {
  await client.query('SELECT id FROM licenses WHERE id = $1 FOR UPDATE', [licenseId]);

  const existing = await client.query<{ normalized_domain: string }>(
    `
    SELECT normalized_domain
    FROM activations
    WHERE license_id = $1
      AND instance_id = $2
    LIMIT 1
    `,
    [licenseId, instanceId],
  );

  if (existing.rowCount > 0) {
    await client.query(
      `
      UPDATE activations
      SET
        normalized_domain = $3,
        site_url = $4,
        is_active = TRUE,
        deactivated_at = NULL,
        last_validated_at = NOW()
      WHERE license_id = $1
        AND instance_id = $2
      `,
      [licenseId, instanceId, normalizedDomain, siteUrl],
    );
  } else {
    const usage = await client.query<{ count: string }>(
      `
      SELECT COUNT(*)::text AS count
      FROM activations
      WHERE license_id = $1
        AND is_active = TRUE
      `,
      [licenseId],
    );

    const activeCount = Number(usage.rows[0]?.count || 0);
    if (activeCount >= maxActivations) {
      return { activationsUsed: activeCount, limitReached: true };
    }

    await client.query(
      `
      INSERT INTO activations (
        license_id,
        instance_id,
        normalized_domain,
        site_url,
        is_active,
        first_validated_at,
        last_validated_at
      )
      VALUES ($1, $2, $3, $4, TRUE, NOW(), NOW())
      ON CONFLICT (license_id, instance_id)
      DO UPDATE SET
        normalized_domain = EXCLUDED.normalized_domain,
        site_url = EXCLUDED.site_url,
        is_active = TRUE,
        deactivated_at = NULL,
        last_validated_at = NOW()
      `,
      [licenseId, instanceId, normalizedDomain, siteUrl],
    );
  }

  const usage = await client.query<{ count: string }>(
    `
    SELECT COUNT(*)::text AS count
    FROM activations
    WHERE license_id = $1
      AND is_active = TRUE
    `,
    [licenseId],
  );

  const activationsUsed = Number(usage.rows[0]?.count || 0);

  await client.query(
    `
    UPDATE licenses
    SET activations_used = $2, updated_at = NOW()
    WHERE id = $1
    `,
    [licenseId, activationsUsed],
  );

  return { activationsUsed, limitReached: false };
}

function isExpired(expiresAtValue: unknown): boolean {
  if (!expiresAtValue) {
    return false;
  }
  const timestamp = new Date(String(expiresAtValue)).getTime();
  if (Number.isNaN(timestamp)) {
    return false;
  }
  return timestamp <= Date.now();
}

async function handleValidateLicense(request: Request, env: WorkerEnv): Promise<Response> {
  const payload = await parseJsonBody<ValidatePayload>(request);

  const licenseKey = coerceString(payload.license_key);
  const instanceId = coerceString(payload.instance_id).toLowerCase();
  const siteUrl = coerceString(payload.site_url);
  const requestedProduct = coerceString(payload.product_slug) || coerceString(env.PRODUCT_SLUG);
  const normalizedDomain = normalizeDomain(siteUrl);

  if (!licenseKey || !instanceId || !siteUrl || !normalizedDomain) {
    return normalizeStructuredError('invalid', { reason: 'missing_required_fields' });
  }

  if (!isUuidV4(instanceId)) {
    return normalizeStructuredError('invalid', { reason: 'instance_id_must_be_uuid_v4' });
  }

  const licenseLookupDigest = await sha256Hex(licenseKey);
  const verifierHash = await hmacHex(env.LICENSE_HMAC_SECRET, licenseKey);
  const license = await getLicenseRow(env, licenseLookupDigest, verifierHash);

  if (!license) {
    await createAuditLog(env, 'license_validation_failed', {
      reason: 'license_not_found',
      masked_license: maskLicenseKey(licenseKey),
      normalized_domain: normalizedDomain,
    });
    return normalizeStructuredError('invalid');
  }

  const licenseId = String(license.id);
  const licenseStatus = String(license.status || 'inactive');
  const licenseType = String(license.license_type || 'lifetime');
  const subscriptionStatus = coerceString(license.subscription_status);
  const planSlug = coerceString(license.plan_slug) || 'unknown';
  const maxActivations = Number(license.max_activations || 1);
  const productSlug = coerceString(license.product_slug);
  const expiresAt = license.expires_at ? new Date(String(license.expires_at)).toISOString() : null;

  if (requestedProduct && productSlug && requestedProduct !== productSlug) {
    return normalizeStructuredError('product_mismatch');
  }

  if (licenseStatus !== 'active') {
    return normalizeStructuredError('invalid');
  }

  if (licenseType === 'subscription' && !SUBSCRIPTION_ACTIVE_STATUSES.has(subscriptionStatus)) {
    return normalizeStructuredError('invalid');
  }

  if (isExpired(expiresAt)) {
    return normalizeStructuredError('expired', { expires_at: expiresAt });
  }

  const activation = await withTransaction(env, async (client) => {
    return upsertActivation(client, licenseId, instanceId, normalizedDomain, siteUrl, maxActivations);
  });

  if (activation.limitReached) {
    return normalizeStructuredError('activation_limit_reached', {
      activations_used: activation.activationsUsed,
      max_activations: maxActivations,
    });
  }

  await createAuditLog(env, 'license_validated', {
    plan: planSlug,
    license_type: licenseType,
    normalized_domain: normalizedDomain,
    masked_license: maskLicenseKey(licenseKey),
  }, licenseId);

  return jsonResponse({
    valid: true,
    plan: planSlug,
    product_slug: productSlug,
    license_type: licenseType,
    expires_at: expiresAt,
    activations_used: activation.activationsUsed,
    max_activations: maxActivations,
    features: license.features_json || {},
    ttl: 21600,
  });
}

async function getPlanForCheckout(
  env: WorkerEnv,
  productSlug: string,
  requestedPlanSlug: string,
): Promise<Record<string, unknown> | null> {
  const params: unknown[] = [productSlug];
  let where = 'p.slug = $1 AND pl.is_active = TRUE';
  if (requestedPlanSlug) {
    params.push(requestedPlanSlug);
    where += ` AND pl.slug = $${params.length}`;
  }

  const result = await query<Record<string, unknown>>(
    env,
    `
    SELECT
      pl.id,
      pl.slug,
      pl.billing_type,
      pl.stripe_price_id,
      p.slug AS product_slug
    FROM plans pl
    INNER JOIN products p ON p.id = pl.product_id
    WHERE ${where}
    ORDER BY pl.id ASC
    LIMIT 1
    `,
    params,
  );

  return result.rows[0] || null;
}

function adminFallbackUrl(request: Request): string {
  const requestUrl = new URL(request.url);
  return `${requestUrl.origin}/wp-admin/admin.php?page=gallery-sync-settings`;
}

async function handleCreateCheckoutSession(request: Request, env: WorkerEnv): Promise<Response> {
  const payload = await parseJsonBody<Record<string, unknown>>(request);

  const productSlug = coerceString(payload.product_slug) || coerceString(env.PRODUCT_SLUG) || 'gallery-sync-pro';
  const planSlug = coerceString(payload.plan_slug);
  const siteUrl = coerceString(payload.site_url);
  const normalizedDomain = normalizeDomain(siteUrl);
  const successUrl = coerceString(payload.success_url) || adminFallbackUrl(request);
  const cancelUrl = coerceString(payload.cancel_url) || adminFallbackUrl(request);

  const plan = await getPlanForCheckout(env, productSlug, planSlug);
  if (!plan) {
    return jsonResponse({ error: 'plan_not_found' }, 404);
  }

  const billingType = String(plan.billing_type || 'lifetime');
  const stripePriceId = String(plan.stripe_price_id || '');
  if (!stripePriceId) {
    return jsonResponse({ error: 'stripe_price_missing' }, 500);
  }

  const form = new URLSearchParams();
  form.set('mode', billingType === 'subscription' ? 'subscription' : 'payment');
  form.set('line_items[0][price]', stripePriceId);
  form.set('line_items[0][quantity]', '1');
  form.set('success_url', successUrl);
  form.set('cancel_url', cancelUrl);
  form.set('allow_promotion_codes', 'true');
  form.set('metadata[product_slug]', productSlug);
  form.set('metadata[plan_slug]', String(plan.slug || ''));
  form.set('metadata[stripe_price_id]', stripePriceId);
  form.set('metadata[normalized_domain]', normalizedDomain);

  const session = await stripeRequest(env, 'POST', '/v1/checkout/sessions', form);
  const checkoutUrl = coerceString(session.url);
  if (!checkoutUrl) {
    return jsonResponse({ error: 'checkout_session_missing_url' }, 500);
  }

  return jsonResponse({
    checkout_url: checkoutUrl,
    product_slug: productSlug,
    plan_slug: String(plan.slug || ''),
  });
}

async function handleHealth(env: WorkerEnv): Promise<Response> {
  try {
    await query(env, 'SELECT 1');
    return jsonResponse({ ok: true, service: 'gallery-sync-license-worker', database: 'up' });
  } catch {
    return jsonResponse({ ok: false, service: 'gallery-sync-license-worker', database: 'down' }, 503);
  }
}

export default {
  async fetch(request: Request, env: WorkerEnv): Promise<Response> {
    const url = new URL(request.url);

    try {
      if (request.method === 'GET' && url.pathname === '/health') {
        return handleHealth(env);
      }

      if (request.method === 'POST' && url.pathname === '/stripe/webhook') {
        return handleStripeWebhook(request, env);
      }

      if (request.method === 'POST' && url.pathname === '/validate-license') {
        return handleValidateLicense(request, env);
      }

      if (request.method === 'POST' && url.pathname === '/create-checkout-session') {
        return handleCreateCheckoutSession(request, env);
      }

      return jsonResponse({ error: 'not_found' }, 404);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'unknown_error';
      await createAuditLog(env, 'request_failed', { path: url.pathname, message: message.slice(0, 180) });
      return jsonResponse({ error: 'internal_error' }, 500);
    }
  },
};

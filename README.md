# Gallery Sync

Open-source WordPress plugin that syncs photos and videos from external libraries
into your WordPress media library, with a Cloudflare Worker license server,
Supabase Postgres persistence, and Stripe billing.

## What it does

- Syncs media from external sources into WordPress (Dropbox, Google Drive,
  Google Photos, Flickr, Immich, Nextcloud, OneDrive, ownCloud, and more).
- Gates premium features against a license validated by a Cloudflare Worker API.
- Handles checkout and subscription/lifetime licensing through Stripe.

## Architecture

- **WordPress plugin (PHP):** an untrusted client. Stores a per-site
  `gallery_sync_instance_id` (UUID v4) and an encrypted license key, calls the
  Worker over HTTPS for `/validate-license` and `/create-checkout-session`, and
  caches the last valid response to reduce API calls. No paid business logic
  lives in the plugin — forking it never unlocks premium features.
- **Cloudflare Worker (`worker/`, TypeScript):** the source of truth for
  licenses and activations. Verifies Stripe webhook signatures and enforces
  `(instance_id + normalized_domain)` against `max_activations`. Supports
  `lifetime` and `subscription` license types.
- **Supabase Postgres:** stores products, plans, licenses, activations, webhook
  events, and audit logs. License keys are never stored in plaintext — only
  `license_lookup_digest` + `license_verifier_hash`.

## Worker routes

- `GET /health`
- `POST /stripe/webhook`
- `POST /validate-license`
- `POST /create-checkout-session`

## Screenshots

<!-- TODO: add admin UI + integrations screenshots here.
     Source imagery lives in assets/img/. -->

## Quickstart for forks

1. **Create Cloudflare resources** — an API token with Workers deploy
   permissions and your `CLOUDFLARE_ACCOUNT_ID`.
2. **Create a Supabase project** — save the Postgres connection string as
   `SUPABASE_DB_URL` (or `DATABASE_URL`) in GitHub Actions secrets.
3. **Create Stripe products/prices** — update
   `supabase/seeds/001_products_plans.sql` with your real `stripe_price_id`s.
4. **Add GitHub Actions secrets:** `CLOUDFLARE_API_TOKEN`,
   `CLOUDFLARE_ACCOUNT_ID`, `SUPABASE_DB_URL`. Optional for the webhook-setup
   workflow: `STRIPE_SECRET_KEY`, `WORKER_PUBLIC_URL`.
5. **Set Cloudflare Worker runtime secrets:** `DATABASE_URL`,
   `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `LICENSE_HMAC_SECRET`.
6. **Set your KV namespace ids** in `workers/wrangler.toml` (placeholders are
   provided — create them with `wrangler kv:namespace create ...`).
7. **Run the workflows** from the Actions tab: `DB Migrate`, then `Deploy
   Worker`. Optionally run `Stripe Webhook Setup`.
8. **Configure the Stripe webhook** at `https://<your-worker-domain>/stripe/webhook`
   for `checkout.session.completed` and the `customer.subscription.*` events.
9. **Configure the WordPress plugin** — set the Worker API base URL, enter a
   license key, and click **Validate Now**.

## Local development

Worker:

```bash
cd worker
npm install
npm run typecheck
npm run dev
npm run deploy
```

Database migrations:

```bash
psql "$DATABASE_URL" -f supabase/migrations/0001_init.sql
psql "$DATABASE_URL" -f supabase/seeds/001_products_plans.sql
```

An optional `scripts/bootstrap.sh` uses the `gh` CLI to set the common GitHub
Actions secrets in your fork.

## Tech stack

WordPress / PHP · Cloudflare Workers (TypeScript, Wrangler) · Supabase Postgres · Stripe

## Contributing

Branch from `main`, open a pull request, and let CI run. Never commit literal
secrets — use GitHub Actions secrets and Cloudflare Worker runtime secrets.

## License

See [LICENSE](LICENSE).

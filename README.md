# Gallery Sync

Open-source WordPress plugin with a Cloudflare Worker license server, Supabase Postgres persistence, and Stripe billing webhooks.

## What this repo now includes

- WordPress plugin UI/client for license validation and premium feature gating.
- New Cloudflare Worker in `worker/` (TypeScript, deployed with Wrangler).
- Supabase SQL migrations and seed files in `supabase/`.
- GitHub Actions for CI, worker deploy, DB migrations, and optional Stripe webhook setup.
- Legacy sync worker in `workers/` remains untouched for backward compatibility.

## Architecture

- WordPress plugin:
  - Stores `gallery_sync_instance_id` (UUID v4) once per site.
  - Stores encrypted `gallery_sync_license_key` in options.
  - Calls Worker over HTTPS for `/validate-license` and `/create-checkout-session`.
  - Caches last valid license response to reduce API calls.
- Worker (`worker/`):
  - Source of truth for licenses and activations.
  - Verifies Stripe webhook signatures.
  - Enforces `(instance_id + normalized_domain)` and `max_activations`.
  - Supports `lifetime` and `subscription` license types.
- Supabase Postgres:
  - Stores products, plans, licenses, activations, webhook events, and audit logs.
  - Uses `license_lookup_digest` + `license_verifier_hash` (no plaintext license keys stored).

## Worker routes

- `GET /health`
- `POST /stripe/webhook`
- `POST /validate-license`
- `POST /create-checkout-session`

## Quickstart for forks

1. Create Cloudflare resources.
- Create a Cloudflare API token with Workers deploy permissions.
- Get your `CLOUDFLARE_ACCOUNT_ID`.

2. Create Supabase project.
- Get the Postgres connection string.
- Save it as `SUPABASE_DB_URL` (or `DATABASE_URL`) in GitHub Actions secrets.

3. Create Stripe product and prices.
- Create one-time and/or subscription prices.
- Update `supabase/seeds/001_products_plans.sql` with your real `stripe_price_id` values.

4. Add GitHub Actions secrets.
- `CLOUDFLARE_API_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`
- `SUPABASE_DB_URL` (or `DATABASE_URL`)
- Optional for webhook setup workflow: `STRIPE_SECRET_KEY`, `WORKER_PUBLIC_URL`

5. Set Cloudflare Worker runtime secrets.
- `DATABASE_URL`
- `STRIPE_SECRET_KEY`
- `STRIPE_WEBHOOK_SECRET`
- `LICENSE_HMAC_SECRET`

6. Run workflows from Actions.
- Run `DB Migrate`.
- Run `Deploy Worker`.
- Optional: run `Stripe Webhook Setup` or create webhook manually.

7. Configure Stripe webhook endpoint.
- Endpoint URL: `https://<your-worker-domain>/stripe/webhook`
- Events:
  - `checkout.session.completed`
  - `customer.subscription.created`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`

8. Configure WordPress plugin settings.
- Set Worker API Base URL (HTTPS).
- Enter license key.
- Click `Validate Now`.
- Use `Purchase / Upgrade` to open checkout session URL.

## Local development commands

- Worker:
  - `cd worker`
  - `npm install`
  - `npm run typecheck`
  - `npm run dev`
  - `npm run deploy`
- Database migrations (local):
  - `psql "$DATABASE_URL" -f supabase/migrations/0001_init.sql`
  - `psql "$DATABASE_URL" -f supabase/seeds/001_products_plans.sql`

## Bootstrap helper

- Optional script: `scripts/bootstrap.sh`
- Uses `gh` CLI to set common GitHub secrets in your fork.

## CI/CD pipeline

- **CI**: shared template from [`ops/ci-templates`](https://gitlab.uplinksync.com/ops/ci-templates) — php lint, phpcs/phpunit via composer scripts, gitleaks secret scan, esbuild asset build, per-MR review app with the plugin active.
- **Previews**: every MR gets a disposable WordPress at `https://<branch-slug>.preview.uplinksync.com`
  ("View app" on the MR; basic-auth credentials in Vault `secret/ai/tooling/preview-basic-auth`).
- **Merges**: `main` is protected; merging requires a green pipeline; humans merge, agents don't.
- **GitHub**: CI-verified `main` auto-mirrors to [`github.com/irwindoug/GallerySync`](https://github.com/irwindoug/GallerySync).

## Contributing

Branch from `main` (Paperclip agents: `paperclip/<task-id>`), open an MR, let the pipeline
run, use the preview link to check the result. Never commit literal secrets.

Full runbook: `dirwin/ecosystem` → `docs/103-detail-gitlab-github-hostinger-pipeline.md`.

## Known issues

- **Plugin cannot activate from this repo (missing module).**
  `includes/admin/loader.php` line 4 requires `includes/helpers/loader.php`, but the
  `includes/helpers/` directory has never been committed (verified against full git
  history, first commit 2026-02-03). Activation fatals immediately; CI previews skip
  activation via `PREVIEW_SKIP_ACTIVATE` until the helpers module is committed from
  the original working copy. Remove that flag in `.gitlab-ci.yml` when fixed.

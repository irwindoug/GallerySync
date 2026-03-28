#!/usr/bin/env bash
set -euo pipefail

if ! command -v gh >/dev/null 2>&1; then
  echo "gh CLI is required. Install it from https://cli.github.com/"
  exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
  echo "Authenticate first: gh auth login"
  exit 1
fi

prompt_secret() {
  local key="$1"
  local prompt="$2"
  local value=""
  read -r -p "$prompt: " value
  if [[ -n "$value" ]]; then
    gh secret set "$key" --body "$value"
    echo "Saved secret: $key"
  else
    echo "Skipped: $key"
  fi
}

printf "\nGallery Sync fork bootstrap\n\n"

echo "GitHub Actions secrets"
prompt_secret "CLOUDFLARE_API_TOKEN" "Enter CLOUDFLARE_API_TOKEN"
prompt_secret "CLOUDFLARE_ACCOUNT_ID" "Enter CLOUDFLARE_ACCOUNT_ID"
prompt_secret "SUPABASE_DB_URL" "Enter SUPABASE_DB_URL"
prompt_secret "STRIPE_SECRET_KEY" "Enter STRIPE_SECRET_KEY (optional; for webhook setup workflow)"
prompt_secret "WORKER_PUBLIC_URL" "Enter WORKER_PUBLIC_URL (optional; for webhook setup workflow)"

cat <<'NEXT'

Next steps:
1. Add runtime secrets to Cloudflare Worker:
   - DATABASE_URL
   - STRIPE_SECRET_KEY
   - STRIPE_WEBHOOK_SECRET
   - LICENSE_HMAC_SECRET
2. Run GitHub Actions workflow: DB Migrate
3. Run GitHub Actions workflow: Deploy Worker
4. Configure Stripe webhook endpoint at /stripe/webhook
NEXT

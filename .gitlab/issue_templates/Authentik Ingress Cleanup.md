## Summary

Clean up Authentik ingress and proxy configuration so routes are explicit, minimal, and aligned with current auth requirements.

## Scope

- Affected ingress entrypoints:
- Affected hostnames:
- Deployment source path:
- Reverse proxy path(s):
- Authentik component(s):

## Cleanup Checklist

- [ ] Inventory every current Authentik ingress route and hostname.
- [ ] Remove stale hostnames, duplicate routes, and deprecated outpost paths.
- [ ] Confirm upstream targets and ports are still correct.
- [ ] Verify TLS termination responsibilities are documented.
- [ ] Confirm whether each route requires `auth_basic`, SSO, or no additional auth.
- [ ] Remove ingress config that points to services no longer in use.
- [ ] Check for overlapping or conflicting proxy rules in nginx-proxy-manager.
- [ ] Update service inventory public exposure metadata if any route changes.

## Validation

- [ ] Authentik UI is reachable on the intended hostname(s).
- [ ] Login and callback flows succeed end to end.
- [ ] No orphaned ingress routes remain in tracked config.
- [ ] Proxy and Authentik logs show clean upstream/auth behaviour after deploy.

## Rollback

- Previous ingress config path:
- Previous route set:
- Rollback steps:
- Post-rollback validation:

## Done Criteria

- [ ] Only active, justified Authentik ingress routes remain.
- [ ] Route ownership and exposure are documented.
- [ ] Follow-up cleanup items are captured if any legacy routes must remain temporarily.

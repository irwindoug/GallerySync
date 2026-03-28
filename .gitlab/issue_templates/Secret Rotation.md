## Summary

Rotate exposed, weak, or unmanaged secrets for the affected service(s).

## Scope

- Service(s):
- Environment(s):
- Current secret source:
- Target secret source:
- Trigger for rotation:

## Affected Paths

- Deployment source path:
- Config path(s):
- Secret reference path(s):
- Target repo path:

## Implementation Checklist

- [ ] Inventory the secret names and where each one is consumed.
- [ ] Confirm the blast radius if the current secret is already compromised.
- [ ] Generate replacement credentials with the correct scope and expiration.
- [ ] Update the deployment source to consume the rotated values.
- [ ] Remove any inline secret material from tracked config.
- [ ] Confirm dependent services or jobs are updated in the same change window.
- [ ] Restart or redeploy only the components that require the new credentials.
- [ ] Validate authentication, background jobs, and webhook/API consumers after rotation.
- [ ] Record the new secret source of truth and ownership.
- [ ] Revoke or delete the old secret material after cutover validation.

## Validation

- [ ] Health checks pass after rotation.
- [ ] Login/API flows work with the rotated secret.
- [ ] No old secret references remain in repo-tracked config.
- [ ] `policy/secret-audit.yml` patterns still cover this secret class if applicable.

## Rollback

- Rollback trigger:
- Rollback source:
- Rollback steps:
- Post-rollback validation:

## Done Criteria

- [ ] New secret is active.
- [ ] Old secret is revoked or scheduled for revocation.
- [ ] Ownership and source-of-truth are documented.
- [ ] Follow-up issues are created for any remaining secret debt.

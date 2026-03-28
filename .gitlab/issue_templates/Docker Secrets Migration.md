## Summary

Migrate a service from inline environment secrets or bind-mounted secret files to Docker secrets.

## Scope

- Service(s):
- Environment(s):
- Current deployment source path:
- Current secret delivery method:
- Target Docker secret names:

## Pre-Work

- [ ] Identify every secret currently passed through `.env`, compose `environment`, or bind mounts.
- [ ] Confirm the runtime supports Docker secrets for the target workload.
- [ ] Confirm the application can read secrets from files or secret mounts.
- [ ] Identify any init/startup scripts that need to map secret files into env vars.

## Implementation Checklist

- [ ] Define Docker secret objects in the deployment source.
- [ ] Remove inline secret values from compose or config files.
- [ ] Update service definitions to mount or reference Docker secrets.
- [ ] Update startup/config logic to read secret files instead of raw env vars.
- [ ] Keep non-secret configuration in env/config files and move only secret material.
- [ ] Update documentation for secret creation, rotation, and recovery.
- [ ] Validate secret file permissions and mount paths.

## Validation

- [ ] Service starts successfully with Docker secrets enabled.
- [ ] Login/API/database connectivity still works.
- [ ] Secret values are no longer visible in compose files or repo-tracked env files.
- [ ] Dangerous mount usage was not added as part of the migration.

## Rollback

- Rollback path:
- Previous env/config source:
- Steps to revert:
- Validation after revert:

## Done Criteria

- [ ] Inline secret material is removed from tracked config.
- [ ] Docker secrets are the only runtime secret source for the migrated service.
- [ ] Runbooks reflect the new secret creation and rotation flow.

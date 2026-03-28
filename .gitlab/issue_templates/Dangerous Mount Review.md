## Summary

Review and either remove or explicitly justify dangerous host bind mounts.

## Scope

- Service(s):
- Deployment source path:
- Mount source(s):
- Environment(s):

## Review Checklist

- [ ] List each dangerous mount and the service that consumes it.
- [ ] Confirm whether the mount is still required.
- [ ] Identify whether a named volume, API call, or narrower path can replace it.
- [ ] Document the exact capability gained by the mount.
- [ ] Assess host compromise blast radius if the container is exploited.
- [ ] Check whether a `policy-exception: dangerous-mount` comment exists and is still justified.
- [ ] Record whether the mount is temporary, permanent, or removable.

## Decision Per Mount

- Mount:
- Keep / replace / remove:
- Justification:
- Safer alternative:
- Follow-up issue:

## Implementation Checklist

- [ ] Remove unnecessary mounts from the deployment source.
- [ ] Replace broad host paths with safer alternatives where possible.
- [ ] Add or update explicit exception comments for any remaining dangerous mounts.
- [ ] Update runbooks if the operational workflow changes.

## Validation

- [ ] Service still functions after mount changes.
- [ ] No new dangerous mounts were introduced elsewhere.
- [ ] `policy/mount-policy.yml` still reflects the intended review standard.

## Done Criteria

- [ ] Every dangerous mount has a keep/remove decision.
- [ ] Remaining exceptions are justified in config and in the issue.
- [ ] Follow-up work is created for mounts that cannot be removed immediately.

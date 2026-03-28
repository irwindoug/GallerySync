## Summary

Evaluate and, if approved, plan a service for migration to Vault-backed secret delivery.

## Candidate Details

- Service:
- Environment:
- Current secret source:
- Deployment source path:
- Owner:

## Evaluation Checklist

- [ ] List all secrets used by the service and their current source of truth.
- [ ] Identify which secrets are shared across services and which are service-specific.
- [ ] Estimate rotation frequency and audit requirements.
- [ ] Document whether the service can consume dynamic or short-lived credentials.
- [ ] Determine whether Vault agent, sidecar, template rendering, or direct API access is the best fit.
- [ ] Confirm network reachability and auth path from the workload to Vault.
- [ ] Identify bootstrap secret requirements and chicken-and-egg concerns.
- [ ] Record operational risks if Vault is unavailable.

## Decision

- Candidate status: proposed / approved / rejected
- Recommended integration pattern:
- Required prerequisite work:
- Blocking risks:

## Implementation Follow-Ups

- [ ] Create a dedicated implementation issue if approved.
- [ ] Update service inventory ownership and secret source metadata.
- [ ] Document rollback to the current secret delivery method.

## Done Criteria

- [ ] A clear go/no-go decision is recorded.
- [ ] The chosen integration pattern is documented.
- [ ] Any follow-up implementation work is captured as linked issues.

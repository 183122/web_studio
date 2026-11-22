# Incident response

## Severity
- **SEV1** data exposure across tenants, payment/billing corruption, platform down → page on-call immediately, incident commander assigned.
- **SEV2** a major feature down (publishing, AI, Google sync) or many published sites failing.
- **SEV3** degraded performance, single-tenant issue.

## First 15 minutes
1. Acknowledge, open an incident channel, name an incident commander.
2. Check `/api/health`, `/api/internal/metrics` (failed jobs, dead deliveries/syncs, failed deployments), queue depth, error logs filtered by `request_id` from user reports (every API error returns `error.requestId`).
3. Stabilize: roll back the last deploy (see `deploy-rollback.md`), pause queues (`php artisan queue:pause` per queue), disable an integration via config if it is the source.
4. Security incidents: preserve evidence (logs, DB snapshot) before changing anything; rotate affected secrets; run `php artisan studio:audit:verify`.

## Communication
Status page update within 30 min for SEV1/2; customer notification for data incidents. PDPA: notify the PDPC within 72 hours of becoming aware of a personal-data breach that risks rights/freedoms, and affected individuals without delay when the risk is high.

## After
Blameless postmortem within 5 business days: timeline, impact, root cause, what went well, action items with owners.

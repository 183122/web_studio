# Backup and restore

## What to back up
| Data | Where | Method | Frequency / retention |
|---|---|---|---|
| MySQL (all tenants, app records, audit chain) | managed MySQL | provider snapshots + `mysqldump --single-transaction --routines` to encrypted object storage | snapshots hourly (7 d), dumps daily (35 d), monthly (12 mo) |
| Tenant assets (`storage/app/tenant-assets`, object storage in prod) | object storage | versioned bucket + cross-region replication | continuous; object versions 30 d |
| Secrets | deployment secret store | provider backup; `APP_KEY` escrowed offline (needed to decrypt tokens/secrets) | on change |

`APP_KEY` loss = all encrypted columns (Google tokens, BYOK keys, OAuth client secret, webhook secrets, 2FA secrets) become unreadable. Escrow it separately from database backups.

## Restore drill (quarterly, recorded)
1. Provision an isolated environment (no public DNS, `QUEUE_CONNECTION=sync`, mail to log, `BILLING_PROVIDER=test`, webhooks disabled by blocking egress).
2. Restore the latest dump: `mysql < dump.sql`; restore assets from the bucket snapshot of the same time.
3. Set the escrowed `APP_KEY`; run `php artisan migrate --force` (must be a no-op or forward-only).
4. Verify:
   - `php artisan studio:audit:verify` → "audit chain intact"
   - `curl /api/health` → 200
   - sign in with a drill account; open a project, a published site (via `Host` header), an invoice print view
   - row counts vs. production metrics (`/api/internal/metrics`)
5. Record RPO (age of the newest restored data) and RTO (start → verified). Targets: RPO ≤ 1 h, RTO ≤ 4 h.
6. Destroy the drill environment.

## Point-in-time recovery for a single tenant
Restore to a scratch database, export the tenant's rows (`organization_id` / `project_id` filters), review with the customer, then import with new ids if needed. Never overwrite other tenants.

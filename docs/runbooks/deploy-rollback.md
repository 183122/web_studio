# Deploy and rollback

## Deploy (production deployment requires explicit approval)
1. CI green (typecheck, unit, PHPUnit on MySQL, E2E, security scans).
2. Build immutable images (`infra/docker/*`), tag with the git SHA.
3. Migrations must be backward compatible with the previous release (expand → migrate → contract). Destructive migrations need separate approval and a backup taken immediately before.
4. Run `php artisan migrate --force` from a one-off container, then roll out web/api/worker/scheduler.
5. Smoke: `/api/health` 200, sign-in, open the editor, publish a canary project, open a published site, Sheets/Drive with the canary workspace, billing page loads.

## Rollback
Redeploy the previous image tag. Migrations are not rolled back automatically: if the new schema is incompatible, restore from the pre-deploy backup only after approval (data written since then would be lost — prefer a forward fix).

Published sites are unaffected by platform deploys: they serve immutable releases; customers can roll back their own site from the publish dialog.

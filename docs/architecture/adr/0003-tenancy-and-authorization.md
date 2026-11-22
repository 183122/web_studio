# ADR-0003: Tenancy and authorization

- Status: Accepted (Phase 0)
- Decision:
  - Hierarchy: organization (tenant, owns plan) → workspaces → projects/assets. Users join organizations (`owner|admin|billing_admin|member`) and workspaces (`admin|editor|developer|viewer`).
  - Logical isolation with `organization_id`/`workspace_id` on rows. Every request resolves the resource by ULID, then authorizes through policies based on the **current user's workspace membership**. Non-members receive **404** (`Response::denyAsNotFound`) so existence is not disclosed; members lacking a role receive 403.
  - Listing endpoints are always scoped by a workspace the user belongs to; no endpoint accepts a tenant id as authority.
  - Database-per-tenant for high tiers is deferred (Phase 6+) behind the same service layer.
- Verification: `TenantIsolationTest` (18 cross-tenant routes + asset reference/deletion) and E2E `another tenant cannot open a project by URL`.

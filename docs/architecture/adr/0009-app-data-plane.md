# ADR-0009: Generated-app data plane, RBAC and workflows

- Status: Accepted (Phase 2)
- Context: spec §7 allows "typed JSON records with indexed materialized fields OR generated physical tables". Generated DDL per tenant would mean running migrations on user input.
- Decision:
  - **Typed JSON records** in `app_records` (`project_id`, `environment`, `model_id`, `data` keyed by stable field ids, `version`). Model/field definitions live in the canonical AppDefinition (schema v2), so the data model is versioned, diffable and reviewable like everything else.
  - **Environments**: `preview` now; `production` is reserved for published releases (Phase 4). Preview data never mixes with production.
  - **Unique fields** are enforced atomically by `app_record_unique_keys` (unique index on a SHA-256 of the normalised value), not by read-then-write checks.
  - **Optimistic concurrency** per record (`version`), 409 on stale writes.
  - **Safe evolution**: model changes never rewrite or delete stored values. Removed fields are hidden but retained; values that no longer validate are reported by the *migration impact preview* (`POST /data/impact`) before the user confirms. Model removal is blocked while forms/tables/workflows reference it.
  - **RBAC**: explicit permission entry per model (validator requires it) for app roles `public`, `member`, `admin` × `create/read/update/delete`; default deny. Studio members act as the app owner. The same evaluator serves the preview runtime (simulated role), forms and API keys.
  - **Workflows**: declarative trigger → conditions (AND) → allowlisted actions (`record.setField`, plain-text `email.send`). One run per (workflow, record, event, version) via a unique index; workflow-made changes do not re-trigger workflows; e-mail is queued after commit with retries; dry runs are side-effect free.
  - **REST API**: per-project API keys (`sk_<prefix>_<secret>`, SHA-256 stored, shown once, revocable) with scopes and an app role; payloads use field names.
  - Searching uses `LIKE` on the JSON text (stored with unescaped Unicode so Thai works); filtering/sorting use JSON path expressions with a numeric cast for number fields. Large-scale indexing (generated columns / search index) is a Phase 6 performance item.
- Consequences: no tenant DDL, uniform tenant scoping; per-field indexes are not available yet, so very large tables (>100k rows per model) will need materialised indexed columns later.

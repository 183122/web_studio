# ADR-0004: Revisions, checkpoints and optimistic concurrency

- Status: Accepted (Phase 1)
- Context: autosave produces many small saves; storing a full snapshot per save is wasteful, but history must be reliable.
- Decision:
  - `app_definitions` holds the head document and `current_revision` (monotonic). Every write locks the head row (`SELECT … FOR UPDATE`) and requires `baseRevision == current_revision`, otherwise **409 revision_conflict**.
  - `app_change_events` is append-only: every accepted command with `(revision, seq)`.
  - `app_revisions` stores **immutable checkpoints** (full snapshot + sha256 of canonical JSON) when: the project is created/cloned/restored/replaced, the user names a version, or the last checkpoint is older than `STUDIO_CHECKPOINT_INTERVAL` (default 300s). Any revision is reconstructable from the nearest checkpoint + events.
  - Restore never rewrites history: it creates a new revision with `restored_from_revision`.
  - Client: commands queue locally, sent in batches with an `Idempotency-Key`; network failures retry with the same key (exactly-once effect); on 409 the user can **merge** (re-apply unsaved commands on the new head, validated) or reload.
- Consequences: version list shows checkpoints, not every autosave; event replay tooling is a Phase 6 ops task.

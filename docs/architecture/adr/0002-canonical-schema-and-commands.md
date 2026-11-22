# ADR-0002: Canonical AppDefinition, typed commands and dual validation

- Status: Accepted (Phase 1)
- Context: AI edits and drag-and-drop must change the same document (spec §0.2, §6) and AI output is untrusted (§0.7).
- Decision:
  - One canonical JSON document per project, defined by JSON Schema (draft 2020-12) plus a semantic validator (registry allowlist, prop kinds, style allowlist + value grammar, URL safety, referential integrity, single parent, no cycles, max depth 32, max 5000 components, unique routes, derived asset list).
  - All mutations are typed commands (JSON Schema `oneOf`), never free-form patches. The future AI patcher (Phase 3) must emit the same commands.
  - TypeScript is the reference implementation; PHP mirrors it. Parity fixtures generated from TS are asserted by both test suites; CI fails if fixtures or precompiled validators drift.
  - Ajv validators are precompiled to standalone ESM (`npm run generate`) so the studio needs no `eval`/`new Function` (strict CSP).
- Consequences: two implementations to maintain, mitigated by fixtures; schema evolution uses `schemaVersion` + upcasters (first upcaster lands with v2).

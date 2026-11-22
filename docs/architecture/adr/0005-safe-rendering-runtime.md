# ADR-0005: Schema-driven safe renderer (no tenant code execution)

- Status: Accepted (Phase 1)
- Decision:
  - A single React renderer (`packages/renderer`) renders only registry components; text is rendered as text (no `dangerouslySetInnerHTML`), links pass `isSafeHref` again at render time (defence in depth), images accept only `asset:<id>` or `https://`, video only YouTube-nocookie/Vimeo ids in sandboxed iframes, CSS only from the allowlisted style properties with validated grammar.
  - The same renderer powers the editor canvas (`edit` mode: `data-node-id` hooks, placeholders), private preview (`preview`), and will power the published runtime (`live`, Phase 4) — so what users see is what ships.
  - No tenant-authored JS/PHP/SQL is executed anywhere (spec §8). Custom code is Phase 7 behind a sandbox and security review.

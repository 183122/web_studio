# ADR-0006: Asset pipeline

- Status: Accepted (Phase 1)
- Decision:
  - Upload limit 10 MB; type detected from **content** (finfo), allowlist JPEG/PNG/WebP (SVG rejected: script vector); dimension cap 16 MP checked before decode.
  - Images are **re-encoded with GD** before storage: strips EXIF/GPS (PDPA) and neutralises polyglot payloads.
  - `MalwareScanner` interface: ClamAV (clamd INSTREAM) when `CLAMAV_HOST` is set; otherwise status is recorded honestly as `not_scanned` (never `clean`). Infected uploads are rejected and audited.
  - Private disk with tenant prefixes `tenants/{org}/{workspace}/assets/{ulid}.{ext}`; served only via **relative temporary signed URLs** (30 min) with `nosniff` and a sandbox CSP. Swap the `assets` disk to S3-compatible storage in production.
  - De-duplication by sha256 within a workspace; storage quota from plan entitlements, enforced under an organization row lock.
- Not yet: async scanning queue, image variants/transformations, folders (Phase 2/6).

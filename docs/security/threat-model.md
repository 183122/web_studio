# Threat model (Phase 0–1)

Method: STRIDE per trust boundary. Status legend: ✅ implemented & tested · 🟡 partial · ⏳ planned (phase).

## Assets to protect
Tenant documents (app definitions, revisions), uploaded files, user accounts/sessions, audit trail, plan quotas, platform availability.

## Trust boundaries
1. Browser ↔ edge/API (public internet)
2. API ↔ database/object storage (internal)
3. Tenant ↔ tenant (logical isolation inside shared tables)
4. Untrusted content (user/AI-authored documents, uploads) ↔ renderer/runtime
5. Platform ↔ third parties (Google, payment, AI providers — Phase 3+)

| # | Threat | Control | Status |
|---|---|---|---|
| S1 | Credential stuffing / brute force | throttle 5/min per email+IP, 30/min per IP; strong password rule; audit `auth.login_failed` (email hashed) | ✅ `AuthTest::test_login_is_rate_limited` |
| S2 | Session theft | HttpOnly cookies, session regenerate on login, invalidate on logout, all sessions revoked on password reset; `Secure` cookies in production | ✅ / prod config |
| S3 | Account enumeration | identical forgot-password responses | ✅ |
| T1 | IDOR / cross-tenant read-write | policies from membership; 404 for non-members; ULIDs | ✅ `TenantIsolationTest` (18 routes), E2E J8 |
| T2 | Cross-tenant asset reference in a document | server checks every `asset:<id>` belongs to the project workspace | ✅ |
| T3 | Lost update / concurrent overwrite | optimistic concurrency (`baseRevision`, row lock), 409 + merge UI | ✅ API + E2E |
| T4 | Duplicate application on retry | `Idempotency-Key` stored per user; mismatched body → 422 | ✅ |
| T5 | Body tampering of `projectId` | server re-binds `projectId` to the URL project | ✅ |
| I1 | Stored XSS via document content | text rendered as text; no HTML props; URL allowlist (`javascript:`/`data:`/protocol-relative rejected) at validation **and** render | ✅ parity fixtures + renderer tests |
| I2 | CSS injection | style keys allowlist + value grammar (no `;`, `url(`, `expression`) | ✅ |
| I3 | Malicious uploads (SVG/HTML/polyglot, EXIF leakage) | content sniffing, SVG blocked, GD re-encode, `nosniff` + sandbox CSP on delivery | ✅ |
| I4 | Malware in uploads | ClamAV adapter when configured; otherwise explicitly `not_scanned` | 🟡 adapter not integration-tested |
| I5 | Signed URL leakage | 30-minute expiry, relative signature, private disk | ✅ |
| D1 | Oversized documents / deep trees | 2 MB doc, 5000 components, depth 32, 200 commands/request, rate limits | ✅ |
| D2 | Decompression bombs | 16 MP cap before decode | ✅ |
| E1 | Privilege escalation inside workspace | role checks: viewer read-only; delete/restore/audit admin only; archived projects read-only | ✅ |
| E2 | Arbitrary code execution from tenant/AI content | no eval of tenant code; allowlisted components only; AI emits typed commands (Phase 3) | ✅ by design |
| R1 | Repudiation | append-only `audit_logs` + `app_change_events` with actor | ✅ (integrity hashing ⏳ Phase 6) |
| X1 | CSRF | Sanctum stateful API with XSRF token | ✅ |
| X2 | Clickjacking | `X-Frame-Options: DENY`, `frame-ancestors 'none'` | ✅ |
| X3 | Host header / domain takeover | trusted proxies only; host-based routing | ⏳ Phase 4 |
| X4 | SSRF via integrations | egress allowlist, private-range deny | ⏳ Phase 5 |
| X5 | Payment webhook forgery | HMAC + replay window + idempotency | ⏳ Phase 6 |

### Phase 2 additions (generated-app data)
| # | Threat | Control | Status |
|---|---|---|---|
| D-1 | Cross-tenant record access | every query scoped by project + environment; project resolved through workspace membership (404 otherwise); API keys bound to one project | ✅ `DataRecordsTest`, `AppApiTest` |
| D-2 | Privilege escalation by app users / keys | explicit per-model permission matrix, default deny; scopes ∩ role for keys | ✅ `DataRuntimeTest`, `AppApiTest` |
| D-3 | Duplicate/unique race | unique-key table with DB unique index | ✅ |
| D-4 | Lost updates | per-record version (409) | ✅ |
| D-5 | Form spam / abuse | honeypot, minimum fill time, 10/min per IP+project, record quota | ✅ (CAPTCHA ⏳) |
| D-6 | CSV formula injection | export prefixes `= + - @` cells with `'` | ✅ |
| D-7 | Destructive schema change | impact preview; values never deleted on field/model removal | ✅ |
| D-8 | Workflow loops / duplicate e-mails | no re-trigger from workflow writes; unique run per event/version; mail queued after commit | ✅ |
| D-9 | E-mail header/HTML injection via templates | plain-text body, single-line subject, recipient validated | ✅ |
| D-10 | API key theft | SHA-256 at rest, shown once, revocable, rate limited; Sanctum PAT lookup disabled | ✅ |
| D-11 | PII in app audit logs | stored for the app owner; retention/export/delete policy | ⏳ Phase 6 (PDPA) |

## Residual risks / follow-ups
- Production must set `SESSION_SECURE_COOKIE=true`, HTTPS, `TRUSTED_PROXIES`, and the CSP in `infra/docker/nginx.conf`.
- DAST, SAST and dependency scanning in CI: `npm audit` and `composer audit` run now; SAST/DAST tools are added in Phase 6.
- Audit log tamper-evidence (hash chain) and retention policy: Phase 6.

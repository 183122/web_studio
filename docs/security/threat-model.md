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

### Phase 3 additions (AI)
| # | Threat | Control | Status |
|---|---|---|---|
| A-1 | Prompt injection makes the model emit harmful structure (scripts, unsafe links, privilege grants) | model outputs intent only (JSON Schema); normaliser + deterministic compiler + canonical validator; links/styles re-checked; permission impact shown before approval | ✅ `AiPipelineTest::test_hostile_model_output_is_neutralised` |
| A-2 | AI silently overwrites user work | plan and proposal both require approval; apply uses baseRevision (409 if stale); `ai` checkpoint for rollback | ✅ |
| A-3 | Corrupted schema from invalid/truncated output | normalise → compile → dry-run validate; failures leave the document untouched | ✅ (J10 tests) |
| A-4 | Sending personal data to a third party without consent | explicit consent per request for external providers; prompts stored redacted | ✅ |
| A-5 | API key leakage | platform key in env only; BYOK encrypted at rest, never returned, audit-logged | ✅ |
| A-6 | Cost abuse | monthly credits (reserve/commit under lock), per-user rate limit, bounded repair loop, max tokens | ✅ |
| A-7 | Provider outage blocks users | offline fallback provider, clearly labelled | ✅ |

### Phase 4 additions (publishing & domains)
| # | Threat | Control | Status |
|---|---|---|---|
| P-1 | Tenant code execution on published sites | release is data only (JSON block, `application/json`, HEX-escaped); renderer allowlist; strict CSP (`script-src 'self'`) | ✅ `PublishingTest` |
| P-2 | Cross-site data access via Host spoofing | Host → active domain → exactly one project; platform hosts never resolve; studio API blocked on tenant hosts in nginx; `TRUSTED_PROXIES` only | ✅ J8 tests |
| P-3 | Subdomain/custom-domain takeover | unique `active_hostname`; 30-day cooldown for released slugs; per-binding TXT token + routing + TLS before serving; scheduled re-check; TLS `ask` only for served hosts | ✅ `CustomDomainTest` |
| P-4 | Broken publish takes site down | immutable releases, pipeline gates before switch, compare-and-set switch, health check with automatic revert, rollback | ✅ J9 |
| P-5 | CSRF against end-user sessions | `X-Runtime-Request` header required for writes, no CORS, SameSite=Lax, host-only cookie | ✅ |
| P-6 | Session theft / reuse across sites | HttpOnly host-only cookie, token hashed at rest, bound to project, revoked on disable/role change, 14-day expiry | ✅ |
| P-7 | Account enumeration / brute force | generic errors, dummy-hash timing, 10/min per host+IP | ✅ |
| P-8 | Leaking internal config (workflow recipients, ids) | public document strips workflows and projectId; share links likewise | ✅ |
| P-9 | Asset exfiltration through site | `/_assets/{id}` serves only ids in the live release manifest of that host, `nosniff`, sandbox CSP | ✅ |
| P-10 | Share-link leakage | 40-char random token, hash at rest, expiry, revoke, noindex, no data access | ✅ |
| P-11 | Abuse hosting (phishing on our domain) | verified e-mail required to publish; reporting/abuse flags | ✅ / ⏳ Phase 6 |

### Platform settings
| # | Threat | Control | Status |
|---|---|---|---|
| S-1 | Tenant escalates to platform operator | `is_platform_admin` not mass-assignable; granted only via artisan; admin API returns 404 to others | ✅ `PlatformSettingsTest` |
| S-2 | OAuth client secret disclosure | encrypted at rest (app key), write-only (last 4 shown), excluded from audit metadata and logs | ✅ |
| S-3 | Hijacked admin session changes secrets | password re-confirmation on save/delete, 10/min throttle, audit log | ✅ |

### Phase 5 additions (integrations)
| # | Threat | Control | Status |
|---|---|---|---|
| I-1 | OAuth code interception / CSRF on callback | PKCE S256, single-use user-bound `state` (10 min), callback requires the same studio session, admin re-checked | ✅ `GoogleConnectionTest` |
| I-2 | Token theft | refresh/access tokens encrypted at rest, never serialized/logged/returned; revoke + delete on disconnect | ✅ |
| I-3 | Over-broad Google access | per-feature scopes; Drive limited to `drive.file`; scope checked before every call | ✅ |
| I-4 | Residual access after disconnect | revoke at Google, local deletion regardless of Google's answer, syncs → `disconnected`, calls fail with `google_not_connected` | ✅ J4 |
| I-5 | Formula/CSV injection into Sheets | writes use `valueInputOption=RAW`; imported cells validated like form input | ✅ |
| I-6 | Sheets as an unreliable source corrupting data | app DB canonical; per-row validation; idempotent key/`_studio_id` matching; conflict policy; no deletes from sheets | ✅ |
| I-7 | Quota storms / outages | per-sync lock, backoff, dead letter, scheduler skips syncs with work in flight, 30 req/min/user on Google-backed endpoints | ✅ |
| I-8 | Malicious attachments | content sniffing allowlist, 10 MB cap, malware scan, served as attachment with nosniff via the API | ✅ (ClamAV optional) |
| I-9 | Accessing files after deletion/revocation | metadata re-check at Google on every download; status `missing`/`access_lost` | ✅ J5 |
| I-10 | SSRF via webhooks | https/public-IP-only guard incl. metadata/CGNAT/ULA, DNS pinning (no rebinding), no redirects, short timeouts | ✅ `WebhookTest` |
| I-11 | Webhook spoofing/replay at receivers | HMAC-SHA256 over timestamp + body, event id for idempotency, secret shown once (encrypted at rest) | ✅ |
| I-12 | Fake Google endpoint in production | `GOOGLE_FAKE_BASE` refused when `APP_ENV=production` | ✅ |

### Phase 6 additions (SaaS & hardening)
| # | Threat | Control | Status |
|---|---|---|---|
| B-1 | Fake "payment success" (redirect tampering) | plans change only on verified webhooks; return page only polls | ✅ `BillingTest` |
| B-2 | Forged / replayed / duplicated provider webhooks | HMAC + 5-min window, unique event ids, amount/currency match | ✅ |
| B-3 | Free upgrades via the sandbox provider | test provider refused in production; real provider needs approval | ✅ |
| B-4 | Usage races beyond quota | atomic counters; countable limits checked under org lock | ✅ |
| B-5 | Destructive downgrade | plan change only; data kept; new usage blocked | ✅ J7 |
| S-4 | Operator overreach | support access needs org approval, read-only, expiring, revocable, audited; no billing access | ✅ `PlatformAdminTest` |
| S-5 | Abusive tenant content | public reports, suspension (sites offline, publishing/checkout blocked), data kept for owners | ✅ |
| S-6 | Account takeover | TOTP 2FA with replay protection, hashed recovery codes, session revoke, password confirmation for sensitive changes | ✅ `AccountSecurityTest` |
| S-7 | Invitation hijack | hashed single-use token, 7-day expiry, bound to invited e-mail, seat limits | ✅ |
| P-12 | PDPA rights not honoured | consent record, export, erasure (anonymize + purge after 30 d), end-user export/erase by app owners, retention schedule | ✅ `PrivacyAuditTest` |
| P-13 | Audit log tampering | hash chain over canonical snapshots, locked chain head, daily verify + metric | ✅ |
| O-1 | Blind operations | request ids, readiness, metrics, JSON logs, runbooks | ✅ (alert wiring is deployment-specific) |

### Phase 7 additions
| # | Threat | Control | Status |
|---|---|---|---|
| M-1 | Malicious or infringing community templates | human moderation with rendered preview, rights confirmation, licenses, reports, delisting | ✅ `MarketplaceTest` |
| M-2 | Data/PII leakage through templates | records never included; fixed-recipient e-mail actions removed; workflows disabled; files copied, not linked | ✅ |
| M-3 | Template files escaping quotas or tenant prefixes | copied into installer's prefix with storage quota check; signed-URL access unchanged | ✅ |
| CO-1 | Presence leaking project activity across tenants | presence requires project view; ids validated; no content stored | ✅ `CollaborationTest` |
| CO-2 | Lost edits under concurrency | optimistic revisions + automatic rebase with validation; manual banner when unmergeable | ✅ E2E |
| E-1 | Secrets/PII in exports | no keys/tokens/hashes; data opt-in per environment with PDPA notice; manage permission; audited; rate-limited | ✅ `ExportTest` |
| E-2 | Injection via exported SQL/CSV/HTML | SQL string escaping, formula-safe CSV, inert JSON bootstrap + CSP meta | ✅ (SQL loaded into MySQL 8 in review) |
| X-1 | Tenant code execution | not enabled; security review + guard test | ✅ `NoTenantCodeExecutionTest` |

## Residual risks / follow-ups
- Production must set `SESSION_SECURE_COOKIE=true`, HTTPS, `TRUSTED_PROXIES`, and the CSP in `infra/docker/nginx.conf`.
- Google: complete OAuth verification for the sensitive `spreadsheets` scope and run `docs/runbooks/google-oauth-review.md` against real Google before enabling for customers.
- Published sites: set `PLATFORM_BASE_DOMAIN`/`PLATFORM_CNAME_TARGET`/`PLATFORM_HOSTS`, run the edge with on-demand TLS + `ask`, and keep `STUDIO_DNS_DRIVER=system`, `STUDIO_TLS_CHECK=probe` (the `static`/`assume` drivers are for local/E2E only). Use a separate registrable domain for tenant sites (not a subdomain of the studio) so cookies can never be shared.
- SAST (Semgrep), secret scanning (gitleaks) and dependency audits run in CI (not executed in this environment); DAST (e.g. OWASP ZAP against staging) still to be scheduled.
- Audit hash chain detects tampering inside the database; protecting against a privileged DB attacker who rewrites the whole chain needs periodic export of the chain head to write-once storage (WORM) — not yet automated.

# Security review: tenant custom code ("custom-code sandbox")

- Status: **Review complete — feature NOT enabled.** Re-review required before any implementation ships.
- Scope: letting tenants run their own JavaScript (client widgets) or server-side functions (form handlers, scheduled jobs, API endpoints) inside AI Studio Cloud.
- Spec constraints: §0 / §8 "MVP must not execute tenant-authored PHP, SQL, shell, arbitrary JavaScript, eval, or arbitrary package installs"; "for custom code in future: sandbox per build and per runtime, strict egress allowlist, CPU/memory/time limits, read-only FS, ephemeral credentials, network isolation, policy enforcement and billing"; Phase 7 "custom-code sandbox only after security review".

## 1. What exists today (and must not regress)
- Pages are **data** (AppDefinition) rendered by an allowlisted component registry; no component accepts HTML, scripts or styles beyond typed props. Links, image sources and styles are validated on both client and server.
- Published sites send a strict CSP (`script-src 'self'`), so even injected markup cannot run inline script; the runtime bundle is ours, built from this repository.
- Server-side "logic" is limited to the workflow engine's allowlisted actions (set field, queued plain-text e-mail) and outbound signed webhooks behind an SSRF guard.
- Guard test `NoTenantCodeExecutionTest` fails the build if the registry gains a raw HTML/script component or the command set gains code-bearing commands.

## 2. Threats if custom code were allowed
| # | Threat | Impact |
|---|---|---|
| C-1 | Sandbox escape (VM/isolate/kernel bug) | full platform compromise, all tenants |
| C-2 | Cross-tenant data access through shared runtime, caches, env vars or credentials | PDPA breach |
| C-3 | Network abuse: SSRF to metadata/internal services, spam, scanning, DDoS from our IPs | compromise / IP reputation / legal |
| C-4 | Resource exhaustion (CPU, memory, disk, fork bombs, infinite loops) | outage for other tenants (noisy neighbour) |
| C-5 | Supply chain: arbitrary npm/composer packages, typosquatting, install scripts | code execution at build time |
| C-6 | Secret exfiltration (tenant secrets, platform tokens injected by mistake) | account takeover |
| C-7 | Client-side custom JS on published sites: phishing, crypto-mining, session theft of end users, CSP bypass | end-user harm, domain blocklisting |
| C-8 | Abuse at scale (free tier → hosting malware) | legal / abuse workload |
| C-9 | Cost blow-up without metering/billing | financial |

## 3. Options evaluated
| Option | Isolation | Cold start | Ops cost | Notes |
|---|---|---|---|---|
| A. In-process JS (e.g. QuickJS / V8 isolates in PHP/Node workers) | language-level only | ms | low | One engine bug = shared-process compromise. **Rejected** for server code. |
| B. WebAssembly (Wasmtime/WasmEdge) with WASI, no network by default, fuel/epoch limits | strong memory isolation, capability-based | ms | medium | Good fit for pure functions (validation, transforms). Needs a host-call API for data with per-call authorization. **Preferred first step.** |
| C. gVisor / Kata containers per invocation | syscall/VM-level | 100 ms–1 s | high | For general server functions with packages. |
| D. Firecracker microVMs per tenant build/runtime | hardware virtualisation | ~125 ms | highest | Strongest; matches "sandbox per build and per runtime". |
| E. Client-side custom JS in an iframe on a separate origin (`*.usercontent.<base>`) with `sandbox="allow-scripts"` and postMessage API | browser origin isolation | — | low | Viable for widgets; never on the site's own origin. |

## 4. Required controls before enabling (all mandatory)
1. **Isolation**: B (Wasm) for functions, E for client widgets; D/C only after a separate review. One sandbox per invocation, no shared mutable state between tenants.
2. **Limits**: CPU time (fuel/epoch) ≤ 1 s per call, memory ≤ 64 MB, output ≤ 1 MB, no filesystem (read-only bundle only), concurrency quota per organization.
3. **Network**: no direct sockets; outbound HTTP only through a host function that reuses `EgressGuard` (public IPs, DNS pinning, allowlisted domains configured by the tenant, rate limits), metered.
4. **Data access**: host functions call `RecordService` with an `Actor` of type `function` whose role is explicit in the permission matrix (default deny), scoped to the project and environment.
5. **Secrets**: per-project secrets encrypted at rest, injected per invocation, never logged; ephemeral short-lived credentials only; no platform credentials inside the sandbox.
6. **Supply chain**: no package installs in phase 1 (single-file modules); later, a curated, pinned, scanned package mirror.
7. **Build**: compilation in an isolated build sandbox (separate from runtime), reproducible, SAST on tenant source, size limits; artifacts are immutable and referenced by release manifests.
8. **Client widgets**: separate registrable domain for user content, `sandbox` iframes, strict CSP, no access to site cookies, postMessage schema validation, abuse scanning.
9. **Abuse & billing**: paid plans only, verified e-mail + payment method, invocation/CPU/egress metering via `UsageMeter`, automatic suspension on abuse signals, kill switch per organization and globally.
10. **Observability**: per-invocation logs (redacted), traces with request ids, alerting on sandbox faults and limit hits.
11. **Assurance**: external penetration test focused on sandbox escape and cross-tenant access; bug bounty scope update; documented incident runbook; re-review of this document.

## 5. Decision
- Custom code stays **disabled**. No API, schema type, command or UI exposes it.
- The platform's extensibility for now: allowlisted components, workflows, outbound webhooks (to the tenant's own servers, where they can run any code), the REST data API with scoped keys, and full project export.
- Next step if prioritised: a design + prototype of option B for pure "computed field / validation" functions with no network, behind a feature flag in a non-production environment, followed by an external review.

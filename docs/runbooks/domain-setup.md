# Domain setup checklist (platform operator)

DNS changes require explicit approval.

- [ ] Choose a separate registrable domain for tenant sites (not a subdomain of the studio) → `PLATFORM_BASE_DOMAIN`.
- [ ] Wildcard DNS `*.<base>` → edge load balancer; `PLATFORM_CNAME_TARGET` (e.g. `edge.<base>`) → same.
- [ ] Edge (`infra/edge/Caddyfile`): on-demand TLS with `ask` → `/api/internal/tls/ask`; rate limits for issuance; HTTP→HTTPS redirect.
- [ ] `PLATFORM_HOSTS` lists the studio/API hostnames; `TRUSTED_PROXIES` lists the edge.
- [ ] Verify: reserve a slug, publish, open `https://<slug>.<base>`; add a custom domain on a Pro test org, follow the TXT + CNAME instructions, verify → active.
- [ ] Monitor certificate issuance failures and the `studio:domains:check` scheduler.

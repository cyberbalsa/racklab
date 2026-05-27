# M11a — TLS / ACME: Backend + Bootstrap

**Status:** Not started.
**Estimated effort:** 1–2 weeks.
**Depends on:** M0.5, M2.5.
**Unblocks:** M11b, M12.

## Goal

RackLab's Baseline profile serves HTTPS from first boot and has a backend TLS/ACME control surface that the later admin GUI can drive. M11a ships Caddy-in-FrankenPHP TLS integration, self-signed bootstrap certificates, issuance profile configuration, force-renew CLI, backup/restore primitives, and audit events without requiring the M10a admin UI shell.

## In scope

- Four ACME issuance profiles (see below) configured via Caddy's TLS directives; semantic carry-forward from the deleted `docs/superpowers/specs/2026-05-24-server-side-tls-acme.md`.
- PRD §18 security — TLS termination, HSTS defaults, secure cookies, OCSP, modern TLS profile.
- PRD §16 Baseline container operations — FrankenPHP Quadlet and install-script integration.

## Issuance profiles

The four profiles from the original TLS-ACME spec carry forward, configured against Caddy/FrankenPHP rather than Traefik:

1. **Public ACME (Baseline default)** — Caddy's built-in ACME via FrankenPHP handles the standard HTTP-01/TLS-ALPN-01 flow automatically on first request for a real hostname. No extra `lego` agent needed.
2. **Manual cert upload** — operator supplies a pre-issued cert + key pair; Caddy consumes them via `tls.certificates` directive without restart (hot-reload).
3. **Custom internal CA / ACME with EAB** — custom ACME directory URL + EAB credentials + uploaded CA bundle; configured in Caddy `tls` block with `ca` + `ca_root` + `eab` directives.
4. **ACME-DNS-01 for private domains** — DNS challenge provider configured in Caddy; `lego` cert agent writes PEMs to a shared volume consumed by Caddy via `tls.certificates` when Caddy's built-in DNS plugin is not available for the provider.

For Scale, all four profiles apply against the Caddy/FrankenPHP replicas, or fronted by an external load balancer that terminates TLS upstream and passes plain HTTP to FrankenPHP.

## Dependencies

- M0.5 — Baseline install script, persistent config directories, and container image discipline.
- M2.5 — health checks, restart/drain behavior, backup/restore conventions, and baseline operational smoke.
- M0 audit subsystem — all TLS changes are auditable from the start.

## Deliverables

- FrankenPHP Quadlet for the Baseline profile: listens on 80 + 443, handles TLS natively; forwards WebSocket traffic to Reverb upstream.
- Static Caddy config shipped by `scripts/baseline-install.sh`: entry points, HSTS global option, OCSP stapling, access log, metrics endpoint, and certificate resolver definitions.
- RackLab Caddy config generator (Artisan command):
  - Renders the Caddyfile `tls` block from the active issuance profile stored in `laravel-settings`.
  - Renders `tls.certificates` entries for uploaded or self-signed PEMs.
  - Classifies each profile change as hot-reload (new `tls.certificates` entry) vs. restart-required (ACME provider change, OCSP toggle, HSTS headers).
- Self-signed bootstrap cert generator: ECDSA P-256 keypair, 365-day validity, generated during install and used until a real issuer is configured.
- Backend model/service for issuance profiles stored in `laravel-settings`:
  - Public ACME (Caddy built-in): ACME email, staging vs. production toggle.
  - Manual upload: cert path, key path, SAN list.
  - Custom ACME with EAB: directory URL, EAB key ID + HMAC, CA bundle path.
  - DNS-01 via `lego`: DNS provider slug, credentials, shared PEM volume path.
- Artisan command surface:
  - `racklab tls:validate` — validate active TLS config.
  - `racklab tls:render` — render Caddyfile TLS block to stdout.
  - `racklab tls:switch <profile>` — switch active issuance profile.
  - `racklab tls:force-renew` — force-renew with stop-edit-start semantics (for non-Caddy-automatic profiles); rate-limited to 1/hour.
  - `racklab tls:backup` / `racklab tls:restore` — backup/restore cert state (PEMs + settings snapshot).
- Renewal-status probe service: performs active HTTPS handshake checks against the configured domain; does not scrape Caddy internals.
- Hardening defaults: HSTS one year with `includeSubDomains=false`, preload off, TLS 1.2+ Intermediate default, optional Modern profile, `sniStrict`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options`, trusted-proxy allowlist, access-log redaction.
- Route-shadowing defenses: plugins cannot register routes under `/admin`, `/api`, internal health/metrics paths, or Caddy admin API paths.
- Audit events from the TLS spec: `tls.config.change`, `tls.cert.uploaded`, `tls.force_renew`, `tls.renewal.success`, `tls.renewal.failure`, `tls.admin_api_access_attempt`, `tls.cert_backup`, `tls.cert_restore`.

## Acceptance criteria

- [ ] Fresh `scripts/baseline-install.sh` on a clean host serves RackLab over HTTPS using the self-signed bootstrap cert on the first request.
- [ ] Switching to public ACME staging from the CLI renders the correct Caddyfile TLS block, reloads Caddy only when required, and successfully issues against a Pebble/LE-staging test path.
- [ ] Uploading a pre-issued cert + key pair validates hostname/SAN/key match; invalid pairs are rejected and valid pairs hot-reload through `tls.certificates` without restarting FrankenPHP.
- [ ] Custom ACME with EAB credentials and custom CA bundle validates against a step-ca/Pebble-style integration test.
- [ ] Force-renew runs the documented stop-edit-start cycle, rate-limits repeated attempts, audits request + result, and preserves a backup before mutation.
- [ ] HSTS, OCSP, TLS profile, security headers, and trusted-proxy defaults are visible in generated Caddyfile and verified by integration tests.
- [ ] An attempt by a plugin to register a route under a reserved path is rejected at plugin-enable time with a clear error and audit event.
- [ ] Caddy admin API access attempts from non-localhost are rejected and emit `tls.admin_api_access_attempt`.

## Test layers

- **Tiny / unit**: cert/key pair validator; hostname/SAN validator; hot-reload-vs-restart classifier; Caddyfile TLS block renderer; sanitized error generator.
- **Contract**: Caddyfile config generator against checked example output; TLS Artisan command/service API against fake filesystem and fake process runner; `laravel-settings` profile serialization.
- **Integration**: FrankenPHP + Pebble/step-ca issuance; uploaded cert hot-reload; force-renew backup + restore; route-shadowing rejection. Uses Testcontainers (Postgres 16 + Redis 7 + Podman socket).
- **E2E (Dusk)**: Baseline install smoke reaches RackLab over bootstrap HTTPS; full GUI-driven journey waits for M11b.

## Risks / open questions

- **ACME rate limits**: the backend must default to staging in dev/test contexts and make production opt-in explicit. M11b improves the UX, but backend defaults must already be safe.
- **Cert state backup**: M11a ships Artisan restore. M11b adds the admin surface; tests need to cover corrupted/missing backups now.
- **OCSP restart boundary**: OCSP stapling is static Caddy config. Any change that touches it requires restart and must be classified that way.
- **Proxmox trust composition**: M3's provider CA bundle handling and RackLab's public TLS certs can come from different issuers; cross-test before promoting M11a.
- **Caddy admin API exposure**: Caddy's admin API must bind only to `127.0.0.1:2019`; the install script must enforce this and the integration test must verify it.

## Out of scope (deferred)

- TLS admin GUI and E2E admin journey — M11b.
- Scale-profile `lego` cert agent and shared PEM volume — M12.
- DNS-01 challenges and wildcard certificates — post-v1 (DNS-01 via `lego` is the M11a groundwork; full wildcard support deferred).
- HA Caddy/FrankenPHP for Baseline — Baseline remains single-host.

# M11a — TLS / ACME: Backend + Bootstrap

**Status:** Not started.
**Estimated effort:** 1–2 weeks.
**Depends on:** M0.5, M2.5.
**Unblocks:** M11b, M12.

## Goal

RackLab's Baseline profile serves HTTPS from first boot and has a backend TLS/ACME control surface that the later admin GUI can drive. M11a ships Traefik integration, self-signed bootstrap certificates, dynamic config generation, issuance backends, force-renew CLI, backup/restore primitives, and audit events without requiring the M10a admin UI shell.

## In scope

- `docs/superpowers/specs/2026-05-24-server-side-tls-acme.md` backend, config, issuance, and security-header sections.
- PRD §18 security — TLS termination, HSTS defaults, secure cookies, OCSP, modern TLS profile.
- PRD §16 Baseline container operations — Traefik Quadlet and install-script integration.

## Dependencies

- M0.5 — Baseline install script, persistent config directories, and container image discipline.
- M2.5 — health checks, restart/drain behavior, backup/restore conventions, and baseline operational smoke.
- M0 audit subsystem — all TLS changes are auditable from the start.

## Deliverables

- Traefik 3.x Quadlet for the Baseline profile: listens on 80 + 443, forwards UI/API/SSE/WebSocket traffic to `racklab-web` on the internal Podman network.
- Static Traefik config shipped by `scripts/baseline-install.sh`: entry points, file provider, access log, metrics endpoint, dashboard disabled, OCSP static block, and certificate resolver definitions.
- RackLab dynamic config generator:
  - `routes.yml` for routers/services/middlewares.
  - `tls.yml` for TLS store, HSTS middleware, TLS-profile options, active resolver references, and uploaded/self-signed `tls.certificates`.
- Self-signed bootstrap cert generator: ECDSA P-256 keypair, 365-day validity, generated during install and used until a real issuer is configured.
- Backend model/service for issuance profiles: Let's Encrypt HTTP-01, custom ACME with EAB and custom CA bundle, uploaded cert/key pair, self-signed fallback.
- CLI/management-command surface:
  - validate TLS config.
  - render dynamic config.
  - switch active profile.
  - force-renew with stop-edit-start semantics.
  - backup/restore `acme.json`.
- Renewal-status probe service: parses Traefik/lego output and performs active HTTPS handshake checks; does not scrape `acme.json` internals.
- Hardening defaults: HSTS one year with `includeSubDomains=false`, preload off, TLS 1.2+ Intermediate default, optional Modern profile, `sniStrict`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `X-Frame-Options`, trusted-proxy allowlist, access-log redaction.
- Route-shadowing defenses: plugins cannot register routes under `/admin`, `/api`, internal health/metrics paths, or Traefik dashboard paths.
- Audit events from the TLS spec: `tls.config.change`, `tls.cert.uploaded`, `tls.force_renew`, `tls.renewal.success`, `tls.renewal.failure`, `tls.dashboard_access_attempt`, `tls.acme_json.backup`, `tls.acme_json.restore`.

## Acceptance criteria

- [ ] Fresh `scripts/baseline-install.sh` on a clean host serves RackLab over HTTPS using the self-signed bootstrap cert on the first request.
- [ ] Switching to Let's Encrypt staging from the CLI renders the correct static/dynamic config, restarts Traefik only when required, and successfully issues against a Pebble/LE-staging test path.
- [ ] Uploading a pre-issued cert + key pair validates hostname/SAN/key match; invalid pairs are rejected and valid pairs hot-reload through `tls.certificates` without restarting Traefik.
- [ ] Custom ACME with EAB credentials and custom CA bundle validates against a step-ca/Pebble-style integration test.
- [ ] Force-renew runs the documented stop-edit-start cycle, rate-limits repeated attempts, audits request + result, and preserves a backup before mutation.
- [ ] HSTS, OCSP, TLS profile, security headers, and trusted-proxy defaults are visible in generated config and verified by integration tests.
- [ ] An attempt by a plugin to register a route under a reserved path is rejected at plugin-enable time with a clear error and audit event.
- [ ] Traefik dashboard access attempts 404 and emit `tls.dashboard_access_attempt`.

## Test layers

- **Tiny / unit**: cert/key pair validator; hostname/SAN validator; hot-reload-vs-restart classifier; Traefik config renderer; sanitized error generator.
- **Contract**: dynamic config generator against checked example YAML; TLS command/service API against fake file system and fake process runner.
- **Integration**: Traefik + Pebble/step-ca issuance; uploaded cert hot-reload; force-renew backup + restore; route-shadowing rejection.
- **E2E**: Baseline install smoke reaches RackLab over bootstrap HTTPS; full GUI-driven journey waits for M11b.

## Risks / open questions

- **ACME rate limits**: the backend must default to staging in dev/test contexts and make production opt-in explicit. M11b improves the UX, but backend defaults must already be safe.
- **`acme.json` corruption recovery**: M11a ships CLI restore. M11b adds the admin surface; tests need to cover corrupted/missing backups now.
- **OCSP restart boundary**: OCSP remains static config. Any change that touches it requires restart and must be classified that way.
- **Proxmox trust composition**: M3's provider CA bundle handling and RackLab's public TLS certs can come from different issuers; cross-test before promoting M11a.

## Out of scope (deferred)

- TLS admin GUI and E2E admin journey — M11b.
- Scale-profile `lego` cert agent and shared PEM volume — M12.
- DNS-01 challenges and wildcard certificates — post-v1.
- HA Traefik for Baseline — Baseline remains single-host.
- Traefik Enterprise distributed ACME — paid, not in scope.

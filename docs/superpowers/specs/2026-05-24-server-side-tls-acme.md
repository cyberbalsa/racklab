# Server-Side TLS and ACME

**Date:** 2026-05-24
**Status:** Decided.
**Decision owner:** Forrest Fuqua
**Scope:** How RackLab issues, renews, and serves its own public-facing TLS certificate for the web UI / API / SSE / WebSocket endpoints. Proxmox-side TLS trust (RackLab as a *client* to Proxmox) is covered in `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md` §4.2 and out of scope here.

## 1. Decision

1. **Traefik 3.x is the default reverse proxy and TLS terminator** for both the Baseline and Scale Podman profiles. It handles ACME (in Baseline), HTTP→HTTPS redirect, HSTS, OCSP stapling, modern TLS profile, and routing to the RackLab web tier and any sibling plugin containers.
2. **Four issuance profiles are supported**, switchable from the admin GUI: **Let's Encrypt** (HTTP-01, default), **custom ACME** (institutional directories such as `step-ca` or a campus internal CA, with optional EAB credentials), **uploaded certificate** (operator pastes/uploads a pre-issued cert + key from an external CA), and **self-signed** (first-run bootstrap, also a steady-state mode for air-gapped/dev). The operator picks one per deployment.
3. **URLs are single-domain and path-based.** Everything lives under `https://<deployment-domain>/…`. One certificate per RackLab deployment with an optional SAN list of additional DNS names that resolve to the same RackLab instance. Wildcards and DNS-01 challenges are explicitly out of scope for v1.
4. **The admin GUI is the operator's surface for all routine TLS work**, and the spec is honest about which operations are hot-reloadable vs. which require a Traefik restart (§5). Operators do not edit Traefik config files. Restarts are short (≤3s) and gated by a confirm dialog when the operation requires one.
5. **The Scale-profile ACME design uses an external cert agent (`lego`) writing PEM files to a shared volume, with Traefik consuming static certificates via the `tls.certificates` dynamic-config block.** This sidesteps the Traefik-OSS multi-instance ACME restriction. Single-active-edge with HTTP-01 challenge routing to that edge is a documented alternative for deployments that prefer it.

## 2. Context

RackLab serves browser UI, REST/OpenAPI, SSE streams, and WebSocket terminals over the same hostname. TLS termination has to be solid: the alternative — running an internal CA + asking every student to install a root cert before they can use the lab — is operationally hostile.

The PRD's Engineering and Security sections (`docs/prd/17-engineering-quality-typing-ci.md`, `docs/prd/18-security.md`) require secure-by-default browser flows, HSTS, secure cookies, and audit logging of admin changes. The PRD's Admin persona (`docs/prd/03-users-personas.md`) lists "branding and theme configuration without template editing" as an admin need; TLS/ACME is the same shape: routine sysadmin tasks belong in the admin GUI, not in `vi /etc/traefik/`.

The catch is that Traefik's configuration model is split: certificate resolvers, entry points, the file provider's watch directory, and ACME options are **static** (loaded at process start, require restart to change); routers, services, middlewares, and `tls.certificates` blocks are **dynamic** (hot-reloadable via file-watch). The admin GUI must respect that boundary.

## 3. The four issuance profiles

### 3.1 Let's Encrypt (default)

Static-config `certificatesResolvers.le.acme` block with the public LE production directory, `email = <operator-supplied>`, `storage = /acme/acme.json`, HTTP-01 challenge via the `web` entryPoint on port 80. Configured once at install time; subsequent operator-side changes that touch these fields trigger a Traefik restart.

The admin GUI exposes a **"use LE staging while I verify DNS"** toggle that switches `caServer` to `https://acme-staging-v02.api.letsencrypt.org/directory`. Toggling it is a restart-required operation; the UI says so.

LE has documented [rate limits](https://letsencrypt.org/docs/rate-limits/) per registered domain per week. The "staging" path exists precisely to avoid burning production-issuance budget on a misconfigured deployment.

### 3.2 Custom ACME

For institutional internal ACME directories (`step-ca`, campus internal CA, enterprise ACME):

```yaml
# static config
certificatesResolvers:
  custom:
    acme:
      email: <operator email>
      storage: /acme/acme-custom.json
      caServer: <operator-supplied ACME directory URL>
      caCertificates:
        - /etc/racklab/tls/custom-acme-ca.pem
      caSystemCertPool: true
      eab:                                # optional, often required by enterprise ACME
        kid: <operator-supplied kid>
        hmacEncoded: <operator-supplied b64>
      httpChallenge:
        entryPoint: web
```

EAB (External Account Binding) fields are exposed in the admin GUI because many enterprise ACMEs (step-ca defaults, ZeroSSL, internal CA stacks) require them. Many operators forget; the GUI prompts.

Any change to `caServer`, `caCertificates`, `email`, `storage`, or EAB fields is a static-config change and triggers a Traefik restart. The GUI says so.

### 3.3 Uploaded certificate

Operator pastes/uploads a pre-issued cert + key pair (PEM format), typically from an external CA the institution already runs. Traefik consumes it via the **dynamic** `tls.certificates` block; this profile is hot-reloadable.

```yaml
# dynamic config (file-watched, hot-reloadable)
tls:
  certificates:
    - certFile: /etc/racklab/tls/uploaded-cert.pem
      keyFile: /etc/racklab/tls/uploaded-key.pem
      stores:
        - default
```

Server-side validation on upload: PEM format check, key matches cert, cert is in date, primary CN/SAN matches the configured deployment domain. Upload itself triggers a write-and-rename to the watched dynamic file. No Traefik restart.

### 3.4 Self-signed

Two ways the self-signed profile gets used:

- **First-run bootstrap.** Before the operator has configured a domain and issuance profile, RackLab serves a self-signed cert generated at install time. ECDSA P-256, 365-day validity, CN = `<hostname>`, SAN = `<hostname>`. (ECDSA P-256 rather than Ed25519 because the browser/client compatibility matrix for P-256 is universal; Ed25519 in TLS server certs is supported but not universally.) The admin UI surfaces a prominent banner: "RackLab is using a self-signed certificate. Configure an ACME issuer below to get a trusted certificate." This is a hot-reloadable dynamic certificate.
- **Steady-state self-signed.** Air-gapped deployments stay on self-signed. RackLab regenerates the cert annually by default (operator-configurable interval). Same dynamic mechanism as 3.3.

## 4. Hot-reload vs restart boundary

The admin GUI is honest about which operations are which. This is the single most important UX point in this spec.

**Hot-reload (no restart, ≤2s settle):**

- Upload a new pre-issued cert + key (profile §3.3).
- Regenerate the bootstrap or steady-state self-signed cert (profile §3.4).
- Change which certificate a specific router uses (`router.tls.certResolver`) or which TLS store / `tls.certificates` entry serves a hostname.
- Adjust HSTS, CSP, security-header middlewares.
- Add/remove routers, services, middlewares.

**Restart-required (≤3s downtime, confirm dialog in GUI):**

- Switch issuance profile between §3.1 (LE) ↔ §3.2 (custom ACME) ↔ §3.3 (uploaded) ↔ §3.4 (self-signed).
- Change LE staging ↔ production.
- Change `email`, `caServer`, `caCertificates`, EAB credentials on a custom ACME resolver.
- Change the HTTP-01 challenge entryPoint.
- Enable/disable OCSP stapling (`ocsp: {}` is a static block in Traefik 3.7).
- Change the entryPoints themselves (rare).
- Force-renew via the supported pattern (§7).

The GUI shows a **status pill** on each setting: "hot-reload" or "restart" with the expected downtime. The status panel after a save tracks Traefik's actual restart and reports completion.

A Traefik restart is performed via systemd (`systemctl restart traefik` on the host running the Quadlet) — the Baseline profile has one Traefik instance, Scale has the architecture from §6. Restarts are atomic and brief; pending HTTP requests are drained (Traefik's default graceful shutdown).

## 5. Admin GUI surface

The admin panel at **System Settings → TLS** is where all routine TLS work happens. The operator never needs a shell on the host for any of the following:

| Task | UI surface | Reload kind |
|---|---|---|
| Set the deployment domain (and optional SAN list — must be additional DNS names for the same RackLab instance) | Single text field + comma-separated SAN list. Validated against DNS resolution before save. | Dynamic (router config) |
| Pick the issuance profile (LE / custom ACME / uploaded / self-signed) | Radio + per-profile form | Restart |
| LE staging vs production toggle | Toggle | Restart |
| Supply ACME account email | Email field, validated | Restart |
| Supply custom ACME directory URL + EAB credentials + uploaded CA chain | URL field + two text fields (kid, hmac) + file upload | Restart |
| Upload a pre-issued cert + key pair | Two file uploads, validated server-side | Dynamic |
| View current cert: subject, SANs, issuer, not-before, not-after, days-until-expiry, fingerprint | Read-only panel, refreshed live (SSE per PRD §15) | — |
| Force-renew | Button with confirm dialog (§7). Rate-limited to 1/hour. | Restart |
| View last-renewal outcome from Traefik logs / Prometheus metrics | Read-only panel; operator-presented text sanitized to avoid leaking internal CA URLs or account IDs (full detail is in audit/logs) | — |
| Configure HSTS max-age, includeSubDomains, preload | Form fields. Defaults: max-age 1 year, includeSubDomains OFF (opt-in only when RackLab owns the whole DNS zone), preload OFF (requires opt-in commitment) | Dynamic (middleware) |
| Pick TLS profile | Dropdown: "Modern" (TLS 1.3 only) / "Intermediate" (TLS 1.2+, default) / "Operator-supplied (advanced)" | Dynamic |
| Configure security headers (`X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `sniStrict`, request-size limits) | Form with safe defaults pre-filled | Dynamic |
| Configure trusted proxies / `X-Forwarded-*` policy | Form. Default: trust no upstream. | Dynamic + static for upstream-trust changes |
| Health: ACME provider reachable? Storage writable? Watch directory mounted? Cert in date? | Status panel with green/yellow/red per probe | — |
| Backup / restore `acme.json` (manually, audited) | Two buttons. Restore prompts confirm + reason. | Restart |

All changes are **audit-logged** with actor, before-state, after-state, and reason field (per PRD §14). The audit row stores the full upstream error verbatim where applicable; the operator UI shows a sanitized summary.

The admin UI never asks the operator to write Traefik syntax. RackLab generates the dynamic configuration; the Traefik service unit is templated at install for the static side.

## 6. Scale profile: external cert agent + Traefik consumes static certs

Traefik OSS **does not support multi-instance ACME**: challenge traffic from the CA may hit the wrong replica, and the older KV coordination was removed in 3.x. Sharing `acme.json` across replicas does not solve this. The realistic Scale-profile architectures, in order of preference:

### 6.1 External cert agent (recommended)

A small `lego`-based cert agent (or `certbot`, or `step-cli`) runs as a Quadlet on a dedicated cert-management host (or one well-known edge host). It handles the ACME dance, writes PEM files to a shared volume mounted into every Traefik replica's `tls.certificates` watch directory. Traefik instances consume the PEMs as static-uploaded certs (profile §3.3 shape, applied at the architecture level).

Renewal: the cert agent runs `lego --renew` on a cron (or systemd timer) at the standard cadence. On successful renewal, the new PEM is written atomically to the shared volume; Traefik file-watch picks it up on every replica. No restart.

HTTP-01 challenge: the cert agent runs a tiny challenge responder on port 80 of its own host; the load balancer in front of the Traefik fleet routes `/.well-known/acme-challenge/*` to that host, everything else to Traefik. Many ingress LBs (HAProxy, nginx in front, the cloud LB) handle this trivially with a single rule.

This is the canonical pattern for non-K8s multi-instance Traefik ACME. RackLab's installer for the Scale profile sets up the cert agent + the LB rule as part of the runbook.

### 6.2 Single active ACME edge

One Traefik instance is designated the active ACME edge. The load balancer routes `/.well-known/acme-challenge/*` to that one edge; other Traefik instances run with `acme.json` mounted **read-only** and never participate in challenges. They still consume cert material from the shared `acme.json` via Traefik's normal cert loading.

This works but has more sharp edges than 6.1: cert material in `acme.json` is Traefik-owned-and-locked from the active edge's perspective, and stale reads on the read-only replicas during writes are possible. For v1 the spec recommends 6.1 unless the operator has a specific reason.

### 6.3 Traefik Enterprise (paid)

Traefik Enterprise has distributed ACME with a built-in coordination layer. Out of scope for RackLab open-source; recorded only so the option isn't lost.

### 6.4 cert-manager (Kubernetes only)

If a deployment is K8s-native (not RackLab's baseline), cert-manager is the right pattern. Out of scope for the Podman-first design.

## 7. Force-renew

The admin GUI's "Force renew" button does not edit `acme.json` while Traefik is running (which is the path the prior draft of this spec wrongly suggested). The supported pattern, per Traefik's own force-update guidance:

1. Take a backup of the current `acme.json` (RackLab keeps 7 daily rotating backups).
2. Stop Traefik (Baseline profile: `systemctl stop traefik`).
3. Remove the relevant entry from `acme.json` atomically. (Or move `acme.json` to `acme.json.old` and let Traefik start fresh, depending on the operator's preference; the GUI exposes both.)
4. Start Traefik (`systemctl start traefik`).
5. Traefik issues a new cert on next request (or eagerly at startup if the router is configured to require it).
6. Watch the resulting renewal in the GUI status panel.

The button is **rate-limited to 1 per hour** to prevent the operator from triggering an LE rate-limit block.

In the Scale profile (§6.1 architecture), force-renew calls `lego --renew --force` on the cert agent and waits for the PEM file to update. No Traefik restart needed.

Failures are surfaced from Traefik logs and `lego` exit codes, audit-logged verbatim, presented to the operator in sanitized form.

## 8. Renewal automation

Baseline (Traefik manages ACME directly): Traefik auto-renews when certs are within ~30 days of expiry. Failures emit RackLab audit events from a log-parser that tails Traefik's log; the parser is the **source of truth for renewal status**, not `acme.json`.

Scale (external cert agent per §6.1): the cert agent's systemd timer runs renewals. Same log-parsing audit pattern applied to `lego` output.

RackLab notifications fire (per PRD §13 notification plugins) at 14 days, 7 days, 3 days, and 1 day before expiry if a cert is approaching expiry without successful renewal. The expiry-check probe runs every 5 minutes against the deployed certificate (HTTPS handshake, X.509 parse), not against `acme.json`.

## 9. Hardening defaults

Defaults in the RackLab-generated dynamic config (all hot-reloadable via the file-provider middlewares):

- **HSTS** — `max-age = 31536000` (1 year), `includeSubDomains = false` by default (operator opts in only when RackLab owns the whole DNS zone), `preload = false` (commitment to public HSTS preload list submission must be explicit).
- **OCSP stapling** — explicit static config `ocsp: {}` in `traefik.yml`; not implicit. Restart-required to toggle.
- **TLS profile** — default **Intermediate** (TLS 1.2+, modern cipher suite list). Educational labs typically have managed-desktop and old-appliance clients that don't all speak TLS 1.3. "Modern" (TLS 1.3 only) is the alternative preset for operators with a known modern-only client base. "Operator-supplied" exposes the raw `tls.options` block.
- **`sniStrict = true`** — reject TLS handshakes without SNI on a multi-cert deployment.
- **`X-Content-Type-Options: nosniff`** — middleware default-on.
- **`Referrer-Policy: strict-origin-when-cross-origin`** — middleware default-on, configurable per deployment.
- **`Permissions-Policy`** — minimal default (`camera=(), microphone=(), geolocation=()`); operator can extend.
- **`X-Frame-Options: DENY`** or `Content-Security-Policy: frame-ancestors 'none'` — default-on (the docs plugin's embed needs may relax this for its routes only).
- **`SameSite=Lax`** default for cookies, `SameSite=Strict` for sensitive sessions per PRD §18. Set by Django, not Traefik.
- **HTTP→HTTPS redirect** on the `web` entryPoint, no exceptions other than the LE / `lego` challenge path `/.well-known/acme-challenge/`.
- **Trusted-proxy policy** — default: trust no upstream. Operators behind a corporate LB must explicitly configure trusted proxy IPs in the GUI; this is a restart-required change.
- **HTTP request size / header size limits** — Traefik defaults are sane; surfaced in the GUI for operators who need to relax them for the docs plugin's image uploads or similar.
- **Access-log redaction** — Traefik access logs strip the `Authorization` header and any cookie matching configured names by default.
- **Backup encryption** — `acme.json` backups are encrypted at rest with the operator-supplied secret-backend key.

No Traefik dashboard in production. Routine visibility is in the RackLab admin GUI; deep visibility is in operator-attached Prometheus.

## 10. Audit

Structured audit events (per PRD §14):

- `tls.config.change` — domain, SAN list, issuance profile, HSTS settings, TLS profile preset, security-header config, before/after.
- `tls.cert.uploaded` — uploaded cert fingerprint, validity window, uploader.
- `tls.force_renew` — operator-initiated renewal, reason, outcome, restart duration.
- `tls.renewal.success` / `tls.renewal.failure` — from the Traefik log parser (Baseline) or `lego` exit (Scale), with full upstream response stored verbatim in the audit row.
- `tls.dashboard_access_attempt` — recorded if anyone attempts to reach a Traefik dashboard URL.
- `tls.acme_json.backup` / `tls.acme_json.restore` — manual backup/restore operations.

Audit rows store verbatim error text; the operator-facing GUI displays sanitized text (account IDs, internal CA URLs, network-path detail redacted in the display layer).

## 11. Self-signed bootstrap for first-run

- The installer generates an **ECDSA P-256** keypair, 365-day validity, CN = `<hostname>`, SAN = `<hostname>`.
- Written to `/etc/racklab/tls/bootstrap-cert.pem` and `/etc/racklab/tls/bootstrap-key.pem`.
- Traefik is configured to use it via the dynamic `tls.certificates` block.
- The admin UI shows the prominent "configure an ACME issuer" banner until the operator picks a profile.
- Once the operator switches to LE / custom ACME / uploaded cert and the first real cert lands successfully, the bootstrap cert + key are deleted and the dynamic config no longer references them.
- Switching *back* to self-signed regenerates a fresh cert; old uploaded certs are not retained unless the operator explicitly saved them via the backup mechanism.

A fresh RackLab install is reachable over HTTPS from the very first request: accept the cert warning, set up DNS, point RackLab at LE, watch the cert auto-issue.

## 12. Storage of cert state

- **`acme.json`** (Baseline): managed by Traefik, mounted on a persistent volume, owned by Traefik's UID. RackLab reads it only via mediated paths for metadata. Force-renew goes through the §7 stop-edit-start flow, never an in-place edit while Traefik is running.
- **Uploaded / bootstrap / self-signed PEMs**: `/etc/racklab/tls/`, owned by the RackLab service account, mounted into Traefik read-only.
- **`acme-custom.json`** (custom ACME profile): same model as `acme.json`, separate file.
- **Custom-ACME root CA chain**: `/etc/racklab/tls/custom-acme-ca.pem`, referenced from Traefik's `caCertificates`.
- **TLS configuration history**: RackLab persists the last N versions of each TLS config object in Postgres for rollback. Default N = 20.
- **`acme.json` backups**: 7 daily rotating, encrypted at rest. Restored only via the audited admin UI flow.
- **Scale profile PEMs** (per §6.1): shared volume mounted into all Traefik replicas, written atomically by the cert agent.

## 13. Multi-tenant edge concerns

The TLS edge is also a routing edge that handles plugin containers (docs plugin, future SSH plugin, future BookStack fallback, etc.). Defenses:

- **Strict path routing.** Plugins do not register routes that shadow `/admin`, `/api`, `/static`, `/media`, or `/.well-known/`. Plugin route prefixes are validated at install time.
- **Host allow-listing in Django** (`ALLOWED_HOSTS`) — Django rejects requests whose `Host` header doesn't match the configured deployment domain or SANs.
- **WebSocket origin checks** — Channels consumers (the SSH plugin, console-proxmox, future consoles) validate `Origin` headers match the deployment domain. Reject requests from foreign origins.
- **SSE origin checks** — same pattern for SSE endpoints.
- **No tenant-controlled Traefik snippets.** Plugins declare desired routes/middlewares through the plugin contract; RackLab translates to Traefik config. No plugin can write arbitrary Traefik syntax.
- **Per-plugin security-header policy.** Plugin contract allows a plugin to *relax* a header on its own route prefix only (e.g., the docs plugin embedding an iframe loosens `X-Frame-Options` for its routes, not globally), with admin opt-in.

## 14. Open risks

- **Operator forgets to switch off self-signed.** Banner stays up; admin notification weekly until ACME is configured or self-signed is explicitly chosen as steady-state.
- **LE rate limits during repeated misconfiguration.** The "staging while I verify DNS" toggle is the first thing the form recommends.
- **HTTP-01 prerequisites not met** (DNS not pointing here, port 80 blocked, redirect breaks the challenge path). Health probes check all three before allowing the operator to save an LE-profile change; clear error if any fail.
- **Operator uploads cert for the wrong domain.** Server-side validation rejects at form level with a clear error.
- **Custom ACME directory unreachable mid-renewal.** Notifications fire on the 14/7/3/1 day countdown; renewal failures are surfaced with full upstream error in audit and sanitized text in the GUI.
- **`acme.json` corruption.** Daily encrypted backups; restore is an audited admin UI flow that takes Traefik through a clean stop→restore→start cycle.
- **Scale profile cert-agent host is a single point of failure.** Mitigation: cert PEMs on the shared volume have validity windows ≥ 60 days; a cert-agent outage is tolerable for days, not hours. A second cert agent on a hot-standby host is documented as a follow-up.
- **Browser/client TLS 1.3 compatibility.** Default profile is "Intermediate" (TLS 1.2+) precisely because educational labs often have managed desktops or appliances that don't speak TLS 1.3.
- **HSTS preload commitment is durable.** Operators who enable preload should know it's effectively irrevocable on the public preload list. The GUI says so.

## 15. What I'd want to verify before committing

1. Time a fresh install on a real box: install → admin login → set domain → switch from self-signed to LE → first cert issues. Target: under 5 minutes assuming DNS is correct.
2. Implement and exercise the §7 force-renew path against LE staging. Confirm the stop-edit-start cycle works and downtime is ≤3s.
3. Test the §3.2 custom-ACME path against a local `step-ca`, with and without EAB credentials. Confirm `caCertificates` + `caSystemCertPool` composition.
4. Stress the dynamic file-watch reload: rapid successive uploaded-cert changes shouldn't cause Traefik to flap. Verify atomic-rename semantics and the 2s provider throttle.
5. Build the Scale §6.1 architecture end-to-end: cert agent + LB challenge routing + shared-volume PEMs + Traefik consuming. Confirm a real LE issuance.
6. Test the cert-upload mismatched-domain rejection. Confirm the error is human-readable.
7. Run the admin UI through the accessibility audit per PRD §15. TLS settings is a critical-flow page; AAA contrast and proper ARIA on the status panel.
8. Confirm the Traefik log parser produces an audit event within ≤10s of a successful or failed renewal.

## 16. Confidence

**High** on Traefik 3.x as the substrate, on the four-profile model, and on the hot-reload-vs-restart boundary table in §4-5.

**High** on the Scale-profile §6.1 external-cert-agent architecture. It's the canonical pattern when Traefik OSS multi-instance ACME is off the table.

**High** on the §9 hardening defaults. They reflect 2026 best practice for an educational deployment.

**Medium** on the §7 force-renew restart cadence. Real-world tuning may want a different rate limit; 1/hour is the starting default.

**Medium** on the §5 GUI surface being deliverable in v1. The number of fields is large; some may be deferred to v1.1 (security-header advanced config, trusted-proxy editor) without losing the "dead easy normal sysadmin tasks" goal.

**Medium** on the Scale §6.1 cert-agent single-point-of-failure mitigation. The 60-day cert window is durable; a hot-standby cert agent is the right follow-up if a deployment hits operational pain.

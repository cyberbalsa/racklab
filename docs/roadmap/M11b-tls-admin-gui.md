# M11b — TLS / ACME: Admin GUI

**Status:** Not started.
**Estimated effort:** 1–2 weeks.
**Depends on:** M11a, M10a (admin shell + UI patterns).
**Unblocks:** v1 admin completeness.

## Goal

System Settings → TLS becomes the canonical operator surface for all routine TLS work. Operators never touch a Caddyfile for the admin-GUI-supported tasks. The hot-reload-vs-restart boundary is surfaced honestly in the UI: each setting shows "hot-reload" or "restart" with the expected downtime; restart-required operations have a confirm dialog. Force-renew and cert backup/restore are one-click operations with audit logging.

## In scope

- The full TLS admin GUI surface (profiles and fields documented in the M11a backend section and `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §3), implemented as a Filament 5 admin page in `app/Filament/Pages/TlsSettings.php`.
- The named E2E user journey "admin-configure-ACME-issuer" from PRD §17.

## Dependencies

- M11a — Caddy-in-FrankenPHP TLS backend + Caddyfile config generation + audit + force-renew CLI.
- M10a — admin shell + the rest of the System Settings UI; the TLS page is one of the admin Key Screens.

## Deliverables

- Filament 5 admin page at **System Settings → TLS** (`app/Filament/Pages/TlsSettings.php`) with every field from the TLS spec §5:
  - Set domain + SAN list (validated against DNS resolution before save).
  - Pick issuance profile (radio group + per-profile sub-form using Filament's `Section` + `Grid` layout).
  - LE staging vs. production toggle.
  - ACME account email.
  - Custom ACME directory URL + EAB credentials + uploaded CA chain.
  - Upload pre-issued cert + key pair (with server-side validation) via `spatie/livewire-filepond` embedded in the Filament page.
  - Live cert status (subject, SANs, issuer, not-before, not-after, days-to-expiry, fingerprint) refreshed via Livewire polling or Reverb broadcast.
  - Force-renew button with Filament confirm modal; rate-limited to 1/hour.
  - Last-renewal outcome panel (sanitized for the operator; verbatim in audit).
  - HSTS settings (max-age, `includeSubDomains`, preload — each with safe defaults + extra confirm modal for preload given its durability).
  - TLS profile dropdown (Modern / Intermediate / Operator-supplied).
  - Security header config (`sniStrict`, `content-type-nosniff`, `referrer-policy`, `permissions-policy`, request-size limits).
  - Trusted-proxy editor (default: trust no upstream).
  - ACME provider health probes panel.
  - Cert backup + restore one-click operations.
- Each setting shows a **"hot-reload" or "restart"** indicator (Filament `Placeholder` with badge) with the expected downtime; restart-required operations confirm before applying.
- The status panel after a save tracks FrankenPHP's actual reload/restart and reports completion via Livewire polling against the M11a renewal-status probe.
- All admin actions emit the TLS audit events from M11a; verbatim errors stored in audit; sanitized text presented to operator.

## Acceptance criteria

- [ ] Fresh `scripts/baseline-install.sh` + admin login: the prominent Filament notification banner "RackLab is using a self-signed certificate" appears; clicking through goes to System Settings → TLS.
- [ ] Admin sets a domain + selects Let's Encrypt + supplies an email + clicks Save: Filament validates DNS + port 80 reachability before submission; the restart confirm modal appears; FrankenPHP reloads; the cert issues; the live status panel refreshes to show the new cert.
- [ ] Admin toggles LE staging → production; confirm modal explains the rate-limit risk; the toggle works.
- [ ] Admin uploads a pre-issued cert for the wrong domain; server-side validation rejects with a clear, accessible Filament error notification.
- [ ] Admin clicks Force-renew; confirm modal; backup + stop + edit + start cycle completes; new cert observable; rate-limit prevents a second renew within the hour.
- [ ] Admin enables HSTS preload after the durable-commitment confirm modal; the audit event records the durable change.
- [ ] axe-core finds no new violations on the TLS settings page (Dusk a11y gate); screen-reader testing confirms the status panel's live region announces cert-state changes.
- [ ] E2E Dusk test: admin-configure-ACME-issuer journey runs end-to-end against LE staging.

## Test layers

- **Tiny / unit**: form validators (DNS-resolution check, cert/key pair match, EAB credential shape); confirm-modal content rendering per restart-required action; hot-reload-vs-restart classifier mapping to Filament badge labels.
- **Contract**: the Filament page form submissions against the M11a backend Artisan commands (faked via `Bus::fake()`); Livewire polling for cert-state changes against fake renewal-status probe.
- **Integration**: full GUI-driven LE-staging issuance against Testcontainers (Postgres + Redis + Podman socket) + Pebble; GUI-driven custom-ACME against step-ca; GUI-driven force-renew + rate-limit verification. Uses Dusk for browser interactions.
- **E2E (Dusk)**: the named "admin-configure-ACME-issuer" journey — admin configures, watches the cert issue, force-renews. axe-core regression gate stays green.

## Risks / open questions

- **The hot-reload-vs-restart boundary**: misclick that causes an unintended restart in production is real harm. Verify the Filament confirm modal UX with manual testing; consider a "preview the change" mode that shows what would happen without applying.
- **HSTS preload confirm modal wording**: needs to make the durability explicit ("this is effectively irrevocable on the public HSTS preload list"). Manual review with a non-developer admin.
- **Form validation against external resources** (DNS-resolution + port-80-reachability checks happen server-side): slow operations; Filament shows a loading indicator via `$this->dispatch('start-polling')` + `wire:loading`; timeout at 10s; document the failure modes.
- **`spatie/livewire-filepond` + Filament page integration**: filepond is a Livewire component; embedding inside a Filament `Page` (not a `Resource`) requires using `@livewire()` or a Filament `ViewField` wrapper — test the file-upload state handoff to Filament's form early.

## Out of scope (deferred)

- Scale-profile TLS (Caddy/FrankenPHP replicas or upstream load-balancer TLS termination) — M12.
- Custom Caddy admin API dashboard replacement — the RackLab admin GUI's TLS panel is the canonical view; Caddy's admin API stays on `localhost` only.
- Per-deployment SAN management beyond the install-time domain — a v1.1 concern.

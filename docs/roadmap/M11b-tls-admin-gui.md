# M11b — TLS / ACME: Admin GUI

**Status:** Not started.
**Estimated effort:** 1–2 weeks.
**Depends on:** M11a, M10a (admin shell + UI patterns).
**Unblocks:** v1 admin completeness.

## Goal

System Settings → TLS becomes the canonical operator surface for all routine TLS work. Operators never touch a Traefik config file for the admin-GUI-supported tasks. The hot-reload-vs-restart boundary is surfaced honestly in the UI: each setting shows "hot-reload" or "restart" with the expected downtime; restart-required operations have a confirm dialog. Force-renew and `acme.json` backup/restore are one-click operations with audit logging.

## In scope

- `docs/superpowers/specs/2026-05-24-server-side-tls-acme.md` §5 (the full admin GUI surface).
- The named E2E user journey "admin-configure-ACME-issuer" from PRD §17.

## Dependencies

- M11a — Traefik backend + config generation + audit + force-renew CLI.
- M10a — admin shell + the rest of the System Settings UI; the TLS page is one of the admin Key Screens.

## Deliverables

- Admin panel page at **System Settings → TLS** with every field from the TLS spec §5:
  - Set domain + SAN list (validated against DNS resolution before save).
  - Pick issuance profile (radio + per-profile form).
  - LE staging vs production toggle.
  - ACME account email.
  - Custom ACME directory URL + EAB credentials + uploaded CA chain.
  - Upload pre-issued cert + key pair (with server-side validation).
  - Live cert status (subject, SANs, issuer, not-before, not-after, days-to-expiry, fingerprint) via SSE.
  - Force-renew button with confirm dialog; rate-limited to 1/hour.
  - Last-renewal outcome panel (sanitized for the operator; verbatim in audit).
  - HSTS settings (max-age, includeSubDomains, preload — each with safe defaults + confirm dialog for preload).
  - TLS profile dropdown (Modern / Intermediate / Operator-supplied).
  - Security header config (sniStrict, content-type-nosniff, referrer-policy, permissions-policy, request-size limits).
  - Trusted-proxy editor (default: trust no upstream).
  - ACME provider health probes panel.
  - `acme.json` backup + restore one-click operations.
- Each setting shows a **"hot-reload" or "restart"** indicator with the expected downtime; restart-required operations confirm before applying.
- The status panel after a save tracks Traefik's actual restart and reports completion via SSE.
- All admin actions emit the TLS audit events from M11a; verbatim errors stored in audit; sanitized presented to operator.

## Acceptance criteria

- [ ] Fresh `scripts/baseline-install.sh` + admin login: the prominent banner "RackLab is using a self-signed certificate" appears; clicking through goes to System Settings → TLS.
- [ ] Admin sets a domain + selects Let's Encrypt + supplies an email + clicks Save: form validates DNS + port 80 reachability before submission; the restart confirm dialog appears; restart happens; the cert issues; SSE pushes the live status update to the page.
- [ ] Admin toggles LE staging → production; confirm dialog explains the rate-limit risk; the toggle works.
- [ ] Admin uploads a pre-issued cert for the wrong domain; server-side validation rejects with a clear, accessible error.
- [ ] Admin clicks Force-renew; confirm dialog; backup + stop + edit + start cycle completes; new cert observable; rate-limit prevents a second renew within the hour.
- [ ] Admin enables HSTS preload after the durable-commitment confirm dialog; the audit event records the durable change.
- [ ] axe-core finds no new violations on the TLS settings page; screen-reader testing confirms the status panel's live region announces cert-state changes.
- [ ] E2E test: admin-configure-ACME-issuer journey runs end-to-end against LE staging.

## Test layers

- **Tiny / unit**: form validators (DNS-resolution check, cert/key pair match, EAB credential shape); confirm-dialog content rendering per restart-required action.
- **Contract**: the admin-page form submissions against the M11a backend; SSE event publishing for cert-state changes.
- **Integration**: full GUI-driven LE-staging issuance against testcontainers + Pebble; GUI-driven custom-ACME against step-ca; GUI-driven force-renew + rate-limit verification.
- **E2E**: the named "admin-configure-ACME-issuer" journey — admin configures, watches the cert issue, force-renews. axe-core regression gate stays green.

## Risks / open questions

- **The hot-reload-vs-restart boundary**: misclick that causes an unintended restart in production is real harm. Verify the confirm dialog UX with manual testing; consider a "preview the change" mode that shows what would happen without applying.
- **HSTS preload confirm dialog wording**: needs to make the durability explicit ("this is effectively irrevocable on the public HSTS preload list"). Manual review with a non-developer admin.
- **Form validation against external resources** (DNS-resolution + port-80-reachability checks happen server-side): slow operations; show a spinner; timeout at 10s; document the failure modes.

## Out of scope (deferred)

- Scale-profile TLS via the `lego` cert agent — M12.
- A custom Traefik dashboard replacement — the RackLab admin GUI's TLS panel is the canonical view; Traefik dashboard stays disabled.
- Per-deployment SAN management beyond the install-time domain — a v1.1 concern.

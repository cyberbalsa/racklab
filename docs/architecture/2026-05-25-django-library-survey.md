# Django ecosystem library survey for RackLab

**Date:** 2026-05-25.
**Method:** eight parallel research passes across PyPI, GitHub, official docs, and a few vendor blogs. Each candidate library was checked for current version, license, Django 5.2 compatibility, and maintenance signal. Recommendations are tagged **Adopt**, **Adopt for specific scope**, **Defer**, **Skip — Django built-in suffices**, **Skip — abandoned**, or **Skip — wrong fit**.
**Stack assumed pinned:** Django 5.2 LTS, DRF 3.16, drf-spectacular, channels 4.2, pluggy 1.6, pydantic 2.x, asyncssh, nats-py, psycopg, proxmoxer; **frontend pivoted mid-session from Bootstrap+jQuery to Django + React islands via django-vite, with Mantine + Radix UI gaps** (see binding constraints below and §20); Traefik 3 fronts the app; Podman Baseline + Nomad Scale profiles; ruff + mypy + basedpyright + bandit + semgrep + pip-audit in CI.
**Goal:** stop reinventing wheels. For each thing RackLab is building, prefer an established library when one exists, document why when one doesn't.

This survey is descriptive, not prescriptive. Adoptions still need PRD/roadmap edits and pyproject pins before they bind.

---

## Binding frontend constraints (revised 2026-05-25 — React pivot)

The team's earlier "Bootstrap 5 + jQuery for all UI" stance was reversed mid-session in favor of **Django + React islands via django-vite, with Mantine or Chakra as the React component library**. The PRD §15 jQuery plugin slate (DataTables, Select2, Flatpickr, jQuery Validate, SortableJS, jstree, Toastr, bootbox) is **out** and replaced by React-native equivalents covered in §20. PRD §15 itself needs editing to reflect this.

These constraints apply to every UI surface RackLab ships — operator admin, user-facing, plugin-shipped templates, every dashboard. They override library defaults where they conflict.

1. **User-facing UI: Django + React islands via django-vite.** Django renders server-side HTML for layout, auth, CSRF, and SSR-relevant SEO; React mounts on specific component roots via Vite chunks. Components come from **Mantine** (recommended for feature completeness — date picker, tables, forms, notifications built in) or **Chakra** (lighter, more flexible). **No HTMX. No Alpine. No Tailwind. No Vue / Svelte / Solid.**
2. **Operator admin UI: stock Django admin** (Django's bundled jQuery, no theme). Until M10 lands a React-based custom UI shell, stock Django admin is the operator surface. No django-unfold (HTMX/Tailwind/Alpine bundled), no django-jazzmin (Bootstrap 4 mismatch), no django-grappelli (dated). Accept the visual cost as the price of constraint compliance.
3. **Hard a11y requirement.** WCAG 2.2 AA + ARIA APG. Every React component RackLab adopts must (a) ship correct ARIA semantics out of the box, or (b) allow ARIA to be patched in without forking. Mantine is *generally* WAI-ARIA-compliant but requires usage discipline + axe-core + pa11y + Storybook a11y testing to verify each component as it's wired — it is not a free pass. Radix UI primitives are rigorously audited and serve as the fallback for any Mantine component that fails the CI gates. axe-core + pa11y + Storybook a11y addon CI gates apply. *No exceptions for "we'll add ARIA later."*
4. **Hard i18n requirement.** All copy must be translatable. **LinguiJS v6** is the primary recommendation — it stores catalogs natively as `.po` files in the same `locale/<lang>/LC_MESSAGES/` directory Django reads. Django and React share the literal same `.po` files; **plan separate Django and React `domain`s** (`django.po` and e.g. `react.po`) under that directory so the extract-and-compile passes don't collide, and document Lingui's PO-plural-form caveats (Lingui's compiler covers gettext plurals but the team should verify with at least one CLDR pluralization-heavy locale before committing). RTL support required for any locale that needs it. `react-i18next` + `i18next-gettext-converter` is the documented fallback if the Lingui workflow blocks. Mantine ships English-only but accepts translated strings via override; same for Chakra. Details in §20.6.
5. **No Bootstrap-as-CSS dependency in React islands.** Mantine and Chakra come with their own styling systems (CSS-in-JS / CSS Modules). Bootstrap CSS classes don't apply to React-rendered DOM by design. Server-rendered Django pages (layout shell, login, error pages) may still use Bootstrap 5 CSS for the chrome — that's fine and doesn't conflict.

**Adoption filter:** any library failing any of constraints 1–5 is **disqualified** regardless of its other merits. The TL;DR ledger and §20 below reflect this filter.

**Detailed React-side library recommendations are in §20** (in progress — research agent running). Until §20 lands, treat the rest of this survey's frontend-touching sections as pending revision: §8 (admin theme), §15.6 (some i18n libs that were Django-only), §18 (FilePond → `react-filepond`), §12 (Chart.js → `react-chartjs-2`).

---

## TL;DR — adoption ledger

### Adopt now (M0 → M2 window — React side)

| Area | Library | Why |
|---|---|---|
| Build tooling | **Vite 8** + `@vitejs/plugin-react-swc` + **`django-vite` 3.1.0** | Islands architecture; HMR dev, manifest-driven prod. |
| Component library | **Mantine** — pin **latest stable** at M0 ship (currently 9.2.1 per npm registry; see §20.14 open-question on pin discipline) | Feature-complete; built-in dates/forms/notifications/modals/dropzone/spotlight/tiptap. See §20.2. ARIA story is "generally WAI-ARIA-compliant but requires usage discipline + axe-core/pa11y/Storybook a11y testing" — not a free pass. |
| ARIA-fallback primitives | **Radix UI primitives** (`@radix-ui/react-*`) | For any Mantine component where axe-core / pa11y flags WCAG 2.2 AA gaps. |
| Forms (simple) | **`@mantine/form`** | Native Mantine; lightweight. |
| Forms (complex) | **React Hook Form 7.76** + **Zod v4** resolver | Catalog editor, deployment wizard, plugin config. |
| Tables | **TanStack Table 8** + Mantine `<Table>` markup | Sort/filter/paginate logic via TanStack; render via Mantine. |
| Server cache | **TanStack Query v5** | DRF server state; pairs with drf-spectacular codegen. |
| Client UI state | **Zustand v5** | Sidebar collapse, modal stack, theme. |
| i18n | **LinguiJS v6** (`@lingui/core`, `@lingui/react`, `@lingui/vite-plugin`) | Native `.po` storage — single catalog source of truth with Django. |
| Schema validation | **Zod v4** | TS-first; pairs with RHF + TanStack Query. |
| Date pickers | **`@mantine/dates`** | Replaces Flatpickr. |
| Notifications | **`@mantine/notifications`** | Replaces Toastr. |
| Modals | **`@mantine/modals`** + **`@radix-ui/react-dialog`** for stacked/nested cases | Replaces bootbox. |
| Drag/drop | **dnd-kit** | Replaces SortableJS; ARIA-friendly. |
| Tree views | **react-arborist** 3.8.0 | Replaces jstree; verify ARIA with axe. |
| Combobox / select | **Mantine Select / MultiSelect / Combobox** (built-in) | Replaces Select2. |
| Command palette | **`@mantine/spotlight`** | Global resource jump (Ctrl-K). |
| File upload (frontend) | **`react-filepond`** (verify React 18/19 compat) or wrap FilePond core via `useEffect`/`useRef` | Pairs with `@mantine/dropzone` for surface chrome. See §18. |
| Markdown rendering (React) | **`react-markdown` 10** + **`remark-gfm`** + **`react-syntax-highlighter`** | Safe-by-default; CommonMark + GFM. |
| Charts | **`react-chartjs-2`** 5.3.1 (wraps Chart.js per §12) | Wrap with `<AccessibleChart>` HOC for canvas a11y. |
| Rich text (M8) | **`@tiptap/react`** + **`@mantine/tiptap`** | TipTap React binding + Mantine-styled toolbar. |
| Terminal (M4, M9) | **`@xterm/xterm` 6.0.0** + `@xterm/addon-fit` (note package rename) | Vanilla in `useEffect` + `useRef`. |
| VNC (M4) | **noVNC 1.7.0** | Vanilla in `useEffect` + `useRef`. |
| Unit tests | **Vitest 4** + **React Testing Library** | Vite-native; reuses config. |
| Component sandbox | **Storybook 10** + a11y addon | Hard CI requirement — see §20.10. |
| a11y in unit tests | **`vitest-axe`** | Component-level axe assertions. |
| a11y in E2E | **`@axe-core/playwright`** | Already in PRD §17. |
| TypeScript | **TypeScript 5.5+ strict** | Hard requirement for the React stack. |

### Adopt now (M0 → M2 window — Python side)

| Area | Library | Why |
|---|---|---|
| Brute-force lockout | **django-axes** 8.3.1 | Canonical, MIT, classifiers cover Django 5.2 / 6.0, release Feb 2026. |
| Rate limiting | **django-ratelimit** 4.1.0 | DRF auth, public forms, password reset. Older release but stable + tiny. |
| CSP | **django-csp** 4.0 | Mozilla-maintained, Django 4.2–5.2, nonce helper via `request.csp_nonce`. |
| Permissions-Policy | **django-permissions-policy** 4.30.0 | One-middleware, deny-all-features for a control plane. |
| CORS | **django-cors-headers** 4.9.0 | Standard DRF API origin allow-list. |
| Honeypot | **django-honeypot** 1.3.0 | Cheap insurance on public forms; pairs with django-ratelimit. |
| Idle-session timeout (admin/instructor) | **django-session-security** 2.6.8 | Verify bundled JS is jQuery-3-compatible before wiring. |
| Auth + MFA + (optionally) SAML | **django-allauth** 65.x | Already planned for M1. `allauth.mfa` ships TOTP / recovery codes / WebAuthn / passkeys; `[saml]` extra covers SP. |
| Password hashing | **argon2-cffi** via `django[argon2]` | Promote `Argon2PasswordHasher` to position 0 in `PASSWORD_HASHERS`. |
| Settings parsing (Django side) | **django-environ** 0.13.0 | `settings.py` env parsing. |
| Settings parsing (app side) | **pydantic-settings** 2.14.1 | Typed config for plugins / runtime; pydantic already pinned. |
| API tokens (long-lived agent/CLI/plugin) | **django-rest-knox** 5.0.4 — **requires PRD §6/§7 amendment to permit opaque PATs** | Hashed-at-rest, multi-token per user, server-side revoke without JWT blacklist propagation. See §4. |
| API tokens (short-lived browser/console/share-link) | **djangorestframework-simplejwt** 5.5.1 | RS256 + simplejwt blacklist + RackLab-owned `IssuedToken` table for scope / IP / audit. See §4. |
| Signed time-limited share links | **`django.core.signing.TimestampSigner`** (stdlib of Django) | Skip `itsdangerous` — Django's equivalent ships in core and integrates with `SECRET_KEY_FALLBACKS`. |
| Health checks | **django-health-check** 4.4.1 | Pluggable backends; register custom NATS + Proxmox checks for M2.5. |
| DRF / OpenAPI assets | **drf-spectacular-sidecar** 2026.5.1 | Self-hosted Swagger/Redoc — survives CSP without external CDN. |
| DRF error envelope | **drf-standardized-errors** 0.16.0 | Verify RFC 7807 wording before relying on it; doc explicitly says it's not strict 7807. |
| FSM (Job model) | **django-fsm-2** 4.2.4 + **django-fsm-log** 5.0.2 — **conditional on a passing spike** | See §17.1. Original `django-fsm` was archived 2025-10-07; `-2` is the drop-in community fork. Existing `transition_job()` already has atomicity + `state_history` invariant; library route needs a spec proving actor/correlation context survives the `post_transition` signal path. |
| Form rendering | **django-crispy-forms** 2.6 + **crispy-bootstrap5** 2026.3 + **django-widget-tweaks** 1.5.1 | Bootstrap 5 layout DSL + lightweight per-template attribute tweaks. |
| DRF filtering | **django-filter** 25.2 | Filterset framework; pairs with DRF viewsets. |
| Admin theme | **Stock Django admin** (no theme) | The binding constraints (Bootstrap+jQuery only, no HTMX anywhere) disqualify django-unfold (bundles Tailwind/Alpine/HTMX), django-jazzmin (Bootstrap 4 — version mismatch with RackLab's BS5), django-baton (Vue), and django-grappelli (dated UX, but actually jQuery-clean if needed). Stick with stock Django admin until M10 lands the custom UI shell. Django admin has gettext i18n built in and is ARIA-compliant out of the box. See §8 (revised). |
| Admin import/export | **django-import-export** 4.4.1 | Catalog/template/roster seeding. |
| Static files | **whitenoise** 6.12.0 | `ManifestStaticFilesStorage` + immutable headers; Traefik caches the headers we set. |
| Inline CSS/JS bundling | **django-compressor** 4.6.0 | Small but real win on template-fragment assets. |
| Imaging | **Pillow** 12.2.0 | Required by any `ImageField`. |
| Model utilities | **django-model-utils** 5.0.0 | `TimeStampedModel`, `FieldTracker`, `Choices`. Confirm 5.2 compat on the latest tag before pinning. |
| Dev shell | **django-extensions** 4.1 | `shell_plus`, `runserver_plus`. |
| Dev profiling | **django-debug-toolbar** 6.3.0 | Browser inspector. |
| Test factories | **factory-boy** 3.3.3 | Already pinned. |
| Logging substrate | **structlog** 25.5.0 | Mirror every `AuditEvent` to stdout JSON for SIEM defence-in-depth. |
| Error capture | **sentry-sdk** 2.60.0 | Standard Django + DRF + Channels integration. |
| Server metrics | **django-prometheus** 2.4.1 | Middleware + DB instrumentation. M13b graduates Prometheus to first-class. |

### Multi-tenancy (this session)

| Layer | Decision |
|---|---|
| Tenancy shape | **Institution-above-Course**, row-level via a `Tenant` FK on root tables. See §19. |
| Isolation | **Soft, RBAC-enforced.** One DB, tenant context middleware, tenant-aware managers, `tenant.cross_access` audit event on attempted cross-tenant reads. Skip `django-tenants` and `django-multitenant`. |
| Cross-tenant sharing | **Explicit per-resource `sharing_scope` (`tenant_local` default, `shared_with_tenants`, `global`)**. Sharing grants use, not modify; quota counts against the consumer; both tenants get audit events. |
| Library | **django-scopes** 2.0.0 (optional, ~600 LoC) + custom `Tenant` model + tenant middleware (~150 LoC). |

### Adopt at the milestone that creates the need

| Milestone | Library | Use |
|---|---|---|
| M0 (now, after PRD edit) | **`Tenant` model + migration + tenant middleware + tenant-aware managers** | Multi-tenancy from day one. See §19.5. |
| M0 (now) | **FilePond 4.x with `chunkUploads: true`** (core option, not a plugin) + a Django chunked-receive view streaming via `HttpRequest.read()` (filesystem backend) or an S3-multipart coordinator (S3 plugin) | File-upload surface for avatars, instructor uploads, stack imports, and multi-GB ISO/OVA uploads. Replaces archived `blueimp jQuery File Upload`. See §18 for the full protocol + upload-session invariants. |
| M1 (auth + identity) | `django-allauth[mfa]` extras | TOTP / WebAuthn / passkeys for admins, MFA-required on staff accounts. |
| M2 (deployment dashboard) | **django-tables2** 3.0.0 | Server-rendered list pages where DataTables doesn't pull its weight. |
| M2 (deployment dashboard) | **django-formtools** 2.6.1 | Multi-step request-a-lab wizard if/when it arrives. |
| M2 (deployment dashboard) | **Plain Postgres + BRIN indexes + materialized rollups** on a `deployment_event` table | Initial choice. Spike TimescaleDB only if query latency on rollups becomes the bottleneck — see §12 (revised). TimescaleDB advanced features are TSL-licensed (not Apache-2). |
| M2 (deployment dashboard) | **Chart.js** 4.5.1 + `chartjs-adapter-date-fns` 3.0.0 | Already in the PRD slate; confirms the adapter pin. |
| M3 (Proxmox provider) | **Proxmox RRD via proxmoxer** | In-product per-VM graphs without standing up a separate exporter pipeline. |
| M3+ (SSO) | **mozilla-django-oidc** 5.0.2 | OIDC RP for RIT or any tenant exposing an OIDC IdP. |
| M3+ (SSO) | **djangosaml2** 1.12.0 | SAML SP for RIT Shibboleth — better Shibboleth edge-case handling than allauth's SAML extra. |
| M3+ (audit reliability) | **Build custom Postgres outbox + `nats-py` relay** (~150 LoC) | Transactional outbox so audit events survive partial failures. `django-outbox-pattern` is STOMP-centered; `jaiminho` is broker-agnostic function replay. Neither ships a native NATS publisher; the pattern is small enough to hand-roll. See §5.2. |
| M5b (managed networking) | nothing new — built on the M5a primitives | — |
| M6 (quotas + scheduling) | **No library — build custom** | See §5. OpenStack triangle (`limit / reserved / in_use`) on Postgres advisory locks. |
| M7a (cloud-init + scripts) | none specific | — |
| M7b (script sandbox) | **nsjail** (already chosen) + `Ansible Runner` (already in PRD) | — |
| M8 (docs plugin) | **markdown-it-py** 4.2.0 + **nh3** 0.3.5 + **Pygments** 2.20.0 | TipTap-parity on the server; bleach is deprecated. |
| M8 / future docs | **django-jsonform** 2.23.2 | JSON-schema-driven admin widget for editing lab definitions. |
| M9 (SSH plugin) | `gunicorn + UvicornWorker` for HTTP + **daphne** 4.2.1 for the dedicated WS process | Two-process split avoids Channels surprises on the WS protocol path. |
| M10 (UI / a11y / i18n) | **django-sass-processor** 1.4.2 | If Bootstrap SCSS source customization is wanted. |
| M10 (UI / a11y / i18n) | **django-anymail** 15.0 | Pluggable transactional email when notifications land. |
| M11a (TLS backend) | nothing new — Traefik 3.x + `lego` already chosen per the TLS spec | — |
| M13a (HA data tier) | **PgBouncer** (separate service) | PG connection pooling. No Python lib. |
| M13b (observability) | **prometheus-pve-exporter** 3.9.0 + **VictoriaMetrics** v1.144.0 *(optional, for >15-day retention)* + **OpenTelemetry Django instrumentation** *(if cross-service tracing matters)* | Operator dashboards, AGPL Grafana iframes on `/admin/observability/*` only. |
| Anytime | **django-private-storage** 3.1.3 | Access-controlled file serving for any future protected-media surface. |
| LMS integration (future) | **django-lti** 0.10.0 wrapping **PyLTI1p3** 2.0.0 | LTI 1.3 + Advantage. Ship as a plugin, not core. |

### Defer (need-driven; revisit when the use case appears)

`django-hordak` (double-entry ledger — only if billing arrives), `django-pghistory` (Postgres-trigger audit — only if forensic capture from non-ORM writes matters), `django-reversion` (model versioning), `django-silk` (capture-and-replay profiling), `django-taggit` (free-form tags if docs/catalog needs them), `django-treebeard` (hierarchy support if needed), `django-cte` (recursive ORM queries for hierarchy-aware queries), `django-cleanup` (orphan FileField cleanup), `OpenFGA` / `SpiceDB` (ReBAC for multi-tenant scale at the multi-org-SaaS pivot — soft-isolation institutional tenancy in §19 doesn't trigger this yet), `Cerbos` / `OPA` (policy-as-code sidecar), `django-cachalot` / `cacheops` (ORM caching — operational complexity vs gain), `django-resized` (image resize — Pillow direct is fine), `hvac` / `boto3` / Azure / GCP secret SDKs (only as Secret Backend plugins when a deployment demands them), **TimescaleDB extension** (spike-only; not a default adoption — see §12), **`tus.io` / `tusd` sidecar** (FilePond chunked covers the multi-GB upload case; tusd is the upgrade path if FilePond chunked fights us at scale), **`django-eventstream`** 5.3.3 (spike in M2 — see §17.2; codex was right that the original "skip" verdict was wrong).

### Skip — abandoned, deprecated, or wrong fit

`django-stronghold` (subsumed by Django 5.1 `LoginRequiredMiddleware`), `django-secure` (merged into Django core long ago), `django-referrer-policy` (Django built-in), `django-feature-policy` (renamed to `django-permissions-policy`), `django-defender` (works but `django-axes` is fresher and DB-only suffices), `django-cryptography` original (last release 2022; classifiers stop at Django 4.x), `django-cryptography-django5` (Django-5.0-only fork, niche), `django-fernet-fields` / `django-encrypted-model-fields` / `django-pgcrypto-fields` / `django-mirage-field` (all stale), `django-fsm` original (archived 2025-10-07; author monetised to viewflow.fsm), `xworkflows` / `finite-state-machine` (stale), `viewflow` full engine (too heavy), `oso` / `django-oso` (deprecated by maintainers Jan 2024), `Permify` (license conflict between PyPI and repo), `AuthzForce` (XACML niche), `django-tabular-permissions` (unmaintained admin widget), `django-keycloak` (Slump's lib abandoned), `django-guardian` 3.3.1 (covered by RackLab's nested-pack RBAC — see §5.1), `django-quotas` (mpasternacki — never reached PyPI), `django-plans` (SaaS subscription tiers, not compute quotas), `django-limits` (count-based not resource-based), `django-cache-machine` (abandoned 2019), `django-statsd-mozilla` (abandoned 2017), `django-floppyforms` (Django 3.0 max), `django-mptt` (declared unmaintained on PyPI), `django-libsass` (Django 3.2 max), `django-jet-reboot` (AGPL + thin maintenance), `django-jazzmin` / `django-grappelli` / `django-admin-interface` / `django-baton` (all overlap django-unfold without distinguishing wins), `django-vite` / `django-tailwind` (not in stack), `mistune` (overlaps markdown-it-py with smaller ecosystem), `commonmark-py` (stale 2019), `bleach` (deprecation notice 2023-01-23; nh3 succeeds it), `djangorestframework-jwt` jpadilla (abandoned 2017), `djangorestframework-api-key` (no per-user binding — wrong shape), `drf-flex-fields` (last release 2023; niche feature), `djangorestframework-camel-case` (conflicts with drf-spectacular schema names), `django-async-orm` (Django ships async natively now), `django-saml2-auth` (abandoned), `PyLTI` (LTI 1.1 — deprecated by 1EdTech), `django-pinax-notifications` (abandoned), `django-summernote` (Python 3.6–3.9), `django-chartit` / `django-chartjs` (Django ≤ 3 classifiers — roll Chart.js JSON in plain DRF views), `python-logstash` (use `python-logstash-async` if Logstash is the target), `django-tenants` (schema-per-tenant — overkill for soft-isolation tenancy; see §19.4), `django-multitenant` (Citus-shaped; wrong DB topology), `django-organizations` (generic org/membership; covered by custom Tenant + membership model), `django-chunked-upload` (last release 2022; wrong shape — Django assembles chunks instead of direct-to-backend), `blueimp jQuery File Upload` (archived 2023-05-25 — see §18 replacement).

---

## §1. Security & HTTP-header hardening

Django 5.2 already ships HSTS (`SECURE_HSTS_*`), proxy-SSL (`SECURE_PROXY_SSL_HEADER`), `SECURE_REFERRER_POLICY`, `SECURE_CROSS_ORIGIN_OPENER_POLICY`, `SECURE_CONTENT_TYPE_NOSNIFF`, `XFrameOptionsMiddleware`, `CsrfViewMiddleware`, and (5.1+) `LoginRequiredMiddleware`. `python manage.py check --deploy` catches W001–W025 covering most of these. The libraries below fill the gaps `check --deploy` cannot see: CSP, Permissions-Policy, rate limiting, brute-force lockout, CORS, honeypots, idle-session timeout.

| Library | Decision | Note |
|---|---|---|
| django-axes 8.3.1 | **Adopt** | Brute-force lockout on `django.contrib.auth` signals. Integrates with allauth (pick one as source-of-truth for login throttling — see allauth's `ACCOUNT_RATE_LIMITS`). |
| django-csp 4.0 | **Adopt** | Plan a report-only rollout first. With the React pivot the inline-script story is cleaner (Vite bundles eliminate most ad-hoc `<script>` tags); the `<style>` story still needs nonces or hashed allow-listing for any third-party React component that injects styles at runtime (Mantine's CSS-in-JS, code-highlight libraries). |
| django-permissions-policy 4.30.0 | **Adopt** | Deny-all-features dict; one middleware. |
| django-cors-headers 4.9.0 | **Adopt** | Allow-list DRF API origins. |
| django-ratelimit 4.1.0 | **Adopt for specific scope** | Auth endpoints, public forms, expensive read paths. |
| django-honeypot 1.3.0 | **Adopt for specific scope** | Unauthenticated forms only. |
| django-session-security 2.6.8 | **Adopt for specific scope** | Admin (stock Django admin uses Django's bundled jQuery) + any server-rendered Django page that should idle-timeout. For React-island pages, port the idle-timeout to client-side via a Mantine modal + `@mantine/hooks` idle detector — django-session-security's bundled JS is jQuery-specific and won't run inside React islands. |
| django-defender | **Skip** | Redis-backed alternative to axes; pulls Redis we don't otherwise need. |
| django-stronghold | **Skip — abandoned** | Django 5.1 `LoginRequiredMiddleware` replaces it. |
| django-referrer-policy | **Skip — Django built-in** | `SECURE_REFERRER_POLICY = "same-origin"` already default since Django 3.1. |
| django-secure | **Skip — Django built-in** | Was merged into Django 1.8 core; PyPI page says so explicitly. |
| django-feature-policy | **Skip — renamed** | Empty shim; depend on `django-permissions-policy`. |

CSP nonces interact with the React-island stack. Audit every Django template's `<script>` and `<style>` block before promoting CSP from report-only to enforce. Vite chunks load via `<script type="module" src="...">` so they're nonce-friendly out of the box if the django-vite tag emits the nonce. Mantine's CSS-in-JS injects runtime `<style>` tags — those need either `style-src 'nonce-…'` or Mantine's static CSS extraction (if available in the chosen version). Stock Django admin pages still use Django's bundled jQuery and Bootstrap-ish styling and are fine. Wire `manage.py check --deploy` into CI — it's free signal.

If `django-allauth` enforces `ACCOUNT_RATE_LIMITS` on the login path, **don't** double-count by also applying `django-ratelimit` to the same view — pick one source of truth.

Sources: [django-axes](https://pypi.org/project/django-axes/), [django-csp](https://pypi.org/project/django-csp/), [django-permissions-policy](https://pypi.org/project/django-permissions-policy/), [django-cors-headers](https://pypi.org/project/django-cors-headers/), [django-ratelimit](https://pypi.org/project/django-ratelimit/), [django-honeypot](https://pypi.org/project/django-honeypot/), [django-session-security](https://pypi.org/project/django-session-security/), [Django 5.2 SecurityMiddleware](https://docs.djangoproject.com/en/5.2/ref/middleware/#django.middleware.security.SecurityMiddleware), [`check --deploy`](https://docs.djangoproject.com/en/5.2/ref/checks/#security), [`LoginRequiredMiddleware`](https://docs.djangoproject.com/en/5.2/ref/middleware/#django.contrib.auth.middleware.LoginRequiredMiddleware), [django-feature-policy → permissions-policy](https://adamj.eu/tech/2021/04/13/django-feature-policy-is-now-django-permissions-policy/).

---

## §2. Auth / SSO / MFA / passwords

The team already picked **django-allauth** for M1. Allauth ships `allauth.mfa` (TOTP + recovery codes + WebAuthn + passkeys) since 0.56.0, plus `[saml]` and `[socialaccount]` extras. That covers most of the identity surface.

| Library | Decision | Note |
|---|---|---|
| django-allauth 65.17.0 | **Adopt (already decided)** | Use `[mfa]` extra; require MFA for admin/instructor accounts. |
| argon2-cffi 25.1.0 | **Adopt** | Install via `django[argon2]`. Promote `Argon2PasswordHasher` to first position in `PASSWORD_HASHERS`. Keep PBKDF2/BCrypt/Scrypt as fallback verifiers for legacy hashes (none in M0, but the discipline matters). |
| django-otp 1.7.0 | **Skip — overlaps allauth.mfa** | Lower-level substrate. |
| django-two-factor-auth 1.18.1 | **Skip — overlaps allauth.mfa** | Higher-level UI over django-otp. |
| django-mfa2 3.2.0 / django-mfa3 1.1.0 | **Skip — overlaps allauth.mfa** | Same reasoning. |
| djangosaml2 1.12.0 | **Adopt for specific scope (M3+ SSO)** | RIT runs Shibboleth/SAML; this lib's wrapping of `pysaml2` handles Shibboleth metadata edge cases better than the allauth SAML extra in our experience. Trade-off worth re-evaluating closer to M3. |
| mozilla-django-oidc 5.0.2 | **Adopt for specific scope (M3+ SSO)** | OIDC RP. MPL-2.0 weak copyleft is safe at dependency boundary. Subclass `OIDCAuthenticationBackend` for claim-to-role mapping. |
| django-cas-ng 5.1.1 | **Defer** | RIT isn't CAS-shaped; revisit only if a tenant requires it. |
| django-saml2-auth | **Skip — abandoned** | Last release 2019. |
| django-keycloak (Slump) | **Skip — abandoned** | Use mozilla-django-oidc against Keycloak instead. |

Sources: [django-allauth](https://pypi.org/project/django-allauth/), [allauth MFA docs](https://docs.allauth.org/en/latest/mfa/introduction.html), [Django password hashers](https://docs.djangoproject.com/en/5.2/topics/auth/passwords/), [djangosaml2](https://pypi.org/project/djangosaml2/), [mozilla-django-oidc](https://pypi.org/project/mozilla-django-oidc/).

---

## §3. Secrets & encryption

PRD: provider credentials encrypted at rest, secret reads audit-logged, a `SecretBackend` Protocol with a dev-only filesystem backend in M0, real backends as plugins later.

**Recommendation: do not adopt any of the Django field-encryption libraries. Encrypt at the backend boundary, not the column boundary.**

| Library | Decision | Note |
|---|---|---|
| `cryptography.fernet.Fernet` / `MultiFernet` (stdlib of `cryptography`) | **Adopt** | Back the dev filesystem backend with `MultiFernet`; per-key rotation handled. Master key supplied via env-var. |
| django-fernet-encrypted-fields 0.4.0 (Jazzband) | **Adopt for narrow scope** | Only viable maintained encrypted-field package targeting Django 5.2. Useful if/when a column genuinely needs encryption *outside* the SecretBackend abstraction. M0 doesn't need it. |
| django-cryptography 1.1 / -django5 2.2 / django-fernet-fields / django-encrypted-model-fields / django-pgcrypto-fields / django-mirage-field | **Skip — stale** | None test on Django 5.2; most last shipped 2019–2022. |
| django-pgcrypto 3.1.0 | **Defer** | PG-only `WHERE`-able encrypted fields; binds you to Postgres-level key handling that conflicts with the SecretBackend abstraction. |
| hvac 2.4.0 (Vault) | **Defer (Scale-profile plugin)** | Nomad already integrates with Vault natively. When a Scale deployment needs Vault, this is the right SDK. |
| boto3 / azure-keyvault-secrets / google-cloud-secret-manager | **Defer (per-tenant plugin)** | Land as Secret Backend plugins only when a deployment requires them. |
| pydantic-settings 2.14.1 | **Adopt** | Typed config layer for non-Django settings (worker config, plugin config). Also has built-in adapters for AWS/Azure/GCP secret managers — useful for prototyping cloud KMS backends. |
| django-environ 0.13.0 | **Adopt for specific scope** | `settings.py` env parsing. Composes with pydantic-settings — django-environ is the parser, pydantic-settings is the typed validator. |
| python-decouple | **Skip** | Functional but covered by the pair above. |

**Why no field-wrapper:** RackLab's encryption boundary is the *backend*, not the *column*. The plugin Vault/KMS backends will not use Fernet at all — they delegate. Storing ciphertext in a plain `BinaryField` keeps the M0 dev filesystem backend trivial and lets plugin backends bring whatever primitive they want (Vault transit, AWS KMS Decrypt, etc.).

Sources: [cryptography Fernet](https://cryptography.io/en/latest/fernet/), [django-fernet-encrypted-fields](https://pypi.org/project/django-fernet-encrypted-fields/), [pydantic-settings](https://pypi.org/project/pydantic-settings/), [django-environ](https://pypi.org/project/django-environ/), [hvac](https://pypi.org/project/hvac/).

---

## §4. API tokens & DRF extras

PRD §6 currently mandates "strong signed JWTs" with `jti` claims throughout. **This survey recommends amending PRD §6 to permit opaque server-stored Personal Access Tokens (PATs) for the long-lived agent track**, with signed JWTs reserved for browser / console / session / share-link tokens. Rationale: opaque tokens revoke cleanly without JWT-blacklist propagation latency, never expose scope claims to client-side inspection, and Knox's hashed-at-rest storage maps naturally to PRD §6's "audit on creation/use/denial/revocation" requirement.

**Two-track token surface (PRD amendment required):**

| Track | Library | Why |
|---|---|---|
| Short-lived JWTs (minutes): browser session, console grant, share link | **djangorestframework-simplejwt** 5.5.1 with **RS256** | Public key can be exposed to the noVNC websockify proxy / SSH-plugin sidecar without sharing the signing key. Use simplejwt's blacklist app + a RackLab-owned `IssuedToken` table for scope / IP / audit envelope. |
| Long-lived opaque PATs (days–months): agent tokens, CLI tokens, plugin webhooks | **django-rest-knox** 5.0.4 | Multi-token per user, hashed-at-rest (server-side; the user never re-sees the secret after issuance), server-side revoke without blacklist gymnastics. Extend with `scopes JSONField` + `allowed_cidrs ARRAY` + audit-emitting `TokenAuthentication.authenticate()` subclass. |
| Share-link primitive | **`django.core.signing.TimestampSigner`** | Stdlib of Django; `sign_object` + `unsign_object` with `max_age`. Revocation via server-side `RevokedShareLink` table keyed on a `jti`-equivalent embedded in the payload. **Skip `itsdangerous`** — Django's `signing` is the equivalent, integrates with `SECRET_KEY_FALLBACKS`. |

**Required PRD edits:**

- `docs/prd/06-auth-rbac-sharing-tokens.md` — "Tokens" section: split into "Signed JWTs (short-lived)" and "Opaque PATs (long-lived)" with the requirements above. Both retain `jti`-equivalent identifiers and audit-on-every-state-change. PATs are server-stored hashed (Knox's bcrypt-style scheme), JWTs are RS256-signed with the existing `SECRET_KEY_FALLBACKS` rotation.
- `docs/prd/07-api-openapi-sse.md` — API auth section: confirm both `Authorization: Bearer <jwt>` and `Authorization: Token <pat>` are valid auth headers; the dispatch logic picks based on prefix.

Reject:

- **djangorestframework-api-key** — keys aren't bound to a user; collides with the PRD's actor-bearing audit requirement.
- **djangorestframework-jwt** (jpadilla) — abandoned 2017.
- **django-oauth-toolkit / Authlib** — only relevant if RackLab becomes an OAuth/OIDC issuer for other RIT services. PRD doesn't ask for that.

DRF/OpenAPI extras:

| Library | Decision |
|---|---|
| drf-spectacular-sidecar 2026.5.1 | **Adopt** — self-hosted Swagger/Redoc; works behind CSP. |
| drf-standardized-errors 0.16.0 | **Adopt** — consistent error envelope. **Note:** the library says it does *not* implement strict RFC 7807; if PRD §7 mandates `application/problem+json` exactly, plan to fork or write a custom formatter. |
| drf-nested-routers 0.95.0 | **Adopt if URL shape demands it** — e.g. `/labs/{id}/deployments/{id}/events/`. |
| drf-writable-nested 0.7.2 | **Adopt if serializer shape demands it** — explicit endpoints are cleaner where possible. |
| drf-flex-fields | **Skip — last release 2023.** |
| djangorestframework-camel-case | **Skip — fights drf-spectacular schema names.** Standardize one casing in the API contract. |

Sources: [django-rest-knox](https://pypi.org/project/django-rest-knox/), [simplejwt](https://pypi.org/project/djangorestframework-simplejwt/), [Django signing](https://docs.djangoproject.com/en/5.2/topics/signing/), [drf-spectacular-sidecar](https://pypi.org/project/drf-spectacular-sidecar/), [drf-standardized-errors](https://pypi.org/project/drf-standardized-errors/), [drf-nested-routers](https://pypi.org/project/drf-nested-routers/).

---

## §5. RBAC / audit / quota

### 5.1 RBAC — keep custom

RackLab's M0 already ships nested permission packs, role presets, role bindings, a `sync_rbac_defaults` command, and a permission-snapshot test. **Keep this.** The external policy engines (OpenFGA / SpiceDB / Cerbos / OPA) are objectively better at multi-tenant Zanzibar-style ReBAC, but their cost is a stateful service plus per-check round-trip latency, and RackLab's blast radius is one institution with Proxmox boundaries enforcing isolation *below* the Django layer. With the **institution-level tenancy** added in §19, the custom RBAC gains a `tenant` scope dimension and remains the right shape.

| Engine | Decision |
|---|---|
| **django-guardian** 3.3.1 | **Skip — overlaps custom RBAC.** Guardian provides per-object permissions via DB rows (a join on every check) and a `UserObjectPermission` / `GroupObjectPermission` table model. RackLab's nested-pack RBAC already expresses per-object scoping through (a) role bindings scoped to `Course` / `Project` / future `Tenant`, and (b) the existing access predicate layer. Adopting Guardian would mean either ignoring its tables (no value) or layering its per-object rows on top of nested packs (two sources of truth for "can user X read deployment Y" — bad). The PRD-research mention of Guardian was the right call to investigate; the answer after investigation is reject. |
| pycasbin + django-authorization | **Skip — custom RBAC suffices.** Casbin's CONF DSL replaces tested Python with a less familiar surface. |
| oso / django-oso | **Skip — deprecated** (Jan 2024). |
| OpenFGA / SpiceDB / Cerbos / OPA | **Defer — revisit at M8+ if multi-tenant ReBAC emerges.** Document the trigger criteria so the deferral is auditable. |
| Permify | **Skip** — license conflict between PyPI (AGPL-3) and repo (Apache-2) |
| AuthzForce | **Skip** — XACML niche |
| django-tabular-permissions | **Skip — unmaintained** |
| django-rules 3.5 | **Adopt for narrow scope (computed predicates).** Use alongside the nested-pack RBAC for "computed" predicates that depend on object state ("is owner AND course is published") which don't fit cleanly in the role-binding table. Composes with the custom RBAC; doesn't replace it. |

**One enhancement worth adding to the custom RBAC:** emit a `permission.denied` audit event on the deny path so the audit trail captures attempted-but-rejected access. Document the criteria that would trigger a switch to SpiceDB/OpenFGA: multi-org SaaS pivot, >10k users, security review demands policy-as-code separated from feature code.

### 5.2 Audit — keep custom + add three things

The custom `AuditEvent` emitter with documented-event catalog + CI emission test is the right *semantic* event log. The libraries below cover orthogonal use cases.

| Library | Decision |
|---|---|
| django-simple-history 3.11.0 | **Adopt for narrow scope (optional)** — admin-edited config models only (Cluster, Template registration, RBAC role bindings). Not runtime-churny models like `Instance.state`. Django Commons maintenance is reassuring for a long-lived control plane. |
| django-auditlog 3.4.1 | **Skip — overlaps simple-history without distinguishing wins.** |
| django-reversion 6.2.0 | **Defer — version-controlled model state is over-spec.** |
| django-easy-audit 1.3.8 | **Skip — GPL-3.0** is awkward for a plugin host. |
| django-pghistory 3.9.2 | **Defer** — PG-trigger-level capture (catches non-ORM writes) is genuinely valuable but binds audit to Postgres DDL. Revisit if a future incident shows non-ORM writes happening. |
| eventsourcing 9.5 + eventsourcing-django | **Skip — architecture mismatch.** Event-sourcing rebuilds models as event projections; RackLab is state-first. |
| structlog 25.5.0 | **Adopt** — mirror every `AuditEvent` to stdout JSON so SIEM ingestion has a path independent of the DB write. |
| python-json-logger 4.1.0 | **Skip if structlog adopted** — overlapping scope. |
| python-logstash 0.4.8 | **Skip — stale.** Use `python-logstash-async` if Logstash is the target. |
| fluent-logger-python | **Defer** — only if Fluent Bit is the chosen shipper. |

Two more pieces close the gaps:

- **Transactional outbox to NATS — build custom.** Risk: `AuditEvent.objects.create(...)` succeeds in a transaction; NATS publish happens after commit and crashes; the event never reaches SIEM. The available Django outbox libraries don't fit cleanly: `django-outbox-pattern` is STOMP-centered and would need a NATS-publisher fork; `jaiminho` (Loadsmart) is a broker-agnostic *function-call replay* tool, not a STOMP-first library as v1 of this survey said — different design philosophy, neither ships a native NATS / JetStream publisher. The right answer is **build custom**: a `~150-line` Postgres outbox table + a `nats-py`-driven relay worker. The pattern is small enough that adopting a library would mean fighting its abstractions; cite the pattern source (Microservices.io / Chris Richardson) in the inline docs.
- **Tamper-evident hash chain.** No mature Python library exists; the pattern is small. Add `prev_hash` and `hash` columns to `AuditEvent`, compute `hash = sha256(prev_hash || canonical_json(event))` on insert via `pre_save`, ship a `manage.py verify_audit_chain` command. Genuinely valuable for incident response — if an attacker tampers with an audit row, chain verification catches it.

### 5.3 Quotas — build custom

No Django library covers "self-service educational lab compute quotas." The closest libs (`django-quotas`, `django-limits`, `django-plans`) are either abandoned, count-based instead of resource-based, or oriented at SaaS subscription tiers. `django-ratelimit` / `limits` are request rate-limit only. The real reference designs live outside Python: **OpenStack Nova/Cinder** and **Kubernetes ResourceQuota/LimitRange**.

Recommended architecture for M6 (~800–1500 lines of custom Django):

1. **Model quota dimensions explicitly:** `vcpus`, `memory_mib`, `disk_gib`, `concurrent_deployments`, `cpu_hours_per_week`. Each is a row in a `QuotaPolicy` table scoped to (user, lab, course) tuples with precedence rules.
2. **Three numbers per (scope, dimension):** `limit`, `reserved` (pending allocations not yet committed), `in_use` (currently consumed). Allocation = `reserved + in_use ≤ limit`. This is OpenStack's quota triangle — it survives crashed creates and double-frees in ways "decrement on create, increment on delete" doesn't.
3. **Postgres advisory locks for check-and-reserve.** `SELECT pg_advisory_xact_lock(hash(scope || dimension))` at transaction start. Serialised check-and-reserve across worker processes without table-level locking.
4. **Enforcement at the API boundary,** in DRF view / serializer pre-validation — mirrors K8s admission. Not in `pre_save` signals: by then the work that needs unwinding has happened.
5. **Skip django-hordak** for now. Double-entry on "CPU-hour balance" is tempting but introduces accounting vocabulary (journals, accounts, posting) into a codebase about VMs. A `QuotaTransaction` table (timestamp, scope, dimension, delta, reason, reference_id) gets the audit benefit without the framework. Defer to M10+ if billing arrives.
6. **Schedule/placement is a separate subsystem.** Quotas answer "can this user create another VM?"; placement answers "which Proxmox node has room?" PRD lists both in M6 — don't conflate them in a single library search.

Sources: [django-quotas (abandoned)](https://github.com/mpasternacki/django-quotas), [django-plans](https://pypi.org/project/django-plans/), [limits](https://pypi.org/project/limits/), [django-hordak](https://github.com/adamcharnock/django-hordak), [django-simple-history](https://pypi.org/project/django-simple-history/), [structlog](https://pypi.org/project/structlog/), [django-outbox-pattern](https://github.com/juntossomosmais/django-outbox-pattern), [hash-chain walkthrough](https://dev.to/veritaschain/building-a-tamper-evident-audit-log-with-sha-256-hash-chains-zero-dependencies-h0b).

---

## §6. Storage & artifacts

PRD: filesystem backend is the dev default; real backends are plugins. M0 ships the `Artifact` + `ArtifactReference` models; the backend Protocol + filesystem implementation is the next slice.

**Do not wrap django-storages in M0.** Reasons:

1. django-storages is a `FileField`-shaped abstraction (`_open`/`_save`/`url`/`exists`/...). RackLab's artifact Protocol is artifact-shaped (content-addressed key, opaque URI back, retention tag). Coupling the plugin interface to Django storage internals forces every plugin author to read Django storage docs, not RackLab's.
2. django-storages *is* the right tool for plugin backends. When `racklab-artifacts-s3` ships, that plugin should depend on django-storages and present a thin `ArtifactBackend` → `S3Boto3Storage` adapter — that removes ~500 lines of boto3 plumbing and gets SigV4, multipart, presigned URLs, retries, and Azure/GCS for free.

| Library | Decision |
|---|---|
| django-storages 1.14.6 | **Adopt for specific scope (plugin backends only)** — S3 / GCS / Azure / SFTP / Backblaze plugins. |
| boto3 | **Adopt for specific scope (escape hatch)** — presigned upload URLs and other things django-storages can't express cleanly. |
| django-cleanup | **Skip / Defer** — RackLab's artifact lifecycle is the retention sweep `ReconcilerTask`, not Django ORM signals. |
| django-private-storage 3.1.3 | **Adopt for specific scope** — when an instructor-uploaded answer key / packet capture needs access-controlled serving. |

Sources: [django-storages](https://pypi.org/project/django-storages/), [django-private-storage](https://pypi.org/project/django-private-storage/).

---

## §7. Scheduled / background tasks

RackLab already owns execution: typed `PluginWorkerRuntime` + `WorkerRuntime` Protocols on NATS. The open question is the **scheduler tier** — what fires cron-style triggers (retention sweep, drift reconciliation, token cleanup).

**Recommendation: orchestrator-level cron (`systemd` timers in dev / Baseline, Nomad periodic in Scale). Each scheduled job is a `manage.py reconcile_*` command that publishes one NATS message and exits.** Zero new Python dependencies. Schedules live in the same IaC / unit files that already deploy the app, so cron config is reviewed and versioned alongside the deployment. Operational story is whatever you already use.

A point readers sometimes miss: **Celery's official broker list does not include NATS** (Redis, RabbitMQ, SQS, plus contributed backends). Adopting Celery for the scheduler tier therefore means standing up Redis or RabbitMQ alongside NATS — two message brokers in the same control plane — *and* writing a Celery↔NATS bridge for any cross-broker dispatch. The "skip Celery" call below is partly about scope (NATS workers cover execution) but also about not introducing a second broker just to get a cron scheduler.

| Library | Decision |
|---|---|
| Celery + django-celery-beat + django-celery-results | **Skip — covered by NATS workers + Celery has no NATS broker** | Brings Redis/RabbitMQ for a benefit (Beat scheduler) that orchestrator-level cron also provides, plus a second message broker that doesn't talk to NATS. |
| Dramatiq + django-dramatiq | **Skip** | Same reasoning. |
| django-q2 1.10.0 | **Skip** | Same. |
| Huey + django-huey | **Skip** | Same. |
| APScheduler 3.11.2 | **Defer — upgrade path** | If RackLab ever wants *dynamic admin-editable schedules*, a single `racklab-scheduler` service embedding APScheduler with in-memory jobstore (schedules from code) is the cleanest evolution. Switch to APScheduler's Django jobstore later without rewriting callers. |
| django-apscheduler 0.7.0 | **Skip** | No Django 5.2 in tested versions; single-scheduler constraint is a deployment footgun. |
| django-cron, arq, schedule, procrastinate, rq, django-rq | **Skip** | Either abandoned, in maintenance-only mode, or covered by NATS workers. |

Sources: [Celery](https://pypi.org/project/celery/), [APScheduler](https://pypi.org/project/APScheduler/), [django-q2](https://pypi.org/project/django-q2/).

---

## §8. Forms, tables, filters, admin UX

| Library | Decision | Note |
|---|---|---|
| django-crispy-forms 2.6 + crispy-bootstrap5 2026.3 | **Adopt for Django-rendered forms only** | Layout DSL for the Django-rendered chrome (login page, error pages, plugin-shipped server-side forms). Client-side validation on those server-rendered forms still uses HTML5 validation; the React islands don't use crispy — Mantine forms render their own markup. If a future decision drops server-rendered forms entirely (every form is a React island), this can be dropped. |
| django-widget-tweaks 1.5.1 | **Adopt** | Ad-hoc template attribute tweaks where a full layout is overkill. |
| django-floppyforms | **Skip — stale (2020).** |
| django-tables2 3.0.0 | **Skip — superseded by React pivot** | Pre-pivot, this was the server-rendered-tables choice. Post-pivot, every interactive list view is a React island built on TanStack Table 8 + Mantine `<Table>` markup (see §20.4). Keep this row in the survey for historical clarity; treat as not-adopted. |
| django-filter 25.2 | **Adopt** | DRF `filter_backends`; plain-Django filterset support too. |
| django-unfold 0.94.0 | **Skip — disqualified by binding constraints.** | Modern admin theme. MIT, Django 5.2 + 6.0. **Bundles Tailwind + Alpine + HTMX inside the admin templates.** The binding-constraints section disqualifies all three. Re-evaluate only if a future Unfold release drops the HTMX/Alpine/Tailwind bundle and switches to Bootstrap+jQuery. |
| **Stock Django admin** | **Adopt (default)** | Django's built-in admin is jQuery-based (Django ships its own jQuery for admin), has gettext i18n out of the box, is ARIA-compliant by default, and is the operator UI through M9 with M10 introducing the custom UI shell. Accept the dated look as the cost of constraint compliance; the operators using it are RIT ops + instructors, not student-facing UX. |
| django-jazzmin 3.0.4 | **Skip — Bootstrap 4 mismatch.** AdminLTE 3 base means Bootstrap 4, not Bootstrap 5; mixing major Bootstrap versions across operator and user-facing UI is more complexity than it saves. |
| django-grappelli 5.0.0 / 4.0.4 | **Skip — dated UX.** jQuery-clean but visually stuck in ~2015; not worth the integration cost over stock admin. |
| django-import-export 4.4.1 | **Adopt** | Catalog/template/roster CSV/XLSX seeding via `ImportExportModelAdmin`. |
| django-jazzmin / django-grappelli / django-baton / django-admin-interface | **Skip** | All overlap django-unfold without distinguishing wins. |
| django-jet-reboot | **Skip** | AGPL-3 + thin maintenance. |
| django-bootstrap5 (Zostera) | **Skip — overlaps crispy-bootstrap5.** Pick one rendering paradigm. |
| django-formtools 2.6.1 | **Adopt for specific scope (when wizard arrives).** |
| django-jsonform 2.23.2 | **Adopt for specific scope (M8)** | JSON-schema-driven admin widget — pairs with pydantic schemas. |

Sources: [django-crispy-forms](https://pypi.org/project/django-crispy-forms/), [crispy-bootstrap5](https://pypi.org/project/crispy-bootstrap5/), [django-tables2](https://pypi.org/project/django-tables2/), [django-filter](https://pypi.org/project/django-filter/), [django-unfold](https://pypi.org/project/django-unfold/), [django-import-export](https://pypi.org/project/django-import-export/), [django-jsonform](https://github.com/bhch/django-jsonform).

---

## §9. Markdown rendering & sanitization

TipTap (frontend) stores Markdown source. The server needs to render that Markdown to HTML for emails, exports, RSS, search snippets, and any view where Prism.js isn't loaded.

| Library | Decision | Note |
|---|---|---|
| markdown-it-py 4.2.0 | **Adopt** | Matches TipTap's JS `markdown-it` semantics — server-rendered HTML lines up with the editor preview. |
| nh3 0.3.5 | **Adopt** | Mozilla `ammonia` Rust bindings via PyO3. **The bleach successor.** ~20× faster, same allow-list API. |
| Pygments 2.20.0 | **Adopt** | Server-side syntax highlighting for code blocks in emails/exports where Prism.js isn't loaded. |
| Python-Markdown 3.10.2 | **Skip — markdown-it-py preferred** | Pick if you specifically want the Python-Markdown extension ecosystem (attr_list, toc, footnotes). |
| mistune 3.2.1 | **Skip — overlaps markdown-it-py.** |
| commonmark-py | **Skip — stale (2019).** |
| bleach 6.3.0 | **Skip — deprecated** since 2023-01-23 per maintainer notice. |

Sources: [markdown-it-py](https://pypi.org/project/markdown-it-py/), [nh3](https://pypi.org/project/nh3/), [Pygments](https://pypi.org/project/Pygments/).

---

## §10. Asset pipeline & static files

Traefik fronts the app for TLS termination and edge gzip/brotli. Static files still need Django-aware serving for `ManifestStaticFilesStorage` cache busting and immutable headers.

| Library | Decision | Note |
|---|---|---|
| whitenoise 6.12.0 | **Adopt** | Manifest storage + `max-age=immutable` for hashed paths. Traefik caches the right headers. |
| django-compressor 4.6.0 | **Adopt** | `{% compress %}` blocks for inline asset bundling. |
| django-sass-processor 1.4.2 | **Skip — React pivot drops Bootstrap-as-CSS** | Was scoped to Bootstrap SCSS variable customization for the (now-replaced) jQuery UI. Mantine handles its own styling; Vite handles its own asset pipeline. Drop unless server-rendered Django chrome ends up needing SCSS. |
| django-libsass | **Skip — stale (2021).** |
| django-tailwind | **Skip — not in stack.** (`django-vite` 3.1.0 is **Adopt** per §20.1 — was on this skip line in an earlier draft.) |

Sources: [whitenoise](https://pypi.org/project/whitenoise/), [django-compressor](https://pypi.org/project/django-compressor/), [django-sass-processor](https://github.com/jrief/django-sass-processor).

---

## §11. Observability — metrics, traces, errors

PRD M13b graduates Prometheus + Grafana to first-class. The libs below cover the Django-side instrumentation across all milestones.

| Library | Decision |
|---|---|
| django-prometheus 2.4.1 | **Adopt** — middleware + DB instrumentation; the most-used metrics integration for Django. |
| sentry-sdk 2.60.0 | **Adopt** — error capture + lightweight transaction tracing. Standard Django + DRF + Channels integration. |
| opentelemetry-instrumentation-django (beta) | **Adopt for tracing scope (M13b)** — only if cross-service spans (Django ↔ Proxmox ↔ NATS) become a real need. Overkill for M1–M2. |
| django-statsd-mozilla | **Skip — abandoned (2017).** |

Health checks (separate from metrics):

| Library | Decision |
|---|---|
| django-health-check 4.4.1 | **Adopt** — pluggable backends (DB, cache, storage, etc.); register custom NATS and Proxmox checks for M2.5. |
| django-watchman / django-alive | **Skip — covered by django-health-check.** |

Sources: [django-prometheus](https://pypi.org/project/django-prometheus/), [sentry-sdk](https://pypi.org/project/sentry-sdk/), [django-health-check](https://github.com/revsys/django-health-check).

---

## §12. Metrics graphing — deployment perf + Proxmox graphs

The user-visible question: how do RackLab pages show "this lab's CPU over the last hour" and "this template's success rate over the last week"? PRD M13b owns operator-facing Grafana; this section is about **in-product** graphs.

**Two-tier architecture:**

**Storage — spike before committing.** Codex (2026-05-25) flagged the original "TimescaleDB extension on the existing Postgres" recommendation as premature. Loading a native PG extension couples the production DB image, upgrade discipline, backup/restore, and HA failover to Timescale forever. For the *initial* M2 dataset (deployment durations, success/failure counters, queue depth samples) plain Postgres tables with **BRIN indexes on time columns** + **materialized view rollups** likely cover the read shapes RackLab needs. Decision deferred: spike Timescale-vs-plain-Postgres in M2 with the actual query shapes before pinning the storage tier. License note: TimescaleDB community edition is Apache-2.0; advanced features (compression, multi-node, continuous aggregates) are under the proprietary Timescale License (TSL) — if RackLab depends on TSL features, that changes the licensing posture for self-hosted Baseline operators. Document this explicitly in any future spec.

- **First spike (M2):** plain Postgres + BRIN + materialized rollups on a `deployment_event` table. If query latency on "deployment perf over last semester" is acceptable, stop there.
- **Second spike (if needed):** TimescaleDB community edition; verify Apache-2.0 surface covers RackLab's needs without TSL-only features.
- **Prometheus** (M13b) for ops-facing metrics + **VictoriaMetrics** v1.144.0 as a remote-write target if >15-day retention is wanted. Not needed in M0–M2.
- Skip InfluxDB v2/v3 — would force a second query language into Django.
- Skip Thanos/Cortex/Mimir — overkill for educational-lab scale.

**Ingest:**

- **RackLab writes its own metrics directly into a plain Postgres `deployment_event` table** via Django ORM/psycopg at the same moment it emits the lifecycle event. No exporter to misalign. BRIN indexes on the `time` column + per-day materialized rollups for the "by template" and "by tenant" cuts.
- **Proxmox RRD endpoints** (`/api2/json/nodes/{node}/qemu/{vmid}/rrddata`) via `proxmoxer` for on-demand per-VM graphs in lab detail pages. `timeframe` enum: hour / day / week / month / year. Resolution is 1-min for hour, 30-min for day, 3-hour for week. Cache in Django for ~30s.
- **prometheus-pve-exporter** 3.9.0 scraped by RackLab Prometheus for operator dashboards (M13b). Per-VM CPU/mem/disk-IO/net/uptime; per-node load; per-storage capacity/usage; cluster status; backup/replication state.
- Do **not** stand up the Proxmox→InfluxDB push as a third path — it duplicates the exporter pipeline.

**Query:**

- Django ORM / raw SQL against the plain Postgres `deployment_event` table + materialized rollups for RackLab-domain graphs. Switch to Timescale only if a spike proves rollup-driven queries can't meet latency targets.
- `proxmoxer` for live per-VM RRD pulls (cached).
- `prometheus-api-client` 0.7.2 (`PrometheusConnect.custom_query_range()`) for any in-product view that wants a PromQL query. Probably none through M2.

**Render:**

- **Chart.js 4.5.1 + `chartjs-adapter-date-fns` 3.0.0**, mounted in React via **`react-chartjs-2` 5.3.1** (the official React wrapper). Wrap once in a RackLab `<AccessibleChart>` HOC that adds `aria-label` on the `<canvas>` element + an offscreen `<table>` summary so screen readers get a tabular fallback (WCAG 2.2 AA on `img`-equivalent content). Plain DRF endpoints return Chart.js-shaped JSON. **Skip `django-chartjs`** — classifiers cap at Django 3.0; it's 20 lines of plain code instead.
- **uPlot 1.6.32** held in reserve behind a feature flag for the one or two views (long-window deployment-perf history at high resolution) where Chart.js's canvas rendering struggles.
- **Grafana iframes** *only* on `/admin/observability/*` views — never user-facing. Auth via short-lived viewer token injected by Traefik / nginx reverse-proxying the iframe URL.

**Real-time updates:** default to `fetch()` polling every 5–15s; promote to a Channels WebSocket consumer only for the deployment-lifecycle view (which already has a consumer for state transitions). Proxmox itself doesn't expose data faster than 1-minute resolution, so sub-5s push is pointless.

**Minimum viable for M2 that doesn't paint M13b into a corner:** ship a plain Postgres `deployment_event` table with BRIN indexes on the time column and a materialized rollup for "deployments per template per day"; ship a thin `proxmoxer`-backed Django view that proxies RRD data with 30s cache and returns Chart.js JSON. That's enough to graph deployment perf and per-VM CPU without committing to a Postgres extension. Re-evaluate Timescale if/when query latency on the rollups becomes the bottleneck — at which point a Timescale spike has a real target metric to beat. When M13b lands, Prometheus + prometheus-pve-exporter + Grafana go in cleanly; the in-product graphs keep working off Postgres and Proxmox RRD untouched.

Sources: [TimescaleDB](https://github.com/timescale/timescaledb), [Prometheus storage docs](https://prometheus.io/docs/prometheus/latest/storage/), [VictoriaMetrics](https://victoriametrics.com/), [prometheus-pve-exporter](https://github.com/prometheus-pve/prometheus-pve-exporter), [Proxmox External Metric Server](https://pve.proxmox.com/wiki/External_Metric_Server), [Proxmox RRD timeframe enum forum thread](https://forum.proxmox.com/threads/rrd-data-api-help.13454/), [Chart.js](https://www.chartjs.org/), [chartjs-adapter-date-fns](https://github.com/chartjs/chartjs-adapter-date-fns), [uPlot](https://github.com/leeoniya/uPlot), [prometheus-api-client](https://pypi.org/project/prometheus-api-client/), [Grafana embedding](https://grafana.com/blog/how-to-embed-grafana-dashboards-into-web-applications/).

---

## §13. LTI / education integration

RIT runs Brightspace (D2L) campus-wide with Canvas in some colleges. Both support LTI 1.3 + Advantage; LTI 1.1 is deprecated by 1EdTech.

| Library | Decision |
|---|---|
| django-lti 0.10.0 (academic-innovation) | **Adopt as primary (when LMS integration starts)** — explicitly targets Django 5.2 + 6.0, wraps PyLTI1p3 internally. |
| PyLTI1p3 2.0.0 | **Adopt (transitively)** — workhorse for LTI 1.3, NRPS, AGS, Deep Linking. Slowing release cadence but stable. |
| django-lti-toolbox (openfun) | **Skip — slowing.** |
| PyLTI | **Skip — abandoned + LTI 1.1 deprecated.** |
| Open edX OPL libs | **Skip — wrong side.** RackLab is the tool, not the LMS. |

**Architecture:** ship LTI as a `racklab-lms-lti` plugin via pluggy. The plugin maps an LTI launch + NRPS context → an existing RackLab user + course-scoped lab catalog. RackLab core stays usable without an LMS — essential for ops-led labs and non-credit workshops.

Sources: [django-lti](https://pypi.org/project/django-lti/), [PyLTI1p3](https://pypi.org/project/PyLTI1p3/).

---

## §14. ASGI server, caching, search

| Area | Recommendation |
|---|---|
| ASGI server (HTTP + DRF) | **`gunicorn + UvicornWorker`**. gunicorn 26.0 brings the supervisor + graceful reload + worker lifecycle; UvicornWorker brings uvicorn's loop. |
| ASGI server (WebSocket) | **daphne** 4.2.1 as a dedicated WS-only process. Channels' reference server, fewer protocol surprises. |
| Hypercorn | **Skip** unless HTTP/2 server push / HTTP/3 are needed — they're not, Traefik fronts. |
| Redis cache backend | **Use Django's built-in `django.core.cache.backends.redis.RedisCache`.** Adequate for v1 (rate-limit storage, ephemeral state). Adopt **django-redis** 6.0.0 later if you want compression, pattern-based deletes, or sentinel/replication helpers. |
| ORM caching | **Defer django-cachalot / django-cacheops** — invalidation footguns under raw SQL / Channels async paths outweigh the perf gain for a control-plane workload. |
| django-cache-machine | **Skip — abandoned.** |
| Search | **Use Django's built-in Postgres `SearchVector` + `SearchQuery` with GIN indexes.** Catalog corpus is small (hundreds of items). Skip django-watson, django-haystack, meilisearch-python until "instant search across thousands of objects" becomes real. |

Sources: [gunicorn](https://pypi.org/project/gunicorn/), [uvicorn](https://pypi.org/project/uvicorn/), [daphne](https://pypi.org/project/daphne/), [django-redis](https://pypi.org/project/django-redis/), [`django.contrib.postgres.search`](https://docs.djangoproject.com/en/5.2/ref/contrib/postgres/search/).

---

## §15. i18n / l10n extras

RackLab is a single-institution control plane with en-US users. Locale-flavour libraries are not needed.

| Library | Decision |
|---|---|
| django-rosetta 0.10.3 | **Adopt only when a non-English cohort lands** — gettext editor in the admin. |
| django-modeltranslation / django-parler | **Skip** — translate at the catalog level via plugin-shipped catalogs (PRD already says this). |
| django-localflavor / django-countries / django-phonenumber-field / django-money | **Skip** — no domain need (no addresses, no phones, no money). |
| Babel / PyICU | **Skip** — Django's plural-forms support is sufficient for the educational-lab corpus. |

---

## §16. Dev tooling & utilities

| Library | Decision |
|---|---|
| django-extensions 4.1 | **Adopt (dev)** — `shell_plus`, `runserver_plus`. |
| django-debug-toolbar 6.3.0 | **Adopt (dev)** — browser-side request inspector. |
| django-silk 5.5.0 | **Defer** — capture-and-replay profiling; only when there's a real perf complaint. |
| django-model-utils 5.0.0 | **Adopt** — `TimeStampedModel`, `FieldTracker`, `Choices`. Confirm Django 5.2 compat on latest tag before pinning. |
| django-autoslug 1.9.9 | **Adopt with caution** — LGPL-3 is fine at dependency boundary; or write a 6-line slug signal yourself. |
| django-ordered-model | **Defer** — only if a user-orderable list arises. |
| django-mptt 0.18.0 | **Skip — declared unmaintained on PyPI.** Use django-treebeard if hierarchical models are needed. |
| django-treebeard 5.1.0 | **Defer** — only if hierarchical lab nesting or doc-page hierarchies appear. |
| django-cte 3.0.0 | **Defer** — adopt when the first hierarchy-aware quota or placement query lands. |
| factory-boy 3.3.3 | **Adopt (already pinned).** |
| django-anymail 15.0 | **Adopt at the notification milestone.** Pluggable backends (SES, Mailgun, Postmark, SendGrid, Resend, Brevo, …) — good fit for multi-institution deployments. |

---

## §17. FSM, SSE, and "things RackLab is building custom"

### 17.1 FSM — evaluate django-fsm-2 against the existing `transition_job()`

Original draft recommended unconditional adoption. Codex (2026-05-25) flagged the migration risk: RackLab's current `transition_job()` already does `SELECT ... FOR UPDATE`, history validation, state write, and `AuditEvent` emission in a single transaction — atomic. `django-fsm-log` registers via a `post_transition` signal, which fires *after* the state write but inside the same transaction; translating its `StateLog` rows into RackLab's `AuditEvent` envelope after the fact has two concrete risks:

1. **Actor / correlation context loss.** The `post_transition` signal handler doesn't receive the request context. RackLab's `transition_job()` knows the actor, request ID, and correlation tags at call time; a signal handler has to fetch them from thread-locals or be passed them explicitly via custom transition kwargs (`django-fsm-log` supports this but it's not the default and it's brittle).
2. **`state_history` invariant.** RackLab's `Job` model carries a `state_history` field that's appended atomically with the transition. Replacing the existing atomic write with django-fsm's `@transition` + post-hook split risks transitions where the state changes but `state_history` doesn't (signal failure mid-transaction) — that breaks the invariant the audit-emission CI test enforces.

**Action:** before adopting `django-fsm-2` + `django-fsm-log` for the `Job` model, write a small spec (`docs/superpowers/specs/`) that proves:

- The actor/correlation context can be carried into the `post_transition` signal handler (likely via `Job.transition(actor=..., request_id=..., correlation=...)`).
- The `StateLog` write and `AuditEvent` write happen in the same transaction as the state write, with the same rollback semantics.
- The `state_history` invariant is preserved (probably by writing both `StateLog` and `state_history` from the signal handler, or by dropping `state_history` in favor of `StateLog` queries).

If the spec clears, **adopt `django-fsm-2` 4.2.4 + `django-fsm-log` 5.0.2** — they're the right libraries on the merits (`django-fsm` original was archived 2025-10-07; `-2` is the drop-in community fork, MIT, Django 4.2–6.0). If the spec hits walls, **keep the existing custom `transition_job()`** and document why the library route was rejected for posterity. The custom code passes its CI tests today; the library route's value is `TransitionNotAllowed` compile-time-ish enforcement, not raw functionality.

Skip: `xworkflows` / `finite-state-machine` (stale); `viewflow` full engine (too heavy); `transitions` (framework-agnostic, but django-fsm-2's `@transition` decorator is the better Django fit — *if* it composes with the audit-emission discipline).

### 17.2 SSE — spike `django-eventstream` first, fall back to Channels-custom

Original draft said skip — codex (2026-05-25) corrected this: `django-eventstream` does ship DB-backed reliable delivery and automatic repair after disconnect/crash. The `stream-reset` event is the *behind-retention* sentinel (client has fallen past the storage window), not proof replay is absent.

**Action:** spike `django-eventstream` 5.3.3 against PRD §7 SSE requirements before committing either way. Concrete questions to answer in the spike:

- Does the library's persistence layer preserve `Last-Event-ID` semantics across worker restarts with the at-least-once guarantee PRD §7 implies?
- Can RackLab's `Job` / deployment event model write into `django-eventstream`'s storage without contortions, or does it want its own event-log schema?
- How does it interact with Channels 4.2 routing — is it a Channels consumer or a separate ASGI view? PRD M2's SSE channel is already targeted at Channels.
- Auth: does it expose a clean hook for the per-stream RBAC scope check?

If the spike clears, **adopt django-eventstream**; if it fights the existing event model, build a Channels-custom async view (~150–200 lines: read `Last-Event-ID` header, query persisted events with `id > last_event_id`, replay, switch to live tail). Either way, this is an M2 decision, not M0.

**Skip** the original "no library implements it" claim.

### 17.3 Plugin system — stay on pluggy

`stevedore` (OpenStack) is manager-class-oriented; pluggy is hookspec-oriented and matches RackLab's extension-point model. No reason to switch.

### 17.4 Async ORM — Django 5.x native

Django 5.2's `a*` query methods (`aget`, `afilter`, `acreate`, `asave`, etc.) and async iteration are sufficient. Transactions still need `sync_to_async(thread_sensitive=True)`. Skip `django-async-orm` and similar — they predate Django 4.1's native async support.

Sources: [django-fsm-2](https://pypi.org/project/django-fsm-2/), [django-fsm-log](https://pypi.org/project/django-fsm-log/), [django-eventstream](https://pypi.org/project/django-eventstream/), [pluggy](https://pypi.org/project/pluggy/), [Django 5.2 async](https://docs.djangoproject.com/en/5.2/topics/async/).

---

## §18. File uploads — FilePond + chunked + direct-to-artifact-backend

PRD §15 historically listed `blueimp jQuery File Upload`; the prior codex review (2026-05-24) flagged that the upstream was archived 2023-05-25. This survey originally omitted the file-upload category — codex (2026-05-25) flagged that gap. RackLab's upload surfaces include user avatars (small), instructor course material (small-to-medium), **stack imports/exports** (medium, JSON/YAML/tar), and **ISO uploads** (multi-GB) for catalog template construction.

**Naive `FileField` + Django multipart will not work for ISOs / stack tarballs.** A 12 GB ISO uploaded via Django multipart blocks a worker for the entire upload duration and may exceed `DATA_UPLOAD_MAX_MEMORY_SIZE` / `FILE_UPLOAD_MAX_MEMORY_SIZE` / per-process memory caps. Django never handles those bytes in production.

### 18.1 Upload classes and protocols

| Upload class | Protocol | Implementation |
|---|---|---|
| Small files (avatars, small attachments, ≤ 5 MB) | Django multipart `request.FILES` | `FilePond` (via `react-filepond`) → `ImageField` / `FileField` on a small Django model. |
| Medium files (instructor PDFs, course material, stack JSON exports, ≤ 50 MB) | Django multipart with **chunked upload handler** (raises `MultiPartParser` chunk threshold) | `FilePond` → DRF view that uses `HttpRequest.read()` to stream chunks into the artifact backend in bounded reads (never `request.body` — that loads the entire payload into memory). |
| Large files (ISOs, OVAs, full disk images, ≥ 1 GB, frequently ≥ 10 GB) | **FilePond chunked upload protocol** (HEAD + PATCH with `Upload-Offset` and `Upload-Length` headers, tus-like) | See §18.3 below. Django never sees the full byte stream in one shot. |
| Stack imports (YAML / JSON catalog snapshots, small) | Standard multipart | `FilePond` + a stack-import DRF view that streams parse-and-validate. |
| Stack exports | Streaming response | `StreamingHttpResponse` from the artifact backend; no upload library. |

### 18.2 FilePond chunked upload protocol — the actual shape

FilePond's chunked-upload mode (enabled via `chunkUploads: true` on the FilePond instance, **not** the `FilePoster` / `FileMetadata` plugins which serve different purposes) implements a small REST flow:

1. `POST {server}/process` — client announces the upload; server returns a server-generated random transfer ID (`Upload-Id`).
2. `HEAD {server}/patch/{id}` — client asks "what offset are you at?"; server returns `Upload-Offset`.
3. `PATCH {server}/patch/{id}` with header `Upload-Offset: N`, `Upload-Length: total`, body = next chunk — client sends a single chunk; server validates offset matches, appends, returns the new offset.
4. Client repeats `PATCH` until offset == length.
5. `DELETE {server}/revert/{id}` — abort path.

**This is NOT the S3 multipart protocol.** S3 multipart uses `CreateMultipartUpload` → `UploadPart` (per part, returns `ETag`) → `CompleteMultipartUpload` (with all `ETag`s). The protocols are not wire-compatible. The single-PUT direct-to-S3 path caps at **5 GB** per object (S3 hard limit), which is below the stated 10 GB+ ISO/OVA case — direct-to-S3 PUT cannot be the upload path for large artifacts.

### 18.3 Architecture for large uploads (≥ 1 GB)

The Django app server is the **upload coordinator** even when the artifact backend is S3-compatible. FilePond uploads to Django; Django relays to the artifact backend in a manner appropriate to that backend.

- **Filesystem artifact backend (M0 / Baseline):** Django's PATCH handler streams the chunk to disk under a per-transfer temp path, validated by `Upload-Offset` (atomic offset lock per transfer ID via a `UploadSession` row + Postgres advisory lock — *not* `O_APPEND` racing). On finalization, atomically move temp → permanent artifact path; stamp the `Artifact` row with sha256 (computed during streaming).
- **S3-compatible artifact backend (M1+ plugin):** Django's PATCH handler initialises an S3 multipart upload on first chunk, calls `UploadPart` per FilePond chunk (chunk sizes need to align with S3's 5 MB minimum), and `CompleteMultipartUpload` on final chunk. Django still validates offsets and chunk-count invariants. Computing sha256 of the full payload is **only possible post-upload** for the S3 backend — issue a separate `GetObject` + hash, or trust the client-provided sha256 as a *preflight hint* and verify async before clearing quarantine.

### 18.4 Upload-session invariants (all paths)

The session model has to enforce these before any bytes touch storage:

- **Quota check before session creation.** No quota → no `UploadSession` row → no upload starts.
- **Server-generated random transfer IDs** (UUID4 / 128-bit). Never trust client-supplied IDs.
- **Offset locking + idempotent retry.** Each PATCH takes a Postgres advisory lock on `(transfer_id)`, verifies the incoming `Upload-Offset` matches the session's current offset (idempotent retry returns success without re-applying), then appends and commits the new offset atomically.
- **TTL cleanup.** Abandoned sessions (no PATCH activity for N hours) are reaped, including:
  - filesystem backend: delete temp files.
  - S3 backend: `AbortMultipartUpload` to release multipart parts (S3 bills for incomplete multiparts until aborted).
- **Atomic promote after scan.** Sessions complete into a `quarantined=true` artifact. Only after a scanner returns OK is `quarantined` cleared and the artifact made visible to other RackLab paths.
- **Filename + path sanitization.** Never trust client-supplied filenames; derive the storage key from `transfer_id`, store the original filename as metadata.
- **MIME magic sniffing.** Verify declared MIME against actual file magic via `python-magic` (libmagic bindings). Reject mismatches before scan. Especially important for "ISO" claims that are actually archives.
- **Archive / zip-bomb limits.** If the file is a stack tarball or zip, enforce extracted-size caps before processing.

### 18.5 Quarantine + integrity

- `Artifact.quarantined: bool` defaults to true. Until the scanner pipeline returns OK, the artifact is invisible to other RackLab paths and cannot be referenced by a `Deployment`.
- **M0** ships the flag + a no-op dev scanner (always OK) so the workflow is exercised end-to-end.
- **M1+** wires a real scanner plugin — recommend ClamAV via `clamd` for unknown blobs; `qemu-img info` + format validation for ISO/OVA claims; custom validators per-artifact-kind.
- **Hash flow:**
  - Filesystem backend: sha256 computed during streaming write, no separate read pass.
  - S3 backend: sha256 computed post-upload via a streaming `GetObject` (or trust the client-stated hash as preflight + verify async). Quarantine stays set until verification.
  - Optional: instructor pastes upstream sha256 / sha512 at session creation; RackLab refuses session completion if computed hash doesn't match.

### 18.6 Frontend

**FilePond** 4.x (pqina, MIT, active) mounted via **`react-filepond`** 7.1.3 (December 2024 — note: re-verified per codex, earlier "Sep 2020" was wrong; React 18/19 compat is current). Pluggable; supports drag-and-drop, image preview, file type validation, max-size, and chunked uploads via the **core `chunkUploads` option** (no extra plugin needed; codex correction). Pairs with `@mantine/dropzone` for surface chrome.

**Fallback if the wrapper breaks:** mount FilePond core directly in a React component via `useRef` + `useEffect`. No architectural change.

### 18.7 Backend libraries — skip the off-the-shelf assemblers

- **`django-chunked-upload`** — **Skip.** Last release 2022, Django ≤ 4.x classifiers; its model is "Django assembles chunks into a `FileField` row" which still buffers through the Django process and doesn't match FilePond's wire protocol.
- **`django-drf-filepond`** (PyPI 0.5.2) — third-party DRF integration with FilePond's chunked protocol. **Spike before adopting** — it solves the protocol-adapter problem if the wire format matches; verify license, maintenance, and that it handles the upload-session invariants in §18.4. If it's stale or wrong-shape, the protocol is small enough to hand-roll (~200 lines).
- **`django-storages`** — **Adopt for plugin backends** (S3 / GCS / Azure) once the artifact backend Protocol is in place. Don't pin in M0 — the filesystem backend doesn't need it.

### 18.8 Defer / skip

- **`tus.io` / `tusd` sidecar** — held in reserve as the upgrade path if FilePond chunked at multi-GB scale produces incidents. FilePond chunked is conceptually similar to tus (offset-based PATCH); upgrading later is mostly a wire-format change, not a protocol rethink.
- **`Uppy`** — Transloadit's MIT uploader; functionally equivalent to FilePond, team picked FilePond.
- **`django-filer`** — managed media library; overkill for the file-as-artifact model.
- **`django-resized`** — replace with direct Pillow calls if image resize is ever needed.

Sources: [FilePond server docs (chunked uploads)](https://pqina.nl/filepond/docs/api/server/#chunk-uploads), [Django request body / streaming uploads](https://docs.djangoproject.com/en/5.2/ref/request-response/#django.http.HttpRequest.body), [Django chunked upload handlers](https://docs.djangoproject.com/en/5.2/topics/http/file-uploads/#modifying-upload-handlers-on-the-fly), [AWS S3 single PUT 5 GB / multipart 5 TB limits](https://docs.aws.amazon.com/AmazonS3/latest/userguide/qfacts.html), [AWS S3 multipart upload overview](https://docs.aws.amazon.com/AmazonS3/latest/userguide/mpuoverview.html), [django-drf-filepond](https://pypi.org/project/django-drf-filepond/), [tus.io protocol](https://tus.io/) (held in reserve).

---

## §19. Multi-tenancy — Institution-above-Course, soft isolation, cross-tenant sharing

**Decision (this session, 2026-05-25):** Adopt institution-level tenancy from M0 with soft RBAC-enforced isolation and explicit support for cross-tenant shared resources.

**Why now:** M0 is mostly models. Adding a `Tenant` FK to root tables now is ~1 day of work; retrofitting after M3 (provider credentials), M5 (networking), M6 (quotas), M7–M9 (scripts / SSH / docs) roughly triples that cost. Tenancy is "free" while the schema is still soft.

### 19.1 Tenant shape

| Layer | Detail |
|---|---|
| Top-level tenant | New `Tenant` (or `Institution`) model — RIT is the default tenant; partner schools or RIT departments running their own catalogs are separate tenants. |
| Tenant FK lives on | `Course`, `Project`, `Catalog`, `CatalogTemplate`, `ProviderEndpoint`, `ProviderCredentials`, `NetworkOffering`, `QuotaPolicy`, `RoleBinding`, plugin-shipped tenant-scoped models. |
| User membership | Existing user-membership model gains a tenant-scope dimension: a user can belong to multiple tenants with different roles. Tenant switcher in the UI for multi-tenant users. |
| Denormalized tenant on hot tables | `Job`, `AuditEvent`, `Artifact`, `Deployment`, `Reservation` carry an **immutable denormalized `tenant_id` column** set at creation time from the scoping context — they're not joined-through-FKs at query time. Reasons: (1) audit queries must remain correct even if the scoping row (Course / Catalog) is deleted, (2) retention sweeps and quarantine flows partition cleanly on `tenant_id`, (3) cross-tenant queries (`tenant.cross_access` event correlation, shared-resource consumer accounting) need an indexable column, not a join chain. Setting it once on insert and never updating preserves the invariant. |
| Plugin scoping | Plugins are system-installed (PRD §13 unchanged), but plugin-shipped Django apps can declare tenant-scoped models. Plugin manifests opt into tenancy. |

### 19.2 Isolation guarantees — soft, RBAC-enforced

Picked over hard schema-per-tenant isolation for these reasons:

- **One DB image, one migration graph, one backup.** Operationally simpler at single-cluster scale.
- **Cross-tenant queries are useful** for the cross-tenant sharing model in §19.3 below.
- **Tenants aren't adversarial.** Institution-above-course tenancy is a layer for RIT to host partner schools or to separate departments — not for hosting competing institutions on shared infrastructure. If that day comes, schema-per-tenant becomes the right answer; until then, soft isolation is correct.

**Soft isolation mechanics:**

1. **Tenant context propagation via `contextvars`, not thread-locals.** Under ASGI / Channels / async views / NATS background workers, thread-locals do not propagate correctly across async tasks and worker pools. Use `contextvars.ContextVar` (or `asgiref.local.Local` which wraps it) for the in-request tenant context. **Background workers (NATS consumers, scheduled `reconcile_*` commands, hook dispatchers) do not inherit request context** — they must carry an explicit `tenant_id` on every NATS message envelope and `Job` row, and re-establish the tenant context at the start of each handler. This is load-bearing: a `Job` written under tenant A and processed by a worker that defaulted to tenant B would corrupt isolation silently. The audit-emission CI test gains a check that every cross-process payload carries `tenant_id`.
2. **Tenant-aware managers** on tenant-scoped models default to filtering by the current context's tenant. Default `Model.objects.all()` is tenant-scoped; an explicit `Model.objects.all_tenants()` (admin-only RBAC) bypasses the filter for ops.
3. **RBAC predicates** extended: every permission check evaluates `actor.tenant_membership.scope ⊇ resource.tenant` *before* the role/permission lookup. The CI permission-snapshot test gains a tenant-scope dimension.
4. **Audit emission** captures any cross-tenant access attempt as a `tenant.cross_access` event (allowed or denied — both auditable). This is the cross-tenant analog of the §5.1 `permission.denied` event.
5. **Postgres**: no schema separation. One `public` schema, tenant FK columns, indexed on `tenant_id` first (composite indexes lead with `tenant_id`).

### 19.3 Cross-tenant resource sharing

A resource declares its `sharing_scope`:

| Scope | Behaviour |
|---|---|
| `tenant_local` (default) | Visible only to the owning tenant. |
| `shared_with_tenants = [t1, t2, ...]` | Explicit allow-list. Visible to listed tenants; modifications still gated by RBAC. |
| `global` | Visible to all tenants. Reserved for system-level RackLab-owned resources (e.g. an Ubuntu 24.04 cloud image template every tenant can build from). |

**Cross-tenant access semantics:**

- **Use vs modify.** Sharing a resource grants the `use` permission (e.g. "deploy from this template", "attach to this network"); it does not grant `modify` / `delete`. The owning tenant retains those.
- **Quota accounting.** Cross-tenant uses count against the *consumer* tenant's quota, not the owner's. A RIT-shared Ubuntu image deployed by partner school X consumes X's vCPU/RAM quota.
- **Audit.** Both tenants get audit events: the owner gets a `tenant.shared_resource.used` event (so they can see who's consuming what); the consumer gets the normal use event with a `cross_tenant_owner` correlation tag.
- **Plugin opt-in.** Plugins declare in their manifest whether their tenant-scoped models support cross-tenant sharing (default: false). A future plugin can later promote a model to support sharing via a migration + manifest update.

**Example shareable resources:**

- `CatalogTemplate` (Ubuntu cloud image, Windows trial ISO, instructor-curated template): RIT owns; shared `global` so partner schools build from the same baseline.
- `ProviderEndpoint` (a Proxmox cluster physically hosted by RIT): owned by RIT; `shared_with_tenants = [partner_school_a, partner_school_b]` so multiple tenants can request VMs against the same cluster (each tenant's deployments stay tagged with the consumer tenant for accounting).
- `NetworkOffering` (an external bridge to a shared lab subnet): same pattern.
- `Plugin` itself — installed once at system level, available to all tenants by default; tenants can disable per-tenant if RBAC allows.

**Example tenant-local-only:**

- `ProviderCredentials` (the API token used to call a Proxmox cluster) — never shared. A shared `ProviderEndpoint` references credentials owned per-tenant; sharing the endpoint shares the *target*, not the *keys*.
- `UserNotification`, `JobLog`, `AuditEvent` — never shared.
- `QuotaPolicy` — never shared (quotas are inherently per-tenant).

### 19.4 Cross-tenant RBAC — role bindings that span tenants

**Decision (this session, 2026-05-25):** Role bindings carry a **scope dimension** in addition to a role. A binding can be `tenant_local` (today's default), `multi_tenant` (binding applies across an explicit set of tenants), or `global` (binding applies across every tenant). This is orthogonal to §19.3 cross-tenant resource *sharing* and composes with it.

**Why we need it:**

- **RackLab platform operators** (RIT IT staff who run the cluster) need a single role binding that lets them troubleshoot or administer any tenant without having one binding per tenant.
- **Consortium instructors** teaching a course offered jointly between RIT and a partner school need the same `instructor` role across both tenants.
- **Support engineers** need read-only access across all tenants for incident response without exposing tenant data laterally to other tenants' users.

**Binding model extension:**

| Field | Values | Behaviour |
|---|---|---|
| `RoleBinding.scope_type` | `tenant_local` (default) / `multi_tenant` / `global` | Defines how broadly the binding applies. |
| `RoleBinding.tenant_set` | `[Tenant.id, …]` (only for `multi_tenant`); empty for `global`; single value matching the resource tenant for `tenant_local` | The explicit allow-list of tenants the binding covers when scope is `multi_tenant`. |
| `RoleBinding.granted_by` | `User.id` | Who created the binding — for audit. |
| `RoleBinding.granted_reason` | text | Justification — for audit. |

**Permission evaluation composes three checks:**

1. **Tenant scope match.** Is the actor's binding scope `tenant_local` matching the resource tenant, OR `multi_tenant` and the resource tenant is in `tenant_set`, OR `global`?
2. **Resource visibility match.** Is the resource `tenant_local` matching the actor's tenant, OR `shared_with_tenants` including the actor's tenant, OR `global`?
3. **Permission match.** Does the role include the requested action?

All three must pass. Either of the first two failing emits a `tenant.cross_access` audit event (`result=denied`); the third failing emits `permission.denied` (existing).

**Issuance discipline:**

- **`multi_tenant` and `global` bindings can only be issued by a binding that already has scope ≥ the issued binding's scope.** A tenant-local admin cannot create a global binding. This is the same containment rule that prevents privilege escalation in single-tenant RBAC, extended to the tenant-scope dimension.
- **Every issuance is audited** with `granted_by`, `granted_reason`, and `scope_type` — `multi_tenant` and `global` bindings are inherently sensitive and should appear in operator-visible audit dashboards by default.
- **CI permission-snapshot test** gains a dimension: which roles support which `scope_type` values. The catalog declares per-role whether the role is allowed to be bound at `multi_tenant` or `global` scope (most roles are `tenant_local` only; only `platform_operator`, `consortium_instructor`, and `support_readonly` allow broader scopes by default).
- **Revocation** is the inverse: revoking a `global` binding requires `global`-scope authority.

**Audit shape for cross-tenant actions:**

When a user with a `multi_tenant` or `global` binding acts on a resource in a tenant they are not a member of, the audit envelope captures:

- `actor_tenant` — the actor's primary tenant
- `resource_tenant` — the tenant the resource belongs to
- `binding_scope` — `multi_tenant` or `global`
- `binding_id` — which specific binding authorised this access
- normal action / resource / outcome fields

This makes it possible to answer "show me everything user X did on tenant Y while acting under their global binding" cleanly.

**Examples:**

- A RIT IT engineer with `platform_operator` role bound `global`: can manage every tenant. Every action emits a cross-tenant audit row.
- A consortium instructor with `instructor` role bound `multi_tenant=[RIT, partner_school]`: can edit catalogs and run labs in either tenant. Cannot see tenant `other_school`.
- A support engineer with `support_readonly` role bound `global`: can read every tenant's deployments and audit logs; cannot write. Every read still emits a cross-tenant audit row (for "who looked at what").

**What's still tenant-local-only (cannot escalate to cross-tenant binding):**

- `tenant_admin` — admin of a single tenant, by design.
- `instructor` — the default; promotion to `multi_tenant` requires explicit catalog-level decision.
- `student` — never cross-tenant; isolation is the entire pedagogical model.

**Library impact:**

- Mostly internal to the custom nested-pack RBAC — no new external library needed.
- The `tenant.cross_access` audit event lives in the existing custom emitter; the outbox-to-NATS work (§5.2) carries `binding_scope` as a payload field for SIEM correlation.
- `django-rules` (already adopted for narrow scope) can be useful for the "is this binding scope sufficient" predicate, expressed once and reused.

### 19.5 Library choice

| Library | Decision |
|---|---|
| **django-tenants** | **Skip — overkill.** Schema-per-tenant is for hard SaaS isolation; it complicates every migration and query, breaks cross-tenant sharing (cross-schema queries are awkward), and conflicts with the existing single-DB testcontainers integration test discipline. |
| **django-multitenant** (Citus) | **Skip — wrong shape.** Assumes a sharded Postgres via Citus extension. RackLab is single-Postgres-instance through M13a. |
| **django-organizations** | **Skip — wrong model.** Generic org/membership; covered by RackLab's custom Tenant + existing membership model. |
| **django-scopes 2.0.0** | **Spike before adopt — likely defer.** Codex (2026-05-25) corrected: license is **Apache-2.0**, last release **2023-04-22**, classifiers cover Django **3.2–4.0 only** (no 5.2 testing visible). Docs flag caveats around admin / test fixtures / migrations / advisory-lock interactions. Useful conceptually as belt-and-suspenders against accidental cross-tenant queries, but the staleness against Django 5.2 means it can't be a default adoption. If the team wants the automatic scoping idiom, **spike** on Django 5.2 + Channels + the tenant-aware managers RackLab builds; if it composes cleanly, adopt. Otherwise, the custom managers below already provide the protection. |
| **Tenant context middleware + custom managers** | **Adopt (build custom, ~150 LoC)** — Django doesn't need a library for "set a tenant on each request." Use `contextvars` (or `asgiref.local.Local`) per the async-safety note above; managers default-filter on the current tenant. This is the primary mechanism; `django-scopes` is icing if the spike clears. |

### 19.6 Migration story

| Milestone | Tenancy work |
|---|---|
| **M0 (now, after PRD edit)** | Add `Tenant` model + migration. Backfill all existing rows to a single `default` tenant (RIT). Tenant FK on the root tables that exist today. Tenant context middleware. Tenant-aware managers on the M0 models. CI test that fails the build if a new model lacks a `tenant` FK without an explicit `@untenanted` decorator. |
| **M1 (auth + identity)** | Tenant-aware user memberships. Tenant switcher in the user UI. Tenant-aware login redirects. allauth's `Account.user` extended with tenant membership rows. |
| **M2 (deployment lifecycle)** | Tenant-scoped catalogs and deployments. Cross-tenant share-link primitive uses `TimestampSigner` with tenant scope in the signed payload. |
| **M3 (Proxmox provider)** | `ProviderEndpoint` gains `sharing_scope`; `ProviderCredentials` stays tenant-local. Per-tenant Proxmox routing in the provider plugin. |
| **M5a/M5b (networking)** | `NetworkOffering` gains `sharing_scope`. Tenant-scoped routers / floating IPs / security groups. |
| **M6 (quotas + scheduling)** | Quotas scoped to `(tenant, course?, project?)`. Cross-tenant uses count against the consumer tenant's quota. Reservations carry a `tenant` tag. |
| **M8 (docs plugin)** | Docs can be tenant-local or shared. `racklabRef` cross-link resolver respects tenant visibility. |
| **M9 (SSH plugin)** | Console-access grants carry a tenant scope. Cross-tenant SSH access requires explicit grant. |

### 19.7 Acceptance criteria addition

M0 acceptance criteria gain:

- [ ] Creating a `Tenant`, switching the request context to it, and creating a `Course` under it works; the `Course` row carries the tenant FK.
- [ ] A query made under tenant A's context cannot return rows owned by tenant B without an explicit `all_tenants()` manager call.
- [ ] Attempting cross-tenant access without sharing scope or a cross-tenant binding emits a `tenant.cross_access` audit event with `result=denied`.
- [ ] Sharing a `CatalogTemplate` `global` makes it visible to all tenants; the use is audited with `cross_tenant_owner` tag.
- [ ] Granting a `multi_tenant` or `global` role binding requires the granter to hold a binding of equal or broader scope; attempting to escalate fails with `tenant.cross_access` audit (`result=denied`, reason=insufficient_scope`).
- [ ] A user with a `global` binding performing an action on a tenant they are not a member of emits a `tenant.cross_access` audit event with `binding_scope=global` and `result=allowed`.
- [ ] `Job`, `AuditEvent`, `Artifact`, `Deployment`, `Reservation` carry a denormalized `tenant_id` column populated on insert; updating that column post-insert raises a model-level validation error.
- [ ] Tenant context flows correctly through ASGI async views, Channels consumers, and NATS message handlers (`contextvars`-based propagation; explicit `tenant_id` on NATS message envelopes).
- [ ] The new CI test refuses to merge a model without a `tenant` FK unless decorated `@untenanted`.

### 19.8 Open questions to resolve at PRD edit time

- Should the default Tenant be implicit (created at first run, hidden) or explicit (operator must create a tenant before any other setup)? Recommendation: implicit-then-renamable, so first-run-install isn't blocked.
- Tenant subdomain routing vs path-prefix routing vs explicit switcher only? Recommendation: explicit switcher only in v1; subdomain routing later if RackLab is ever hosted as multi-org SaaS.
- Plugin tenant scoping: should plugins be system-installed-and-globally-enabled (current PRD), or system-installed-and-per-tenant-enabled, or per-tenant-installed? Recommendation: system-installed, per-tenant-enabled — gives operators control without forcing per-tenant install workflows.
- `Job` / `Artifact` retention policies: per-tenant overridable, or system-default-only? Recommendation: system-default with per-tenant overrides allowed in M6.

Sources: [django-scopes](https://pypi.org/project/django-scopes/), [django-tenants (background — not adopted)](https://pypi.org/project/django-tenants/), [OpenStack multi-tenancy patterns](https://docs.openstack.org/security-guide/identity/policies.html) (reference design only).

---

## §20. React stack — Django + React islands + Mantine

**Decision (this session, 2026-05-25):** Django + React islands via **django-vite**, with **Mantine** as the primary component library, **Radix UI primitives** as the ARIA-fallback for any component Mantine doesn't cover at WCAG 2.2 AA, and **LinguiJS** for translations sharing `.po` catalogs with Django's `gettext`. Mantine chosen over Chakra for feature completeness — Mantine ships first-party `@mantine/dates`, `@mantine/form`, `@mantine/notifications`, `@mantine/modals`, `@mantine/dropzone`, `@mantine/spotlight`, `@mantine/tiptap` which cover most of the categories below without third-party glue.

**LLM-friendly docs index:** Mantine publishes an [`llms.txt`](https://mantine.dev/llms.txt) index pointing to per-component / per-hook Markdown docs (each under `https://mantine.dev/llms/...`), plus a consolidated [`llms-full.txt`](https://mantine.dev/llms-full.txt). Confirmed via fetch: the index enumerates ~120 core components (Tree, Combobox, Tabs, Modal, Drawer, Dialog, AppShell, ScrollArea, Stepper, Notification, etc.) and ~80 hooks. Use this as the authoritative reference when wiring components — it's the source of truth ahead of any cached recollection here. Notable native components I'd missed below: **Mantine has a built-in `Tree` component** (`core-tree.md`) — worth evaluating before reaching for `react-arborist` in §20.8.

### 20.1 Architecture — Django + React islands via django-vite

Django serves layout, auth, CSRF, SSO, and the page chrome (header/nav/footer); React mounts on specific component roots via Vite chunks. Each "island" is a small SPA-shaped mount loaded from a Django template:

```html
<div id="deployment-dash" data-init='{"deployment_id":"…"}'></div>
{% vite_asset 'src/islands/deployment-dash.tsx' %}
```

| Tooling | Version | Role |
|---|---|---|
| **Vite** | 8.x | Build, HMR, code-splitting |
| **`@vitejs/plugin-react-swc`** | current | SWC-based React transform (faster than Babel plugin) |
| **`django-vite`** | 3.1.0 (Feb 2025) | `{% vite_asset %}` + `{% vite_react_refresh %}` template tags; manifest-driven prod |
| **TypeScript** | 5.5+ strict | Hard requirement — Zod, TanStack Query, Mantine, RHF all assume strict TS |
| **React** | 19.x | Concurrent features; Mantine 8 supports it |

### 20.2 Component library — Mantine + Radix gaps

| Package | Use |
|---|---|
| **`@mantine/core`** | AppShell, Card, Button, Input, Modal, Drawer, Menu, Combobox, Tabs, Accordion, Badge, Group, Stack, Grid, etc. |
| **`@mantine/hooks`** | `useDisclosure`, `useDebouncedValue`, `useClickOutside`, `useElementSize`, etc. |
| **`@mantine/dates`** | DatePicker, DateTimePicker, MonthPicker, Calendar — replaces Flatpickr |
| **`@mantine/notifications`** | Toast/notification system — replaces Toastr |
| **`@mantine/modals`** | `modals.openConfirmModal` / `openModal` API — replaces bootbox |
| **`@mantine/dropzone`** | File-drop UI; pairs with FilePond core for chunked uploads (see §18) |
| **`@mantine/spotlight`** | Command-palette UX (Ctrl-K) — RackLab's global resource jump |
| **`@mantine/tiptap`** | TipTap React wrapper with Mantine-styled toolbar — M8 docs plugin |
| **`@mantine/code-highlight`** | Code blocks via Highlight.js or Shiki |
| **`@mantine/form`** | Lightweight form state (built-in) |

**ARIA gaps — fill with Radix UI primitives.** Mantine's components are *generally* WAI-ARIA-compliant but the maintainers themselves note that some component modes require usage discipline and explicit testing — it is not "accessible by default" the way Radix is. RackLab's CI a11y gates (axe-core + pa11y + Storybook a11y addon) will catch the gaps as components are wired; when they fire, drop in the equivalent **`@radix-ui/react-*`** primitive and style it with Mantine's styling system. Expect this to come up for: stacked / nested dialogs (use `@radix-ui/react-dialog`), complex menus / combobox (use `@radix-ui/react-menubar` or `@radix-ui/react-select`), tooltips with rich content (`@radix-ui/react-tooltip`), and any composite widget where Mantine's default keyboard model proves insufficient.

**Why Mantine over Chakra (the other option from the picker):** for a control-plane app with form-heavy + table-heavy + date-heavy + notification-heavy surfaces, Mantine's first-party coverage of those categories is unmatched. Chakra is leaner but you'd assemble more components yourself, which fights the survey's overall "don't reinvent" thesis.

**Mantine's own i18n story:** Mantine doesn't translate its components — all translatable strings come from your app's catalog. Mantine accepts translated strings via props; wrap once in a `<RackLabProvider>` that injects defaults from LinguiJS. This is fine and intentional; if Mantine *did* ship its own catalog you'd have to reconcile it with Django's.

### 20.3 Forms

| Library | Verdict |
|---|---|
| **`@mantine/form`** | **Adopt for simple/medium forms** — login, search, settings panes. Native Mantine integration, no glue. |
| **React Hook Form 7.76+ + Zod resolver** | **Adopt for complex forms** — catalog template editor, deployment-launch wizard, plugin config screens. Better async validation; Mantine inputs work with RHF via `<Controller>`. |
| Formik | **Skip — abandoned** (705 open issues; no a11y focus). |
| TanStack Form | **Defer** — newer; smaller ecosystem; revisit if RHF performance becomes the bottleneck. |

### 20.4 Tables

| Library | Verdict |
|---|---|
| **TanStack Table 8** (headless) + Mantine `<Table>` markup | **Adopt as primary** — RackLab owns the rendering, sorts/filters/pagination logic is TanStack. Best ARIA: you wire `aria-sort`, `role="grid"` if needed. |
| **`mantine-react-table`** (community) | **Adopt for specific scope** — drop-in "I just want a table" cases pre-styled with Mantine. Built on TanStack Table internally. |
| AG-Grid Community | **Skip** — canvas-virtualized; a11y story weaker for screen readers. |

### 20.5 State + server cache

| Library | Verdict |
|---|---|
| **TanStack Query v5** | **Adopt** — DRF server state. Pairs with drf-spectacular generated types via codegen. |
| **Zustand v5** | **Adopt** — UI/client state (sidebar collapse, modal stack, theme). |
| Redux Toolkit | **Skip** — overkill; TQ + Zustand covers needs. |
| SWR / Jotai | **Skip** — TQ + Zustand picked. |

### 20.6 i18n — LinguiJS for `.po` catalog sharing with Django

**Adopt: LinguiJS v6** (`@lingui/core`, `@lingui/react`, `@lingui/macro`, `@lingui/vite-plugin`).

The reason: LinguiJS stores catalogs natively as `.po` files — the exact format Django's `gettext` produces. Workflow:

1. Developers wrap strings: `<Trans>Deploy lab</Trans>` or `t`Deploy lab``.
2. `lingui extract` writes/updates `.po` files in the same `locale/<lang>/LC_MESSAGES/` directory Django reads.
3. Translators (or `django-rosetta` in Django admin) edit the same `.po` files.
4. `lingui compile` produces the JS bundles consumed by React at runtime.

Single source of truth, no `.po → .json` conversion artifact, no string drift.

| Alternative | Verdict |
|---|---|
| `react-i18next` + `i18next-gettext-converter` | **Adopt only if the team strongly prefers the i18next ecosystem.** Conversion step (`i18next-conv django.po → react.json`) is bidirectional but adds a build artifact. |
| FormatJS / react-intl | **Skip** — ICU MessageFormat plural rules differ from gettext; lossy. |

### 20.7 Routing — server-driven by Django

In islands architecture there's no React Router. Inter-page navigation is full-page Django (CSRF, auth, SSO all native). Intra-page navigation (tab switches, modal flows, accordion expand/collapse) is Mantine + Zustand state.

### 20.8 jQuery-plugin → React-equivalent map

| PRD §15 jQuery slate (out) | React replacement |
|---|---|
| DataTables | **TanStack Table 8** + Mantine `<Table>` (or `mantine-react-table` for the simple case) |
| Select2 | **Mantine Select / MultiSelect / Combobox** (built-in, ARIA-correct) |
| Flatpickr | **`@mantine/dates`** (built-in) |
| jQuery Validate | **Zod v4 schemas** consumed by `@mantine/form` or React Hook Form |
| SortableJS | **dnd-kit** (ARIA-friendly, customisable screen-reader instructions, active through 2026) |
| jstree | **Mantine `Tree` component** (built-in, see `https://mantine.dev/llms/core-tree.md` — spike first to confirm it covers the lab/template/network hierarchy use cases). Fallback: **react-arborist** 3.8.0 (virtualised, DnD, keyboard nav, ARIA attributes; verify with axe-core in a spike) if Mantine's Tree is missing DnD or virtualisation for the volumes RackLab needs. |
| Toastr | **`@mantine/notifications`** (built-in, ARIA live regions) |
| bootbox | **`@mantine/modals`** (confirm + prompt API) |
| blueimp jQuery File Upload | Already replaced — **FilePond** core via **`react-filepond`** 7.1.3 (Dec 2024 — current). Mount via the wrapper; fallback is to mount FilePond core directly with `useEffect`/`useRef` if the wrapper hits a React 19 issue. Pairs with `@mantine/dropzone` for surface chrome. See §18 for the full chunked-upload protocol + upload-session invariants. |

### 20.9 Vanilla JS exceptions that survive — React mount notes

| Library | React integration |
|---|---|
| Chart.js | **`react-chartjs-2` 5.3.1** — official React wrapper. Pair with a `<AccessibleChart>` HOC that adds `aria-label` on `<canvas>` + an offscreen `<table>` summary for screen readers. |
| Cytoscape.js | **`react-cytoscapejs`** — community wrapper. |
| Prism.js | Replaced by **`react-syntax-highlighter`** (which wraps Prism or highlight.js). |
| clipboard.js | Replaced by **`navigator.clipboard.writeText`** + `@mantine/notifications` for confirm toast. |
| marked + DOMPurify | Replaced by **`react-markdown` 10.1.0 + `remark-gfm`** — sanitised by default (no `dangerouslySetInnerHTML`). Skip nh3 on the client side (no JS port); server-side sanitisation per §9 still applies if Markdown round-trips to the server. |
| noVNC 1.7.0 | Vanilla — `useRef` + `useEffect` mount; `RFB.disconnect()` in cleanup. |
| **xterm.js** (now `@xterm/xterm` 6.0.0, package renamed from `xterm`) | Vanilla — same pattern. Update PRD §15 + M4/M9 to reflect the rename. |
| TipTap | **`@tiptap/react`** + **`@mantine/tiptap`** for the Mantine-styled toolbar. |

### 20.10 Schema validation, testing, a11y CI

| Layer | Library | Note |
|---|---|---|
| Schema validation | **Zod v4** | TS-first; pairs with RHF + TanStack Query response parsing. |
| Unit tests | **Vitest 4** + **React Testing Library** | Vite-native; reuses Vite config. |
| Component E2E | **Playwright** | Already in PRD §17. |
| Component sandbox | **Storybook 10** | Hard CI requirement — forces components to render in isolation and pass a11y addon checks before they hit application pages. |
| a11y in unit tests | **`vitest-axe`** | Component-level `axe()` assertions. |
| a11y in Storybook | **Storybook a11y addon** | Catches issues during component dev. |
| a11y in E2E | **`@axe-core/playwright`** | Already in PRD §17. |

### 20.11 PRD edits this pivot triggers

- **PRD §15 (UI / UX)** — wholesale rewrite of the frontend slate. Drop the jQuery plugin list; replace with the §20.2 Mantine + §20.8 substitution table + §20.9 surviving vanilla libraries. Bootstrap-CSS commitment is removed (Mantine has its own styling system; Django-rendered chrome can still use Bootstrap 5 CSS classes if desired, but React islands don't). Document plugin-shipped React island constraints (plugins ship their own Vite-built bundles registered in the Vite manifest; CSP nonce policy applies; tenant-aware data fetches mandatory; per-plugin ESLint + axe-core CI hooks required).
- **PRD §15 (i18n)** — add LinguiJS as the canonical catalog tool; clarify that React and Django share `.po` files under `locale/<lang>/LC_MESSAGES/`; specify the Django and React `domain`s so extract passes don't collide; document Lingui's PO plural-form caveats.
- **PRD §15 (a11y)** — explicit Mantine + Radix-gaps strategy; add Storybook a11y addon to the CI matrix; require axe-core in Playwright E2E and `vitest-axe` in component tests.
- **PRD §15 (asset pipeline)** — Vite manifest discovery via `django-vite` 3.1; CSP `script-src 'nonce-…'` policy; `style-src` allowance for Mantine's CSS-in-JS injected styles; `connect-src` allowance for HMR in dev only.
- **PRD §15 (linting)** — ESLint + `eslint-plugin-jsx-a11y` + `eslint-plugin-react` + `eslint-plugin-react-hooks` + Prettier as a pre-commit hook for the React tree.
- **PRD §15 (large-file upload)** — FilePond chunked protocol (`chunkUploads: true`), upload-session invariants per §18.4, quarantine + scan flow per §18.5. Adopt `django-drf-filepond` after a spike OR hand-roll the protocol; never ship a "FileField for large files" path.
- **PRD §17 (engineering quality)** — add Vitest 4 + React Testing Library + Storybook 10 + Zod 4 + `vitest-axe` + `@axe-core/playwright` to the testing matrix. Add TypeScript 5.5+ strict as a hard requirement. ESLint a11y plugin in pre-commit.
- **PRD §18 (security)** — add tenant-aware UI/API constraints (`tenant_id` in DRF response envelopes; React never renders cross-tenant data unless the binding scope explicitly allows it; cross-tenant pages emit the audit event from the React side too via a sentinel API call so client-side leaks are detectable).
- **PRD §19 (data model)** — Tenant model + denormalized `tenant_id` columns on `Job`, `AuditEvent`, `Artifact`, `Deployment`, `Reservation`. `RoleBinding.scope_type` + `tenant_set`. `UploadSession` model for §18 chunked-upload tracking.
- **PRD §22 (docs plugin)** — `@tiptap/react` + `@mantine/tiptap`. TipTap docs reference: confirm the editor mounts in a React island, not a vanilla mount.
- **PRD §23 (SSH plugin)** — xterm.js mounted inside a React component via `useRef`/`useEffect`; note the `@xterm/xterm` package rename (was `xterm`); same pattern for `@novnc/novnc` (M4 console plugin).

### 20.12 Roadmap edits this pivot triggers

- **M0** — frontend skeleton lands here: `package.json` + Vite config + django-vite wiring + Mantine + LinguiJS + Storybook scaffold + Vitest scaffold + axe-core CI hook. No application UI yet, but the build pipeline is real.
- **M2 (deployment lifecycle)** — first real React work: deployment dashboard built with Mantine + TanStack Query against the fake provider's DRF endpoints.
- **M4 (console plugin)** — xterm.js + noVNC mounted inside React components.
- **M8 (docs plugin)** — `@tiptap/react` + `@mantine/tiptap`.
- **M10 (UI / a11y / i18n)** — **split into M10a + M10b** analogous to M5a/M5b and M11a/M11b:
  - **M10a** — component library coverage + page shell for every PRD §15 key screen, built on Mantine + LinguiJS.
  - **M10b** — a11y + i18n full pass: axe-core CI green for every key screen, pa11y CI green, manual NVDA / VoiceOver / Orca runs, en-US + one example locale catalog complete.

### 20.13 What was DQ'd by the binding constraints

| Library | Why |
|---|---|
| React-Bootstrap, reactstrap | Team picked off Bootstrap-as-components in favour of Mantine. (Bootstrap 5 CSS may still appear in Django-rendered chrome.) |
| shadcn/ui, Headless UI | Tailwind-dependent; conflicts with the "no Tailwind" rule. |
| MUI, Ant Design | Material/Ant clash with Mantine choice. |
| Formik | 705 open issues; no a11y focus; superseded by React Hook Form. |
| react-beautiful-dnd | Atlassian archived 2025-08-18; npm-deprecated. |
| AG-Grid Community | Canvas-virtualised rendering breaks screen-reader semantics. |
| FormatJS / react-intl | ICU MessageFormat plural rules diverge from gettext. |
| Next.js, Remix | Django stays the server — no SSR layer needed. |
| React Router, TanStack Router | Django routes; no client-side router in islands. |

### 20.14 Open questions for PRD review

- **Storybook as hard CI gate or soft dev tool?** Recommend **hard**: forces a11y catches during component dev rather than at integration.
- **TypeScript strict — hard requirement?** Recommend **hard**: Zod + TanStack Query + Mantine + RHF all assume it.
- **React 19 vs 18?** Recommend **19** for concurrent features and Mantine 8 support.
- **Mantine pin discipline.** Latest npm-published stable at the time of this survey is **9.2.1** (May 2026). Pin to the latest stable at M0 ship and track upgrades via a Renovate / Dependabot policy. Re-verify before pinning — Mantine ships frequent minor releases.
- **Chakra UI as the fallback if Mantine ARIA proves insufficient?** Document the criterion: if axe-core / pa11y / manual screen-reader testing finds Mantine components fail WCAG 2.2 AA in ways the Radix-fallback strategy can't fix, switch the whole component library to Chakra. Unlikely but worth documenting.

Sources: [Mantine](https://mantine.dev/), [Mantine LLM docs index (`llms.txt`)](https://mantine.dev/llms.txt), [Mantine LLM docs consolidated (`llms-full.txt`)](https://mantine.dev/llms-full.txt), [Mantine accessibility FAQ](https://mantine.dev/llms/q-are-mantine-components-accessible.md), [Mantine Tree](https://mantine.dev/llms/core-tree.md), [Mantine Dropzone upload guide](https://mantine.dev/llms/q-dropzone-upload.md), [django-vite](https://pypi.org/project/django-vite/), [Vite](https://vitejs.dev/), [TanStack Query](https://tanstack.com/query/v5), [TanStack Table](https://tanstack.com/table/v8), [LinguiJS](https://lingui.dev/), [dnd-kit](https://dndkit.com/), [react-arborist](https://github.com/brimdata/react-arborist), [cmdk](https://cmdk.paco.me/), [react-chartjs-2](https://react-chartjs-2.js.org/), [@tiptap/react](https://tiptap.dev/docs/editor/getting-started/install/react), [Zod](https://zod.dev/), [Vitest](https://vitest.dev/), [Storybook](https://storybook.js.org/), [Radix UI](https://www.radix-ui.com/primitives), [@xterm/xterm](https://www.npmjs.com/package/@xterm/xterm), [react-i18next](https://react.i18next.com/), [i18next-gettext-converter](https://github.com/i18next/i18next-gettext-converter).

---

## Appendix A. Source-quality flags

PyPI HTML rendering failed intermittently during research; the following data points were validated against GitHub or vendor blogs rather than PyPI directly. Re-verify before pinning:

- `djangorestframework-camel-case` 1.4.2 — release date couldn't be fetched; PyPI page kept failing.
- `django-model-utils` 5.0.0 — Django 5.2 classifier not yet present; Jazzband typically lags formal classifier updates by a release.
- `django-watson` 1.6.3 — Django 5.2 untested per PyPI classifiers.
- `django-resized` 1.0.3 — tested through 5.1, not 5.2.
- `django-notifications-hq` 1.8.3 — no release since 2023; verify before relying on it for production.
- `casbin` (pycasbin) — PyPI shows 1.43.0; GitHub repo also references "v2.8.0" which appears to refer to the cross-language Casbin project, not pycasbin specifically. Treat 1.43.0 as authoritative for Python.
- `Permify` Python SDK — PyPI metadata says AGPL-3.0; GitHub repo states Apache-2.0. License conflict needs maintainer confirmation before adoption.
- Proxmox `rrddata` API parameters — confirmed via PVE wiki + forum; the `pve-docs/api-viewer` URL returned a thin HTML 502. Verify against a live PVE 8 instance before wiring the timeframe enum into code.
- `Chart.js` 4.5.1 (Oct 2024) and `chartjs-adapter-date-fns` 3.0.0 (Dec 2022) — both stable but old. Verify the pair still works at adoption time.

## Appendix B. What was deliberately not researched

This survey covers the open library questions for RackLab's roadmap milestones M0–M13. The following areas are out of scope because the decisions are already locked elsewhere:

- Plugin discovery — pluggy is pinned and good.
- Pydantic 2.x — pinned.
- DRF 3.16 + drf-spectacular — pinned.
- Postgres / psycopg 3.x — pinned.
- proxmoxer 2.x — pinned; see [Proxmox client discipline spec](../superpowers/specs/2026-05-24-proxmox-client-discipline.md).
- Podman / Quadlets / Nomad — see [Podman orchestration spec](../superpowers/specs/2026-05-24-podman-orchestration.md).
- Traefik 3.x — see [TLS/ACME spec](../superpowers/specs/2026-05-24-server-side-tls-acme.md).
- NATS — pinned.
- asyncssh — pinned.
- xterm.js, noVNC, TipTap, Chart.js, Cytoscape.js, Prism.js, marked + DOMPurify, clipboard.js — frontend slate decided per PRD §15.
- DataTables, Select2, Flatpickr, blueimp jQuery File Upload, jQuery Validate, SortableJS, jstree, Toastr, bootbox — frontend plugin slate decided per PRD §15.

## Appendix C. What this survey does not do

- It does not change any PRD section. PRD edits are still required to ratify adoptions.
- It does not add any pyproject.toml dependency. Pins follow PRD approval.
- It does not finalise the deployment-FSM decision. That stays open until M2 lands.
- It does not commit to a Timescale-on-existing-Postgres deployment topology — that decision interacts with M13a (HA data tier) and should be reviewed there.
- It does not replace the existing custom RBAC, audit emitter, or quota design. It complements them.

# UI And UX

RackLab uses **Django + React islands via django-vite**: Django renders server-side HTML for layout, auth, CSRF, and SSO; React mounts on specific component roots via Vite chunks. Component library is **Mantine** (pin latest stable at M0 ship; currently 9.2.1) with **Radix UI primitives** as the ARIA-fallback. Vanilla JS exceptions (`@xterm/xterm`, `@novnc/novnc`) mount inside React via `useEffect`/`useRef`. Server-rendered Django chrome (login, error pages, plugin-shipped server forms) may still use Bootstrap 5 CSS for the chrome — that's fine and doesn't conflict with the React-island styling.

## UI Architecture

The frontend pivoted from "Django templates + Bootstrap 5 + jQuery 3" (the original PRD direction) to **Django + React islands** during the 2026-05-25 library survey. The change resolves several issues: it gives RackLab modern accessibility primitives (Mantine + Radix), shares translation catalogs cleanly with Django via `.po` files (LinguiJS v6), and removes the dead-end on the jQuery plugin slate (DataTables / Select2 / Flatpickr / jQuery Validate / SortableJS / jstree / Toastr / bootbox / blueimp — several of which are abandoned).

Requirements:

- **No SPA as the default**; islands architecture. Each interactive component is a small React mount on a Django-rendered page.
- **Django templates for full pages.** Pages include layout, auth chrome, navigation, and React-island mount points.
- **Vite 8 + `@vitejs/plugin-react-swc` + django-vite 3.1** for the React build pipeline. Vite manifest discovery via `{% vite_asset %}` template tag; HMR in dev; manifest-driven prod.
- **Mantine** as the primary component library (`@mantine/core`, `@mantine/hooks`, `@mantine/dates`, `@mantine/form`, `@mantine/notifications`, `@mantine/modals`, `@mantine/dropzone`, `@mantine/spotlight`, `@mantine/tiptap`, `@mantine/code-highlight`). LLM-friendly docs index at <https://mantine.dev/llms.txt>.
- **Radix UI primitives** (`@radix-ui/react-*`) as the ARIA-fallback when axe-core / pa11y / Storybook a11y addon flag a Mantine component as insufficient.
- **TypeScript 5.5+ strict** is mandatory for the React tree.
- **React 19+** for concurrent features and Mantine 9 support.
- **State**: `@tanstack/react-query` v5 for server state against DRF endpoints (pairs with drf-spectacular generated types); Zustand v5 for client UI state.
- **Forms**: `@mantine/form` for simple/medium; `react-hook-form` + Zod resolver for complex (catalog editor, deployment wizard, plugin config).
- **Tables**: `@tanstack/react-table` v8 (headless) + Mantine `<Table>` markup. `mantine-react-table` is **not** a drop-in option today — its current npm release peers `@mantine/core ^6.0`, which is incompatible with the Mantine 9.x pin; re-evaluate when a 9.x-compatible release exists.
- **Schema validation**: Zod v4.
- **Routing**: server-driven by Django (full-page navigation); no React Router. Intra-page navigation (tabs, modals) via Mantine + Zustand state.
- **HTMX is explicitly out.** No Alpine. No Tailwind in user-facing pages (Mantine's CSS-in-JS handles styling). No Vue/Svelte/Solid.

## Operator admin UI

Stock Django admin until **M10a** lands a React-based custom operator UI shell. No admin theme — django-unfold bundles HTMX/Tailwind/Alpine which conflict with the binding constraints; django-jazzmin is Bootstrap-4-mismatched; django-grappelli is dated. Stock Django admin has gettext i18n built in and is ARIA-compliant by default. Accept the visual cost as the price of constraint compliance.

## Surviving vanilla libraries (mounted inside React via `useEffect`/`useRef`)

| Library | React integration |
|---|---|
| Chart.js | `react-chartjs-2` 5.3.1. Wrap once in a RackLab `<AccessibleChart>` HOC that adds `aria-label` on the `<canvas>` + an offscreen `<table>` summary for screen readers. |
| Cytoscape.js | `react-cytoscapejs` wrapper. |
| Prism.js | Replaced by `react-syntax-highlighter` (wraps Prism / highlight.js). |
| clipboard.js | Replaced by `navigator.clipboard.writeText()` + `@mantine/notifications` confirm toast. |
| marked + DOMPurify | Replaced by `react-markdown` 10 + `remark-gfm` + `nh3` (server-side sanitisation). `react-markdown` is safe by default — no `dangerouslySetInnerHTML`. |
| `@xterm/xterm` 6.0.0 (renamed from `xterm`) + `@xterm/addon-fit` | `useRef` + `useEffect` mount; `terminal.dispose()` in cleanup. |
| `@novnc/novnc` 1.7.0 | Same pattern; `RFB.disconnect()` in cleanup. |
| TipTap | `@tiptap/react` + `@mantine/tiptap` (M8 docs plugin). |

## jQuery-plugin → React-equivalent map (historical)

The original PRD §15 jQuery slate is replaced by:

| Old jQuery plugin (out) | React replacement |
|---|---|
| DataTables | `@tanstack/react-table` v8 + Mantine `<Table>` |
| Select2 | Mantine `Select` / `MultiSelect` / `Combobox` |
| Flatpickr | `@mantine/dates` |
| jQuery Validate | Zod v4 schemas via `@mantine/form` or React Hook Form |
| SortableJS | `dnd-kit` |
| jstree | Mantine `Tree` (spike first); fallback `react-arborist` |
| Toastr | `@mantine/notifications` |
| bootbox | `@mantine/modals` |
| blueimp jQuery File Upload | `react-filepond` 7.1.3 with `chunkUploads: true` — see "File uploads" below |

## File uploads

Multi-GB ISOs / OVAs / stack tarballs cannot go through Django multipart — Django never handles those bytes in production. Two-tier protocol:

- **Small / medium files (≤ 50 MB)**: standard Django multipart via FilePond into the artifact backend.
- **Large files (≥ 1 GB)**: FilePond chunked upload protocol (`chunkUploads: true` core option; HEAD + PATCH with `Upload-Offset`/`Upload-Length` headers, tus-style — *not* the S3 multipart protocol; single presigned PUT direct-to-S3 caps at 5 GB). Django is the upload coordinator for every backend: filesystem backend streams chunks via `HttpRequest.read()` (never `request.body` which loads everything into memory) under a per-`UploadSession` advisory lock; S3-compatible backend initialises an `S3 CreateMultipartUpload` on first chunk, calls `UploadPart` per FilePond chunk, and `CompleteMultipartUpload` on finalisation. sha256 is computed during streaming for filesystem; post-upload via `GetObject` for S3.

Upload session invariants:

- Quota check before session creation. (M0 ships the gate-stub form: the session refuses creation if the actor's `Tenant` doesn't exist. Full per-(scope, dimension) quota enforcement lands with the quota framework in M6. Document this so M0 isn't held to a gate that can't yet exist.)
- Server-generated random transfer IDs (UUID4); never trust client-supplied IDs.
- Offset locking + idempotent retry via Postgres advisory locks on `(transfer_id)`.
- TTL cleanup reaper aborts abandoned sessions; for S3 the reaper calls `AbortMultipartUpload`.
- `Artifact.quarantined = true` on insert; scanner pipeline (no-op in M0; ClamAV / `qemu-img info` / format validator from M1+) clears the flag on OK.
- Filename + path sanitisation; storage key derived from `transfer_id`, original filename kept as metadata.
- MIME magic sniffing via `python-magic`; reject mismatches.
- Archive / zip-bomb limits enforced before processing.

Frontend: FilePond via `react-filepond` 7.1.3 (or mount FilePond core directly via `useRef`/`useEffect` if the wrapper hits a React 19 issue) paired with `@mantine/dropzone` for surface chrome.

## Product Style

RackLab should feel like a practical self-service operations portal:

- Dense but readable.
- Fast navigation.
- Clear status.
- Predictable actions.
- No marketing-style landing page as the main app.
- Tables and filters for operational lists.
- Wizards only where they reduce complexity.
- Clear quota and lease indicators.
- Clear deployment state and next action.

## Branding and Theming

RackLab supports operator-configurable branding and theming from day one. The goal is institutional identity (RIT Cyberlab, departmental labs, partner deployments) without forking the codebase or editing templates.

Requirements:

- Logo (light and dark variants), favicon, product name, and login-screen banner/message are operator-configurable via an admin GUI.
- Primary brand color and semantic accents (success/warning/danger/info) are configurable with live preview.
- Light and dark themes ship by default. Per-user toggle plus per-deployment default.
- Email template branding (sender display name, header logo) follows the same configuration.
- "Reset to defaults" is a single action.
- Custom CSS injection is supported as an advanced option, gated behind an explicit admin permission and audit-logged.
- Themes can be packaged as plugins (see plugin system) so an institution can ship its theme as a versioned, installable artifact rather than a config blob.
- All branding/theming changes are audit-logged.

## Key Screens

Student:

- Dashboard.
- Catalog.
- New deployment.
- Project detail.
- Deployment detail.
- Console view.
- Quota view.
- Sharing view.
- Script library.

Instructor:

- Course dashboard.
- Roster deployment.
- Stack wizard.
- Catalog publishing.
- Student deployment management.
- Script approval.

Admin:

- Provider inventory.
- Network offerings.
- Quota policies.
- Plugin management.
- Audit search.
- Worker health.
- System settings.
- Branding and theme settings.

## Live Updates

SSE powers live status for deployment timelines, scripts, worker health, provider health, approvals, and quotas. React islands consume SSE via the browser-native `EventSource` API wrapped in a TanStack Query subscription; SSE persistence + `Last-Event-ID` replay semantics per PRD §7. Per-stream RBAC scope check applies before any event is sent.

## Accessibility

WCAG 2.2 AA across the platform; AAA on critical flows (deployment status, console launch, share-link acceptance). ARIA APG. Section 508 / EN 301 549.

Implementation:

- **Mantine ARIA strategy**: Mantine is *generally* WAI-ARIA-compliant but is not "accessible by default" the way Radix is — usage discipline plus CI a11y gates verify per-component compliance. When axe-core / pa11y / Storybook a11y addon flags a Mantine component, drop in the equivalent `@radix-ui/react-*` primitive styled with Mantine.
- **CI gates**: axe-core in Playwright E2E + `vitest-axe` in unit tests + Storybook a11y addon in component dev + pa11y on critical flows. Build fails on any new violation.
- **Manual screen-reader pass**: NVDA, VoiceOver, Orca before promotion.
- **Semantic HTML first**; ARIA only where semantic HTML cannot express intent.
- **All interactive elements keyboard-reachable**; visible focus indicators per WCAG 2.4.7.
- **Color contrast** per WCAG 2.2 1.4.3 (AA minimum, AAA on console UI + status indicators).
- **Color never the sole channel** for status, errors, or required-field signaling.
- **200% browser zoom** reflows without horizontal scrolling on the main flow.
- **High-contrast theme** variant beyond light/dark.
- **Form inputs** all have associated `<label>`; errors via `aria-describedby` + submit-time error summaries in a single live region.
- **SSE live updates** use ARIA live regions (`polite` for status changes, `assertive` only for failures requiring action).
- **Console embedding**: keyboard shortcut to release focus, screen-reader announcement of session state, text-mode fallback documented.
- **Skip links** to main content, primary nav, and any in-page filter region.
- **Modals trap focus**, return focus on close, escape-dismissable.
- **Page titles update** on island navigation via `document.title` + `history.pushState`; `aria-current` reflects active navigation.
- **Animations respect `prefers-reduced-motion`**.
- **Accessibility statement** is a first-class admin page.

## Internationalization

RackLab is internationalized from day one. **LinguiJS v6** is the canonical catalog tool — its native PO format is shared with Django's `gettext`.

Requirements:

- All user-facing strings (Django templates, React components, API error messages, audit-event human descriptions, email templates, console UI, admin pages) are wrapped in translation calls. No bare user-facing English literals in templates, views, serializers, React components, or worker outputs.
- Translation catalogs live in `locale/<lang>/LC_MESSAGES/`. Django uses `django.po`; React uses `react.po`. The two `domain`s prevent extract-pass collision; both are real gettext `.po` files Lingui and Django read directly.
- `@lingui/vite-plugin` integrates the React extract/compile pass into the Vite build.
- Each user has a per-account locale preference; falls back to `Accept-Language`, then to the deployment-default locale.
- Date, time, number, and currency formatting respect the active locale.
- CLDR plural forms via Django gettext + Lingui (singular/plural at minimum). Full ICU MessageFormat is **not** in scope for v1.
- RTL languages (Arabic, Hebrew) supported end-to-end: layout flips, bidi-aware text rendering, mirrored icons where directional.
- Default ships with en-US populated; additional locales community-contributable.
- Translatable strings include extractor comments where context is non-obvious.
- Plugin authors ship their own React + Django catalogs; RackLab merges them on plugin install/enable.
- Translation-coverage admin page shows per-locale stats.

## Asset pipeline + linting + CSP

- Vite manifest discovery via `django-vite` 3.1.
- CSP `script-src 'nonce-...'` policy; Vite chunks load via `<script type="module">` with the nonce.
- `style-src` allowance for Mantine's CSS-in-JS injected `<style>` tags (`'nonce-...'` or hash-based).
- HMR `connect-src` allowance in dev only.
- ESLint + `eslint-plugin-jsx-a11y` + `eslint-plugin-react` + `eslint-plugin-react-hooks` + Prettier as a pre-commit hook for the React tree.
- Stylelint for any CSS authored by hand.

## Plugin-shipped React islands

Plugins that contribute UI ship their own Vite-built bundles registered into the central Vite manifest. Per-plugin CI hooks: ESLint a11y plugin, axe-core in Storybook, Vitest + RTL. Plugin islands enforce tenant-aware data fetches and never render cross-tenant data unless the actor's binding scope explicitly allows it.

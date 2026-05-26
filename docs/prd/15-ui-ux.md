# UI And UX

> **Note:** This section was rewritten for the May 2026 Laravel redesign. The previous version described a Django + React islands + Mantine + Radix + LinguiJS stack; the new stack is Blade + Livewire 4 + Filament 5 + Tailwind v4 + daisyUI 5. Implementation detail for the UI stack choices below (Livewire/Filament/Tailwind versions, daisyUI integration, vanilla JS island compilation, accessibility tooling) lives in `docs/superpowers/specs/2026-05-26-laravel-redesign.md` §2 and §4. This document captures the UX requirements and accessibility/i18n commitments; the spec is the source of truth for the libraries that implement them.

RackLab uses **Blade + Livewire 4 components** for the public UI (server-rendered, reactive over the wire) and **Filament 5** for the admin panel. Tailwind v4 + daisyUI 5 handle public-facing styles; Filament 5 ships its own Tailwind v4-based vendor styles for the admin panel. Vanilla JS islands (`@xterm/xterm`, `@novnc/novnc`, Chart.js, FilePond, TipTap) are mounted by Livewire components via `wire:ignore` + `@push('scripts')` — no React.

## UI Architecture

The frontend uses **Blade + Livewire 4** for all public-facing interactive surfaces. Livewire handles server state over the wire; Alpine.js (bundled with Livewire) handles lightweight client-side behavior. The admin panel is **Filament 5** from day one — there is no stock admin phase.

Requirements:

- **No SPA as the default**; Livewire component architecture. Each interactive surface is a Livewire 4 single-file component on a Blade-rendered page.
- **Blade templates for full pages.** Pages include layout, auth chrome, navigation, and Livewire component mount points.
- **Vite** compiles two separate CSS entries: `resources/css/app.css` (Tailwind v4 + `@plugin "daisyui"`, public UI) and `resources/css/filament.css` (Filament 5 vendor CSS, admin). Vanilla JS islands compile from `resources/js/islands/`.
- **Tailwind v4 + daisyUI 5** as the public component layer. Semantic tokens from daisyUI's theme system drive branding overrides.
- **Filament 5** as the admin panel. Filament Resources, Pages, Widgets, and Plugin panels replace any bespoke admin UI. Filament's built-in `RichEditor` (TipTap-based) is used for the docs plugin admin surfaces.
- **TypeScript only for vanilla JS islands** (`xterm-console.ts`, `novnc-viewer.ts`, `chart-board.ts`, `filepond-uploader.ts`, `tiptap-editor.ts`). No TypeScript outside of islands. No React anywhere in the stack.
- **Server state**: Livewire 4 handles all reactive server state over WebSocket/HTTP. No separate client-side query library.
- **Client state**: Alpine.js (bundled) for lightweight UI state (toggle panels, transient form state, dropdown open/close).
- **Forms**: Laravel `FormRequest` classes for validation; `spatie/laravel-data` for server-side typed payloads. Livewire form objects for the component-layer form binding.
- **Tables**: Filament `Table` component (admin); daisyUI + Livewire components for public-facing tables.
- **Routing**: server-driven by Laravel (full-page navigation via `wire:navigate` for SPA-feel transitions). Intra-page navigation (tabs, modals) via Livewire + Alpine.js.
- **HTMX is out.** No Vue/Svelte/Solid. No React. No separate SPA framework.

## Operator admin UI

**Filament 5** is the admin panel from day one. It replaces the Django admin pattern entirely. Filament Resources, Pages, and custom Widgets cover all admin screens listed under Key Screens below. The **M10a milestone** — equivalent to the original custom shell milestone — instead rewrites the **public** UI component library on top of daisyUI 5, not the admin panel.

## Vanilla JS islands (mounted via `wire:ignore`)

Islands are compiled TypeScript files under `resources/js/islands/`. Each island is mounted by its wrapping Livewire component via `wire:ignore` on the container element and `@push('scripts')` for the compiled bundle. Livewire dispatches browser events into and out of the island for integration.

| Library | Island file | Mount strategy |
|---|---|---|
| Chart.js 4.x | `chart-board.ts` | `wire:ignore` container; accessibility lives in a Livewire `<AccessibleChart>` Blade component that wraps the canvas with `aria-label` + an offscreen `<table>` summary. |
| Cytoscape.js | `cytoscape-graph.ts` | `wire:ignore` container |
| `@xterm/xterm` 6.0.0 + `@xterm/addon-fit` | `xterm-console.ts` | `wire:ignore` container; `terminal.dispose()` on Livewire component destroy. |
| `@novnc/novnc` 1.7.0 | `novnc-viewer.ts` | Same pattern; `RFB.disconnect()` on destroy. |
| `@tiptap/core` (vanilla) | `tiptap-editor.ts` | `wire:ignore` on the editor container in public Livewire components. Filament admin uses Filament's built-in `RichEditor`. |
| FilePond 4.x | `filepond-uploader.ts` | `spatie/livewire-filepond` bridge; see "File uploads" below. |

## jQuery-plugin → Livewire/daisyUI/Filament replacement map (historical)

The original PRD §15 jQuery slate was replaced first by React equivalents, and now by Livewire + daisyUI + Filament equivalents:

| Old jQuery plugin (out) | New replacement |
|---|---|
| DataTables | Filament `Table` (admin); Livewire + daisyUI `table` component (public) |
| Select2 | daisyUI `select` + Livewire `wire:model` (public); Filament `Select` (admin) |
| Flatpickr | daisyUI `input[type=date]` + browser native or a lightweight vanilla date picker island (public); Filament `DatePicker` (admin) |
| jQuery Validate | Laravel `FormRequest` + `spatie/laravel-data` (server); Alpine.js + Livewire errors (client) |
| SortableJS | SortableJS vanilla island or `wire:sortable` (Livewire community package) |
| jstree | Filament `Tree` resource (admin); Livewire component with daisyUI `menu` tree markup (public) |
| Toastr | daisyUI `toast` + Alpine.js (public); Filament notifications (admin) |
| bootbox | daisyUI `modal` + Alpine.js (public); Filament action modals (admin) |
| blueimp jQuery File Upload | `filepond` 4.x via `spatie/livewire-filepond` — see "File uploads" below |

## File uploads

Multi-GB ISOs / OVAs / stack tarballs cannot go through standard multipart — the application server never handles those bytes in production. Two-tier protocol:

- **Small / medium files (≤ 50 MB)**: standard multipart via FilePond into the artifact backend.
- **Large files (≥ 1 GB)**: FilePond chunked upload protocol (`chunkUploads: true` core option; HEAD + PATCH with `Upload-Offset`/`Upload-Length` headers, tus-style — *not* the S3 multipart protocol; single presigned PUT direct-to-S3 caps at 5 GB). Laravel is the upload coordinator for every backend: filesystem backend streams chunks via `fread()` on the incoming stream (never buffering the entire body) under a per-`UploadSession` advisory lock; S3-compatible backend initialises an `S3 CreateMultipartUpload` on first chunk, calls `UploadPart` per FilePond chunk, and `CompleteMultipartUpload` on finalisation. sha256 is computed during streaming for filesystem; post-upload via `GetObject` for S3.

Upload session invariants:

- Quota check before session creation. (M0 ships the gate-stub form: the session refuses creation if the actor's `Tenant` doesn't exist. Full per-(scope, dimension) quota enforcement lands with the quota framework in M6. Document this so M0 isn't held to a gate that can't yet exist.)
- Server-generated random transfer IDs (UUID4); never trust client-supplied IDs.
- Offset locking + idempotent retry via Postgres advisory locks on `(transfer_id)`.
- TTL cleanup reaper aborts abandoned sessions; for S3 the reaper calls `AbortMultipartUpload`.
- `Artifact.quarantined = true` on insert; scanner pipeline (no-op in M0; ClamAV / `qemu-img info` / format validator from M1+) clears the flag on OK.
- Filename + path sanitisation; storage key derived from `transfer_id`, original filename kept as metadata.
- MIME magic sniffing; reject mismatches.
- Archive / zip-bomb limits enforced before processing.

Frontend: `filepond` 4.x via `spatie/livewire-filepond` bridge. The FilePond surface chrome uses daisyUI styling via the custom CSS class API. Admin upload surfaces use Filament's `FileUpload` field (which wraps FilePond).

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
- Primary brand color and semantic accents (success/warning/danger/info) are configurable with live preview. daisyUI 5 theme tokens are the mechanism for public UI; Filament panel colors are configured via `$panel->colors([...])` for the admin panel.
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

Admin (Filament 5 panel):

- Provider inventory.
- Network offerings.
- Quota policies.
- Plugin management.
- Audit search.
- Worker health.
- System settings.
- Branding and theme settings.

## Live Updates

Laravel Reverb (WebSockets, Pusher protocol) + Laravel Echo client + Livewire 4 broadcasting power live status for deployment timelines, scripts, worker health, provider health, approvals, and quotas. Livewire components subscribe to broadcast channels via `#[On('deployment.{id}.updated')]` listeners; Echo handles the WebSocket transport. Replay semantics (`Last-Event-ID` equivalent) are backed by the `broadcast_event_log` Postgres table — see the spec §7 and PRD §7. Per-channel RBAC scope check applies (channel auth in `channels.php`) before any event is sent.

## Accessibility

WCAG 2.2 AA across the platform; AAA on critical flows (deployment status, console launch, share-link acceptance). ARIA APG. Section 508 / EN 301 549.

Implementation:

- **daisyUI + Livewire accessibility strategy**: daisyUI components are semantic HTML with ARIA baked in. Livewire's reactive rendering must not break focus — `wire:key` discipline ensures stable DOM identity across re-renders. CI a11y gates verify per-component compliance. Where daisyUI components are insufficient, drop to a lightweight headless component or manually authored ARIA markup.
- **CI gates**: axe-core in Laravel Dusk E2E (the primary a11y gate; covers all rendered pages including island content) + pa11y on critical flows + manual review of Blade/Livewire templates for ARIA correctness. (No JSX-specific a11y linter applies — the islands are vanilla DOM TypeScript, not React; axe-core covers what `eslint-plugin-jsx-a11y` would on a React stack.) Build fails on any new violation.
- **Manual screen-reader pass**: NVDA, VoiceOver, Orca before promotion.
- **Semantic HTML first**; ARIA only where semantic HTML cannot express intent.
- **All interactive elements keyboard-reachable**; visible focus indicators per WCAG 2.4.7.
- **Color contrast** per WCAG 2.2 1.4.3 (AA minimum, AAA on console UI + status indicators).
- **Color never the sole channel** for status, errors, or required-field signaling.
- **200% browser zoom** reflows without horizontal scrolling on the main flow.
- **High-contrast theme** variant beyond light/dark.
- **Form inputs** all have associated `<label>`; errors via `aria-describedby` + submit-time error summaries in a single live region.
- **Live updates** use ARIA live regions (`polite` for status changes, `assertive` only for failures requiring action).
- **Console embedding**: keyboard shortcut to release focus, screen-reader announcement of session state, text-mode fallback documented.
- **Skip links** to main content, primary nav, and any in-page filter region.
- **Modals trap focus**, return focus on close, escape-dismissable.
- **Page titles update** on `wire:navigate` transitions via `<title>` in Blade layouts; `aria-current` reflects active navigation.
- **Animations respect `prefers-reduced-motion`**.
- **Accessibility statement** is a first-class admin page.

## Internationalization

RackLab is internationalized from day one. **Laravel's built-in i18n** (`resources/lang/*`) is the canonical catalog tool. A catalog-drift CI gate (RackLab-custom artisan command, working name `php artisan racklab:lang:check`, or equivalent via a community package such as `amir9480/laravel-translations-status`) flags missing or unused keys; build fails on drift. The exact command lands as part of the `ci-gates` sub-plan from the redesign spec §10.

Requirements:

- All user-facing strings (Blade templates, Livewire components, API error messages, audit-event human descriptions, email templates, console UI, admin pages) are wrapped in translation calls (`__(...)`, `trans(...)`, `@lang(...)`). No bare user-facing English literals in templates, controllers, Livewire components, or worker outputs.
- Translation catalogs live in `resources/lang/<lang>/`. Laravel's PHP-array format is the primary form; JSON catalogs for front-end strings passed to Alpine.js islands or JS config. Plugin authors ship their own `resources/lang/` catalogs within their package.
- The catalog-drift gate (`php artisan racklab:lang:check` or equivalent — see the i18n section header) runs in CI to detect missing or orphaned keys. Catalog drift breaks the build.
- Each user has a per-account locale preference; falls back to `Accept-Language`, then to the deployment-default locale (`APP_LOCALE`).
- Date, time, number, and currency formatting respect the active locale via Laravel's locale-aware helpers and JavaScript `Intl` in islands.
- Plural forms: `trans_choice()` (Laravel's built-in pluralization) handles singular/plural for English correctly. For non-English locales with multiple CLDR plural categories (Arabic has six, Russian has three, Welsh has six, etc.), Laravel's built-in pluralization is **not** CLDR-compliant — full coverage requires a community package layered on top, e.g. `symfony/translation` integrated via `mcamara/laravel-localization` or `spatie/laravel-translatable`. Choosing and integrating the CLDR plural package is part of the M10b-equivalent i18n-hardening milestone; v1 ships en-US only and stubs the API for additional locales. Full ICU MessageFormat is **not** in scope for v1.
- RTL languages (Arabic, Hebrew) supported end-to-end: layout flips, bidi-aware text rendering, mirrored icons where directional.
- Default ships with en-US populated; additional locales community-contributable.
- Translatable strings include extractor comments where context is non-obvious.
- Plugin authors ship their own language files; RackLab merges them on plugin install/enable via the plugin service provider.
- Translation-coverage admin page shows per-locale stats.

## Asset pipeline + linting + CSP

- Vite compiles `resources/css/app.css` (public Tailwind v4 + daisyUI) and `resources/css/filament.css` (Filament vendor) as separate bundles. Island TypeScript compiles from `resources/js/islands/`.
- CSP `script-src 'nonce-...'` policy; Vite chunks load via `<script type="module">` with the nonce injected by Laravel's middleware.
- `style-src` allowance for compiled CSS served from the Vite manifest.
- HMR `connect-src` allowance in dev only.
- ESLint + `eslint-plugin-jsx-a11y` + Prettier as a pre-commit hook for the island TypeScript files.
- Stylelint for any CSS authored by hand outside of Tailwind/daisyUI utilities.

## Plugin-shipped UI contributions

Plugins that contribute UI ship their own Livewire components, Blade views, Filament Resources/Pages/Widgets, and vanilla JS island files. Per-plugin CI hooks: ESLint a11y plugin, axe-core in Dusk, Pest browser tests. Plugin components enforce tenant-aware data access and never render cross-tenant data unless the actor's binding scope explicitly allows it.

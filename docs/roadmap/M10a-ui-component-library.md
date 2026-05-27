# M10a — Public UI Component Library (Livewire 4 + daisyUI 5)

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M1 through M9 (including M5a/M5b/M5c and M7a/M7b).
**Unblocks:** M10b.

## Goal

**Scope:** Filament 5 handles the entire admin shell from M00 — admin screens (provider inventory, quota policies, plugin management, audit search, system settings, branding) are Filament panels, not custom-built. M10a covers the **public-facing UI only**: the Livewire 4 + daisyUI 5 component library used by students and instructors. Admin branding and theme work stays in Filament's built-in theme customisation.

Every Key Screen from PRD §15 that is not an admin panel exists as a Livewire 4 component styled with daisyUI 5 primitives and Tailwind v4. The component palette is exercised by Pest 4 integration tests (Dusk for browser-layer flows). Laravel's built-in i18n (`resources/lang/en/`) is wired up for every translatable string surfaced by M0–M9 and the catalog-drift CI gate is green. The release gates on a11y + i18n hardening (full WCAG 2.2 AA pass, second locale, RTL verification, manual screen-reader runs) land in M10b.

## In scope

- PRD §15 UI/UX — every Key Screen owned by students and instructors, the Product Style guidelines, and the public branding surface (login banner, product name, logo — served by a Livewire settings component, not Filament).
- PRD §03 Users and Personas — every student and instructor persona's listed needs surfaced in Livewire components.
- The remaining audit-event surfaces that need admin search/filter/export per PRD §14 — *these are Filament panel screens, out of scope here*; the public-facing view of a user's own audit trail is in scope.

## Dependencies

- M0 Livewire 4 + daisyUI 5 toolchain skeleton (Vite entries, Tailwind v4.1+ config, Alpine.js, Pest 4, Dusk baseline).
- M1 baseline branding data model — M10a builds the public branding component on top.
- M2–M9 — the underlying features, including the M5c VPNaaS plugin UI. M10a is the polish + completeness pass for the public UI on top of them.

## Deliverables

- Every PRD §15 Key Screen for students and instructors exists as a Livewire 4 component mounted in a Laravel Blade layout:
  - Student: Dashboard, Catalog, New deployment, Project detail, Deployment detail, Console view, Quota view, Sharing view, Script library.
  - Instructor: Course dashboard, Roster deployment, Stack wizard, Catalog publishing, Student deployment management, Script approval.
- Shared component palette (Livewire 4 + daisyUI 5 primitives), each extractable and independently testable:
  - Layout: `<x-rl-header>`, `<x-rl-sidebar>`, `<x-rl-page-shell>`
  - Data: `<x-rl-data-table>` (sortable, filterable, paginated via Livewire), `<x-rl-badge>`, `<x-rl-status-chip>`
  - Interaction: `<x-rl-modal>`, `<x-rl-toast>` (toast stack via Alpine.js + daisyUI `alert`), `<x-rl-confirm-dialog>`
  - Forms: `<x-rl-form-control>` (label + input + error slot), `<x-rl-select>`, `<x-rl-checkbox-group>`, `<x-rl-filepond>` (wraps spatie/livewire-filepond)
  - Heavy JS islands: `<x-rl-xterm>` (wraps `@xterm/xterm` 6.x), `<x-rl-novnc>` (wraps `@novnc/novnc` 1.7.x) — both mounted via `@entangle` + Alpine.js lifecycle.
- Public branding surface (student/instructor-facing):
  - Logo (light + dark variants), product name, login-screen banner/message.
  - Served from `BrandingSettings` (spatie/laravel-settings) and cached; invalidated on admin change.
  - Light/dark theme toggle per-user stored in `UserPreferences`; cookie fallback for guests.
- Laravel i18n wiring:
  - `resources/lang/en/` catalog complete for every translatable string in every Livewire component surfaced by M0–M9 (including M5c and plugin-published translation files).
  - Custom artisan command `racklab:lang:check` (working name) detects untranslated strings by diffing `trans()` call sites against the `en/` catalog; catalog drift fails CI.
  - Plugin authors publish their translation files to `resources/lang/vendor/<plugin>/en/`; the `racklab:lang:check` command covers vendor catalogs.

## Acceptance criteria

- [ ] Every Key Screen listed above exists as a Livewire 4 component; each renders correctly in dev and production build; each is navigable by keyboard alone at the component level (full a11y pass lands in M10b).
- [ ] The shared component palette (Header, Sidebar, DataTable, Modal, Toast, FormControl) is exercised by at least one Pest 4 Livewire feature test per component verifying render, interaction, and Livewire wire events.
- [ ] A student can browse the catalog, launch a deployment, and reach the console view without leaving the Livewire component tree; each step renders with correct daisyUI styling and no JavaScript console errors.
- [ ] An instructor can publish a catalog item and manage a student deployment through the Livewire UI; RBAC enforcement is verified in Pest 4 tests.
- [ ] An admin sets a custom logo and login-screen banner via the Filament panel; the public-facing login page reflects the change without a server restart.
- [ ] A user toggles to dark theme; the preference persists across page loads (cookie + DB); daisyUI `data-theme` attribute flips correctly.
- [ ] The `racklab:lang:check` artisan command exits 0 with a complete `en/` catalog and exits 1 when a `trans()` call is added without a corresponding translation key.
- [ ] Pest 4 + Pint + Larastan (PHPStan max level) all pass green for every new Livewire component and Blade template.

## Test layers

- **Unit (Pest 4 tiny)**: Livewire component unit tests (render, Livewire action dispatch, wire model binding); daisyUI theme-resolution logic; CSS-injection validator on the branding surface (rejects `<style>` injection via product-name field).
- **Feature (Pest 4 integration)**: full Livewire feature tests per Key Screen using `Livewire::test()`; RBAC enforcement (students cannot reach instructor screens); `racklab:lang:check` command with fixtures.
- **Browser (Dusk)**: every named student and instructor user journey from PRD §17 renders end-to-end with no console errors; dark-theme toggle persists; full a11y + screen-reader runs land in M10b.

## Risks / open questions

- **daisyUI 5 ARIA coverage**: daisyUI 5 components are HTML + CSS; accessibility depends on the Blade/Livewire markup. M10a treats axe-core findings (run in Dusk) as blocking for any new component; M10b is the systematic hardening pass.
- **Translation catalog scope for plugins**: M0 set up the `resources/lang/` pipeline; M7a/M7b/M8/M9 plugins ship vendor translation files; M10a verifies they all pass `racklab:lang:check`. If a plugin's key conflicts with core, core wins; document in plugin authoring guide.
- **Custom branding security**: the product-name and banner fields allow limited HTML; validate against an allowlist (no `<script>`, no `on*` attributes, no `expression()`-style CSS) and audit-log every change.
- **Heavy JS island lifecycle in Livewire**: `@xterm/xterm` and `@novnc/novnc` mount via Alpine.js `x-init` + `x-destroy` lifecycle hooks and communicate via `@entangle` for state (connection status, resize); test that Livewire morphdom updates do not tear down the terminal.

## Out of scope (deferred to M10b or Filament)

- Admin panel screens (provider inventory, quota policies, plugin management, worker health, audit search, system settings) — these are Filament 5 panels, built in M0/M1/M2–M9 as features land, not in M10a.
- Full WCAG 2.2 AA pass (axe-core CI gate fails the build on any new violation across all Dusk flows).
- Manual screen-reader runs (NVDA, VoiceOver, Orca).
- High-contrast theme variant beyond default light/dark.
- 200% browser zoom verification across every Key Screen.
- Second locale (es-ES) at 100% coverage.
- RTL verification via Arabic.
- AAA on critical flows (deployment status, console launch, share-link acceptance).

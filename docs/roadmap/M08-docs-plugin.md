# M8 — Docs Plugin

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M0 React-island toolchain skeleton + M1 (auth, RBAC, share-link primitive). The frontend asset pipeline (Vite + django-vite + Mantine + LinguiJS) lands in M0; M8 mounts the docs editor as a React island via `@tiptap/react` + `@mantine/tiptap`. No M4 dependency — earlier draft conflated the Proxmox-console asset pipeline with this one.
**Unblocks:** SSH plugin (M9) reuses the plugin-contract demonstration patterns established here.

## Required spike before this milestone

**TipTap Markdown round-trip fidelity for the custom `racklabRef` node.** TipTap is at v3.x stable (since July 2025) but the official Markdown extension is still marked **Beta** upstream with documented edge cases around nested lists, code blocks, and custom nodes. Before M8 commits to "Markdown is the persisted truth + HTML cache regenerated on save," run a focused spike:

- A spike repo / branch with a stock Tiptap 3.x editor + the Markdown extension + a stub `racklabRef` custom node.
- Round-trip a representative corpus: Markdown → Tiptap nodes → back to Markdown. Asserts byte-equality (or documented diff) for: nested lists, code blocks with language hints, links + images, the `racklabRef` node's `[[kind:id]]` source syntax.
- If round-trip fails for important shapes, the spike output decides whether to (a) freeze on a Markdown subset, (b) store Tiptap JSON as the source of truth with Markdown export as derived, (c) wait for the Markdown extension's stable release.
- Spike deliverable: a one-page memo committed alongside this milestone before M8 implementation starts.

## Goal

The `racklab-docs` plugin lands — a built-in document store for guides, how-tos, runbooks, and lab instructions. Beyond the user-facing feature, this milestone is the **first deliberate showcase of the full plugin contract**: entry-point discovery, capability declaration, RBAC contributions, audit emission, artifact-storage integration, *and* defining its own extension point (the `racklab_docs_ref_resolver` hookspec) that other plugins extend. Cross-linking to RackLab objects via `[[kind:id]]` syntax is the visible differentiator.

## In scope

- PRD §22 docs plugin — every section.
- PRD §13 plugin contract (this milestone exercises every clause).
- PRD §19 `Doc` / `DocVersion` / `DocImage` tables.
- TipTap (ProseMirror) mounted as a React island via `@tiptap/react` + `@mantine/tiptap` per PRD §15 + §22.

## Dependencies

- M1 RBAC + share-link primitive (reused for doc sharing — no parallel sharing model).
- M0 React-island toolchain — Vite + django-vite + Mantine + LinguiJS + Storybook + Vitest + axe-core CI hook all in place.
- M0 universal `Artifact` model — doc images land here.

## Deliverables

- `racklab-docs` plugin package on PyPI: registers entry point in `racklab.plugins`, declares `capability docs:v1`, ships migrations for `Doc` / `DocVersion` / `DocImage`, declares the six permissions, declares the health check, registers translation catalogs, contributes admin pages.
- TipTap editor wired as a React island via `@tiptap/react` + `@mantine/tiptap`: StarterKit + Image + ImageUploadNode + Link + the custom `racklabRef` Node for `[[kind:id]]` cross-links. Bundled via Vite from `@tiptap/*` packages on npm; no CDN.
- Markdown source of truth + HTML cache: Markdown is the persisted truth; the HTML cache is regenerated on save by a server-side markdown renderer (marked equivalent in Python: `markdown` + bleach for the sanitization contract per PRD §15).
- `racklab_docs_ref_resolver` pluggy hookspec: the docs plugin **defines** this hookspec and other plugins **implement** it. Core RackLab ships resolvers for `deployment`, `project`, `course`, `network`, `script`, `plugin`.
- Cross-link resolver endpoint at `/plugins/docs/refs/resolve/{kind}/{id}/`: consumed by a React component via TanStack Query `useQuery` (per PRD §22), returns `{label, url, status, rbac_visible}`. RBAC-filtered; sampled/aggregated audit per the codex P2 note.
- Image upload endpoint: routes through Django to the artifact-storage backend; stores `Artifact(kind=docs_image)` rows; signed download URLs are RBAC-checked.
- Postgres `tsvector`-based full-text search scoped to documents the requesting user can read.
- "Related docs" contextual panel on resource detail pages (deployment / project / course) showing documents that cross-link to the current resource.

## Acceptance criteria

- [ ] `racklab plugin install racklab-docs && racklab plugin migrate racklab-docs && racklab plugin enable racklab-docs` succeeds; the plugin's health check reports OK; docs surface appears in the UI.
- [ ] An instructor creates a document, embeds an image (uploaded), inserts `[[deployment:abc-123]]`, saves; the rendered page shows the document with the image and a live-polling deployment status pill that resolves the deployment label + status from the resolver endpoint.
- [ ] A student who has access to the document but not to `deployment:abc-123` sees a "redacted reference" pill instead of the status; the audit log records the redacted resolution with sampling per the codex P2 note.
- [ ] A provider plugin contributes a resolver for `kind=cluster` via the `racklab_docs_ref_resolver` hookspec; documents can `[[cluster:xyz]]` and the new pill type works.
- [ ] Disabling the docs plugin removes the navigation entry and the docs admin page but leaves the data in Postgres; re-enabling restores the surface instantly.
- [ ] Rolling back the docs plugin's migrations works end-to-end; CI's plugin-lifecycle integration test verifies the round trip.
- [ ] Full-text search across documents respects RBAC; results never surface a document the requester can't read.
- [ ] axe-core finds no new violations on the docs editor or reader pages; screen-reader testing confirms the editor's accessibility chrome is usable.

## Test layers

- **Tiny / unit**: `[[kind:id]]` parser + serializer (Markdown ↔ TipTap node round-trip); RBAC redaction logic for refs; full-text-search query sanitization.
- **Contract**: the `racklab_docs_ref_resolver` hookspec against a stub resolver plugin; the plugin against the full PRD §13 contract (entry point + capability + migrations + permissions + audit + i18n + health).
- **Integration**: install/migrate/enable/exercise/disable/rollback/uninstall end-to-end against testcontainers Postgres; image upload + RBAC-checked download; cross-link resolution audit sampling under load.
- **E2E**: instructor writes a doc with a `[[deployment:...]]` ref, student reads it, the status pill polls live, axe-core regression gate stays green.

## Risks / open questions

- **TipTap's Markdown extension is still labeled Beta upstream** (codex's roadmap-review correction): TipTap is v3.x stable since July 2025, but the Markdown extension itself is Beta and has documented edge cases. The required spike above is the mitigation. PRD §22 references the Markdown extension; if the spike forces a different approach, update PRD §22 alongside the spike memo.
- **Cross-link audit volume**: codex's P2 note flagged that polling-based audit would be high-volume noise. The sampled/aggregated approach is the design; tune the sampling rate against expected doc-page-views before production.
- **Server-side Markdown rendering library**: PRD §15 names `marked + DOMPurify` (JS) and "Python markdown + bleach" (server) as the pipeline. Pick a specific Python markdown library (`markdown`, `mistletoe`, `cmarkgfm`) with a known-good GFM extension and bleach sanitization config; document.
- **TipTap version pinning**: **3.x is the current stable** (TipTap 3.0 was released July 2025 — the 6.x figure in the previous draft was wrong). Pin to a known-good 3.x patch level and document the upgrade policy. (The previous draft confused TipTap's version with xterm.js's, which is 6.x.)
- **Per-document language**: PRD §22 explicitly out-of-scopes translations of authored document content. If a course needs bilingual docs, instructors write two documents and cross-link them. Confirm this UX is acceptable before M10.

## Out of scope (deferred)

- Real-time collaboration (Yjs) — v1.1 per PRD §22.
- Per-paragraph comments and review workflow — v2.
- Tables in the WYSIWYG — v1.1.
- User mentions and slash commands — v1.1.
- A public reader mode — use guest links (works in M8 because of M1).
- Translation of authored document content — out of scope.
- A dedicated worker pool for docs (background image processing, search indexing) — v1 is sync-only per PRD §22.

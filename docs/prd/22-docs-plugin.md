# Docs Plugin

The `racklab-docs` plugin is RackLab's built-in document store: markdown-source guides, how-tos, runbooks, and lab instructions written by instructors and admins, optionally readable by students, and cross-linkable to live RackLab objects.

It serves two purposes. First, it gives operators and instructors a place to write durable, searchable, RBAC-controlled documentation without standing up a separate wiki. Second, it deliberately exercises the full RackLab plugin contract — entry-point discovery, capability declaration, RBAC contributions, audit emission, artifact storage, and defining its own extension point that other plugins can extend — so the plugin system is validated end-to-end against a real consumer.

## Goals

- A first-class place in the RackLab UI for guides, how-tos, lab instructions, course notes, and operational runbooks.
- Markdown is the canonical storage format. A WYSIWYG editor is provided for authors who prefer it; both editors operate on the same Markdown source.
- Cross-linking to RackLab objects (deployments, projects, networks, scripts, plugins) is a first-class feature, not an afterthought. Links resolve to live, RBAC-filtered status indicators.
- Image uploads go through RackLab's artifact storage.
- Full RBAC integration via the existing share-link primitive — no parallel sharing model.
- The plugin showcases the plugin contract.

## Non-Goals

- Real-time collaborative editing in v1. (Yjs-based Tiptap collaboration is a v2 concern.)
- Per-paragraph commenting and review workflow. (v2.)
- Public unauthenticated reader mode. Public access uses RackLab's guest-link primitive (see `docs/prd/06-auth-rbac-sharing-tokens.md`).
- Replacing institutional wikis (Confluence, BookStack, Outline) at the institution level. RackLab Docs is for content that belongs with the lab platform.

## Editor

The editor is **TipTap** (MIT, ProseMirror-based) mounted as a React component via **`@tiptap/react`** + **`@mantine/tiptap`** for the Mantine-styled toolbar. The editor surface is a React island inside the docs-plugin's Django-rendered page — same Vite manifest, same Mantine theme, same a11y CI gates as the rest of the React-island UI per PRD §15.

Editor capabilities in v1:

- StarterKit (paragraphs, headings, lists, blockquote, code blocks, inline code, bold/italic/strike/underline, links).
- Image upload via a custom `ImageUploadNode` that POSTs to RackLab's artifact-upload endpoint (which goes through the chunked-upload protocol per PRD §15 for large images).
- Markdown source view + WYSIWYG view, switchable per-document.
- Markdown ↔ HTML round-trip. Markdown is the persisted truth; the HTML cache is regenerated on save.
- A custom `racklabRef` Node for cross-linking RackLab objects (see "Cross-Linking" below).

Editor capabilities deferred to v2:

- Tables.
- Mentions of users (`@user`).
- Slash-command menu.
- Real-time collaboration (Yjs).
- Custom embeds beyond `racklabRef`.

## Data Model

`Doc` rows store metadata: id, slug, title, current version id, owner, project (nullable), course (nullable), visibility scope, created/updated timestamps. RBAC scope and sharing reuse the share-link primitive from `docs/prd/06-auth-rbac-sharing-tokens.md`.

`DocVersion` rows store the versioned content: id, doc id, version number, markdown source, html cache, author, created timestamp, editor message ("commit message"). Every edit creates a new `DocVersion`; the doc's `current_version_id` advances on save. Old versions are retained per the doc-retention policy (configurable; default 50 versions or 1 year, whichever is larger).

`DocImage` rows reference uploaded images: id, doc id, content type, size, sha256, artifact-storage key, uploader, created timestamp. Images are stored in RackLab's artifact storage (PRD §14 — filesystem or S3 backend per operator config). The image URL is a signed, RBAC-checked download endpoint.

## Cross-Linking

The differentiating feature. Markdown source contains `[[kind:id]]` references — for example `[[deployment:abc-123]]` or `[[network:lab1-mgmt]]`. These render in the editor as TipTap `racklabRef` Nodes and on the rendered page as live status pills refreshed by a React component consuming the resolver endpoint via TanStack Query.

The flow:

1. Author types `[[deployment:abc-123]]` in the Markdown editor (or uses an editor command to insert it).
2. On render, the markdown processor converts the reference into a `<racklab-ref>` element with `kind="deployment"` and `id="abc-123"`.
3. A small React component (mounted via the central Vite manifest) consumes the resolver endpoint via TanStack Query (`useQuery` against `/plugins/docs/refs/resolve/deployment/abc-123/`, stale-time tuned per-resolver). The component renders the pill with the live state and is a link to the resource. Polling cadence is governed by TanStack Query's `refetchInterval`, with backoff on idle tabs via the Page Visibility API.
4. The pill renders with the live state (e.g., "deployment `abc-123` — running") and is a link to the resource.
5. RBAC: a reader who can't see the referenced object sees "redacted reference" with the kind but no id or status. The audit log records the resolution attempt and its RBAC outcome.

### Resolver Registry

The novel piece. Cross-linking is not hard-coded to a fixed set of object kinds. The docs plugin **defines a pluggy hookspec** that other plugins implement:

```python
@racklab_docs_hookspec
def racklab_docs_ref_resolver(kind: str) -> RefResolver | None:
    """Return a resolver for the given reference kind, or None if not handled."""
```

A `RefResolver` exposes:

```python
class RefResolver(Protocol):
    def label(self, request: Request, id: str) -> str: ...
    def status(self, request: Request, id: str) -> RefStatus: ...
    def url(self, request: Request, id: str) -> str: ...
    def rbac_visible(self, request: Request, id: str) -> bool: ...
```

Core RackLab ships resolvers for `deployment`, `project`, `course`, `network`, `script`, `plugin`. A provider plugin that introduces new first-class objects (e.g., a libvirt provider with `pool` and `domain` concepts) can contribute its own resolvers by registering a hook implementation. The docs plugin becomes a consumer of plugin extensions — demonstrating both sides of the plugin contract in one place.

### Cross-link audit

Every cross-link resolution is audit-logged (PRD §14): actor, request id, target kind + id, RBAC outcome (granted/redacted), elapsed time. High-volume noise is suppressed via the audit subsystem's standard dedup.

## RBAC and Sharing

Permissions:

- `docs.view` — read documents the user has been granted access to.
- `docs.create` — create new documents.
- `docs.edit` — edit own or shared documents.
- `docs.publish` — mark a document as published (visible to all in scope).
- `docs.share` — issue share links per the standard RackLab share primitive.
- `docs.admin` — manage all documents and version retention policies.

Sharing reuses the share-link primitive in `docs/prd/06-auth-rbac-sharing-tokens.md` directly. A document can be shared with:

- A user (granted permission level).
- A project (project members at the permission level).
- A course (course roster at the permission level).
- A guest-link token (short-lived, revocable, audited).

There is no parallel sharing model in `racklab-docs`. Anything that needs sharing semantics extends or composes the existing primitives.

## Search

Full-text search is provided via Postgres' `tsvector`-based search on the current version's markdown source (with HTML stripped) and document title, scoped to documents the requesting user is permitted to read. Results return document title, last-updated, and a snippet of the match.

Search results respect RBAC at the document level. Per-paragraph access control is out of scope.

## Layout and UI Placement

The docs plugin contributes a left-sidebar navigation tree (project-grouped) plus a top-level "Docs" entry in the primary navigation. The "corner" placement requested in the original brief is realized as a contextual "Related docs" panel on resource detail pages (deployment detail, project detail, course detail), populated from documents that cross-link to the current resource.

Editor layout follows the standard RackLab Mantine layout primitives (`AppShell`, `Grid`, `Stack` per PRD §15). The editor area is a `<main>` landmark with proper heading hierarchy. ARIA live regions announce save state per PRD §15 accessibility requirements. Per PRD §15 i18n requirements, the UI chrome (buttons, menus, status text) is translatable; document *content* is operator-authored and carries no translation; an instructor who needs documents in two languages writes them as separate documents linked to each other.

## Plugin Contract Compliance

The plugin demonstrates every piece of the contract from PRD §13:

- **Discovery**: registered via a Python entry point in the `racklab.plugins` group.
- **Capability declaration**: declares capability `docs:v1`, supported RackLab API range, the permissions it contributes, the migration set it ships, and its health check (`docs.health` returns "ok" if the database and artifact storage are reachable).
- **Migration shipping**: contributes Django models (`Doc`, `DocVersion`, `DocImage`), migrations land in the plugin's `migrations/` directory and run on `racklab plugin enable racklab-docs`.
- **RBAC contribution**: contributes the six `docs.*` permissions listed above, integrated with allauth and the share-link primitive (no parallel auth path).
- **Audit emission**: emits structured audit events for create, edit, publish, share, ref-resolve, image-upload, version-restore, and version-prune. Events follow the audit schema in PRD §14.
- **Artifact storage integration**: image uploads land in the artifact storage configured per PRD §14 (filesystem or S3). The plugin does not run its own storage.
- **Plugin-as-extension-point**: defines the `racklab_docs_ref_resolver` hookspec, making the docs plugin a host for other plugins' resolvers. This is the rarer half of the contract — most plugins are extenders, not extension points.
- **Failure isolation**: a docs-plugin database connectivity failure degrades to "docs unavailable" with a clear admin alert. It does not break deployment, console, or any non-docs surface.

## Worker Runtime

The plugin does **not** declare any worker pools and does not interact with `WorkerRuntime` (see the Podman orchestration spec). All operations are synchronous Django request handlers. Image-upload size limits and request timeouts are configured at the Django level. This is intentional and a useful negative demonstration of the plugin contract: a plugin does not have to use every extension point.

## Deployment

The plugin ships as a Python package on PyPI: `racklab-docs`. Installation is the standard RackLab plugin path:

```sh
racklab plugin install racklab-docs
racklab plugin enable racklab-docs
```

The plugin runs as part of the main RackLab web container — no separate container, no separate process. The TipTap editor JS is served from RackLab's static-files pipeline (or a CDN configured per deployment).

## Operational Notes

- **Markdown ↔ Tiptap fidelity**: storage is Markdown; the HTML cache is regenerated on save. Edge cases (HTML pasted into the editor, complex nested lists, code-block edge cases) are handled by treating Markdown as truth and accepting that some pasted HTML may round-trip imperfectly.
- **Image storage growth**: images are deduplicated by sha256. The version-prune job (run as a background management command on a schedule, not via a worker pool in v1) reclaims orphaned image rows.
- **Backup**: documents and their version history live in Postgres; images live in artifact storage. The standard RackLab backup story covers both.
- **Performance**: Postgres full-text search is sufficient at expected sizes (thousands of documents, hundreds of users). If a deployment outgrows this, a v2 spec adds a search index (Meilisearch, Typesense) behind the existing search API.

## Out of Scope for v1

- Real-time collaboration (Yjs).
- Per-paragraph comments and review workflow.
- Tables in the WYSIWYG editor.
- User mentions and slash commands.
- A public reader mode (use guest links).
- Per-document fine-grained ACLs beyond what the share-link primitive supports.
- Translations of authored document *content* (the UI chrome is translatable; document text is not RackLab's i18n concern).
- A dedicated worker pool. Docs is sync-only in v1.

## Effort Estimate

Approximately 3-4 engineering weeks for one Django developer familiar with ProseMirror's schema/node-view model. The breakdown:

- Django app skeleton, models, migrations, RBAC permission strings, admin pages — ~3 days.
- TipTap editor wiring in a Django template, Markdown ↔ HTML, image upload to artifact storage, RBAC-gated views — ~4 days.
- Cross-link custom node, resolver registry hookspec, RBAC redaction, audit emission — ~3 days.
- Plugin packaging: entry points, capability + version declaration, health check, migration shipping, install/enable/disable lifecycle — ~3 days.
- React-island search (Mantine `Spotlight` for command-palette UX), navigation tree (Mantine `Tree`), "Related docs" contextual panel, share-link reuse — ~3 days.
- Tests, accessibility audit, i18n string extraction, documentation, polish — ~3 days.

Two-thirds of this scaffolding is required regardless of editor choice; if the team ever decides to swap TipTap for another editor, only the JS layer changes.

## Fallback

If team capacity or scope pressure makes the custom build untenable, the recorded fallback is **BookStack** (MIT, PHP/Laravel) deployed as a sibling Podman container per the Podman orchestration spec, with OIDC pointed at allauth, reverse-proxied under `/wiki/`. In this fallback, cross-linking to RackLab objects degrades to manual URLs (no live status pills) and the plugin-contract showcase value is lost. The fallback is documented because it is operationally well-understood, not because it is preferred.

## Confidence

**High** on the build path being the right call for "showcase the plugin contract."

**High** on TipTap as the editor pick.

**Medium** on the 3-4 week effort estimate — depends on developer familiarity with ProseMirror; could stretch to 5-6 weeks if the schema/node-view model is unfamiliar.

**Medium** on the v2 deferral list. Real users may push tables and comments forward; the v1 discipline is "ship the core surface and the plugin-contract demonstration first."

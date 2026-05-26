# UploadSession + Tenant-Identity Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the M0 deliverable line — "`UploadSession` row is created on session start and refuses creation if the actor has no `Tenant`" — per the roadmap (M00-foundations.md line 69). The model lands per PRD §19 with the full field surface even though only the identity gate fires at M0. M1+ adds the FilePond HEAD/PATCH endpoints, M2 adds the TTL cleanup reaper, M6 layers in the full per-(scope, dimension) quota check.

**Architecture:**

- **`UploadSessionState` enum** — `StrEnum` (`active` | `complete` | `aborted` | `expired`) in `src/racklab/core/states.py` next to `JobState` and `AuditOutboxStatus`.
- **`UploadSession` model** — tenant-scoped with the immutable `tenant_id` + `TenantAwareManager` + force-update bypass guard pattern that landed on `Job` (slice 4) and `Artifact` (slice 9). Fields per PRD §19. `expires_at` defaults to `created_at + 24h` via a module-level callable so it's reproducible in tests + migrations.
- **`UploadSessionGateError`** — subclass of `django.core.exceptions.ValidationError` so callers handle gate rejection uniformly with other domain validation, but `isinstance(exc, UploadSessionGateError)` distinguishes for audit-emission paths that care about gate-rejection counts.
- **`create_upload_session(actor, request)` service** in `src/racklab/core/upload_sessions.py` — refuses if no tenant context, refuses if actor has no `TenantMembership` for the active tenant, otherwise creates the row. The full quota check lands with the quota framework in M6.
- **`UploadSessionRequest`** — frozen dataclass bundling the caller's per-session parameters (artifact_kind, declared filename / mime / size, optional `expected_total`, `client_declared_sha256`, `backend_handle`) so the service signature stays inside ruff's `PLR0913` argument budget without losing per-field type safety.
- **Migration `0012_upload_session`** — `CreateModel` for `UploadSession` only; no backfill (the table is empty at the time of introduction).

**Tech Stack:** Django 5.2 LTS, Python 3.12+, pytest + pytest-django, the existing tenancy primitives (`current_tenant`, `TenantAwareManager`, `TenantMembership`).

---

## Scope boundary

**In scope:**

- `UploadSessionState` enum.
- `UploadSession` model with the PRD §19 field surface + tenant-FK + immutability + `TenantAwareManager`.
- Module-level helper `_default_upload_session_expires_at` (created_at + 24h) wired into the model field default.
- `UploadSessionGateError` exception.
- `UploadSessionRequest` dataclass + `create_upload_session(actor, request)` service.
- Migration `0012_upload_session` (CreateModel only, no backfill needed for an empty table).
- Tiny test for the `UploadSessionState` enum.
- Integration tests: happy path, no-tenant-context refusal, non-member refusal, fail-closed manager, tenant filter, tenant immutability, default `expires_at` in the future.

**Out of scope (deferred to later milestones unless noted):**

- **Per-(scope, dimension) quota check** — lands with the quota framework in M6.
- **FilePond HEAD/PATCH chunked-upload endpoints** — M1+.
- **Filesystem backend chunk receive + S3 multipart coordinator** — M1+.
- **Advisory-lock chunk serialisation** — M2.
- **TTL cleanup reaper** — M2.
- **MIME magic sniff at completion** — M1+.
- **ClamAV / `qemu-img info` / archive-bomb scanner** — M1+.
- **sha256 verification during streaming** — M1+.
- **`upload_session.created` audit event** — emits when the gate-rejection audit event lands; left for the M2 audit-fanout slice.
- **State machine validation** — currently the model just stores the state CharField; M1+ adds per-state transition guards once the upload protocol exists.

## File Structure

- **Modify:** `src/racklab/core/states.py` — add `UploadSessionState` enum.
- **Modify:** `src/racklab/core/models.py` — add `UploadSession` model + `_default_upload_session_expires_at` helper + `DEFAULT_UPLOAD_SESSION_TTL_SECONDS` constant.
- **Create:** `src/racklab/core/upload_sessions.py` — `UploadSessionGateError` + `UploadSessionRequest` + `create_upload_session`.
- **Create:** `src/racklab/core/migrations/0012_upload_session.py` — the migration.
- **Create:** `tests/tiny/test_upload_session_state.py` — enum shape.
- **Create:** `tests/integration/test_upload_session.py` — gate + tenant-aware behavior.

## Implementation tasks

### Task 1: Add `UploadSessionState` enum

Append to `src/racklab/core/states.py` after `AuditOutboxStatus`.

### Task 2: Add `UploadSession` model + helper

`src/racklab/core/models.py`:

- Append `DEFAULT_UPLOAD_SESSION_TTL_SECONDS` + `_default_upload_session_expires_at` module-level after `AuditOutboxEntry`.
- Append the model with the PRD §19 field surface.
- `save()` mirrors `Job.save()`: auto-set tenant from context on insert (raise `ValidationError({"tenant": ...})` if no context AND no explicit tenant), enforce immutability on update, force-update bypass attack covered via `objects.all_tenants().filter(pk=...).values_list("tenant_id")` comparison.
- `from_db` snapshots `_loaded_tenant_id`; `refresh_from_db` conditionally restamps the marker (codex P0 from slice 9 — only restamp when the tenant column itself is in the refreshed-field list).

### Task 3: Create the upload-session service

`src/racklab/core/upload_sessions.py`:

- `UploadSessionGateError(ValidationError)` so callers can still treat it like a Django validation but distinguish via `isinstance`.
- `UploadSessionRequest` — `frozen=True, slots=True, kw_only=True` dataclass.
- `create_upload_session(actor: User, request: UploadSessionRequest) -> UploadSession`:
  1. `tenant_id = get_current_tenant_id()`; if `None` raise `UploadSessionGateError({"tenant": ...})`.
  2. `TenantMembership.objects.filter(user=actor, tenant_id=tenant_id).exists()`; if false raise `UploadSessionGateError({"actor": ...})`.
  3. `UploadSession.objects.create(actor=actor, artifact_kind=..., declared_filename=..., declared_mime=..., declared_size=..., expected_total=request.expected_total or request.declared_size, client_declared_sha256=request.client_declared_sha256, backend_handle=dict(request.backend_handle or {}))`.

### Task 4: Migration

```bash
uv run python manage.py makemigrations core --name upload_session
uv run python manage.py migrate
```

CreateModel only — no backfill needed since the table is brand new.

### Task 5: Tests

- Tiny: enum shape + lowercase string values.
- Integration: happy path; refuse without tenant context; refuse non-member; manager fail-closed; manager filters per tenant; tenant immutability; default `expires_at > created_at`.

### Task 6: Gate stack

```bash
uv run ruff format --check .
uv run ruff check .
uv run mypy
uv run basedpyright
uv run pytest
uv run python manage.py check
uv run bandit -r src/ -q
uv run pip-audit
```

### Task 7: Codex diff review

`codex review --uncommitted` → fold P0/P1 before commit.

### Task 8: Commit + PROGRESS.md update

Two commits per pattern: `feat(core): add UploadSession + tenant-identity gate` then `docs(progress): update for the UploadSession slice`.

## Self-Review

**Spec coverage:**

- ✅ M0 acceptance line 69 satisfied: row created on session start; refuses creation when actor has no `Tenant`.
- ✅ Tenant-scoped per PRD §19: tenant FK + immutable + fail-closed manager.
- ✅ Server-generated UUID4 transfer ID per PRD §18.
- ⚠ Quota gate is identity-only — full per-(scope, dimension) check deferred to M6 (documented in service docstring + PRD §15).
- ⚠ No FilePond endpoints — protocol implementation deferred to M1+ (documented).
- ⚠ No advisory lock / TTL reaper — deferred to M2 (documented).

**Project rules compliance:**

- No `# noqa` / `# type: ignore`.
- Conventional commits, signed.
- Codex review before commit.
- Tenancy primitives reused (no duplicated logic).

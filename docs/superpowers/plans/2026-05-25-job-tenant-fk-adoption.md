# Job Tenant-FK Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make `Job` the first tenant-scoped production model — add the `tenant` FK + immutability invariant from PRD §19, swap `Job.objects` for the `TenantAwareManager` shipped in commit `ed4735c`, register `TenantContextMiddleware` in the production `MIDDLEWARE` setting, and migrate existing rows to the default RIT tenant. This is the slice that makes the contextvar + middleware + manager combo actually load-bearing in production rather than infrastructure-only.

**Architecture:**

- `Job` gains a `tenant` FK (`PROTECT` on delete) → underlying column is `tenant_id` (PRD §19's "denormalized immutable `tenant_id`").
- `Job.save()` auto-sets `tenant_id` from the contextvar on insert if not provided, and enforces immutability on update (matches `tenant_id` against the value loaded from the DB; mismatch raises `ValidationError`).
- `Job.objects` becomes `TenantAwareManager(tenant_field_name="tenant_id")`. Fail-closed — code that touches `Job.objects` outside a tenant context raises `MissingTenantContextError`; cross-tenant code uses `Job.objects.all_tenants()`.
- A new composite index `(tenant_id, state)` supports the "all jobs in tenant X with state Y" query pattern PRD §19 anticipates.
- `TenantContextMiddleware` registered in `racklab.settings.base.MIDDLEWARE` after `AuthenticationMiddleware` — this is the first slice that makes wiring it up sensible because Job is the first model that needs it.
- Existing 3 tests touching `Job` are updated to wrap their work in `with current_tenant(str(tenant.id)):` — explicit and small.

**Tech Stack:** Django 5.2, Python 3.12+, pytest + pytest-django.

---

## Codex review feedback folded (2026-05-25)

Codex returned 0 P0 + 5 P1 + 7 P2 on the draft. The corrected implementation:

- **P1 — `bulk_update()` / `.update()` bypass `save()` and would violate the immutability invariant.** Adding a `TenantAwareQuerySet.update()` override in `tenancy_managers.py` that raises if `tenant` or `tenant_id` appear in the kwargs. Applies to every model using the manager, not just `Job`. New tiny test in `test_tenancy_managers.py` covers it.
- **P1 — Index field-name drift between model and migration.** Standardising on `models.Index(fields=["tenant", "state"])` in both — using the FK field name, idiomatic Django, lets `makemigrations` stay quiet.
- **P1 — Backfill helper concerns.** Helper is migration-only — called via `RunPython` with `apps` arg returning historical model proxies (which still carry the default `Manager`, not `TenantAwareManager`). Live-app callers don't exist; documenting this in the helper's docstring so it stays migration-scoped.
- **P1 — `Job.objects.create(tenant=...)` outside context still raises.** Manager calls `get_queryset()` before the explicit kwargs reach `save()`. Decision: do NOT add a privileged-create escape — workers must always set `current_tenant(...)` before any ORM write (per PRD §19 "Background NATS workers and scheduled commands ... worker handlers re-establish the tenant context at the start of each message"). Service `create_job()` callers therefore need an active context too; documenting this explicitly. Existing service tests already update to wrap in `with current_tenant(...)`.
- **P1 — M0 line 67 ASGI tenant-filter test.** Adding `test_async_view_under_tenant_a_cannot_read_tenant_b_jobs` that uses `AsyncClient` + an async view registered via `override_settings(ROOT_URLCONF=...)` to prove the manager filters through the async stack.
- **P2 — Missing default tenant in backfill = hard error.** Changing `return` to `raise RuntimeError(...)` so the migration fails loudly if 0005's bootstrap somehow didn't seed `rit`.
- **P2 — `refresh_from_db()` should refresh `_loaded_tenant_id`.** Adding override.

## Scope boundary

**In scope:**

- `Job.tenant` FK + DB column.
- `Job.tenant_id` immutability via `save()` override.
- `Job.objects` swap to `TenantAwareManager`.
- Composite index `(tenant_id, state)`.
- Migration `0007_job_tenant_fk`: AddField (nullable=True for backfill) → RunPython backfill → AlterField (nullable=False) + AddIndex.
- Backfill function lives in `src/racklab/core/tenancy_bootstrap.py` (alongside the existing default-tenant bootstrap helpers) so it's importable from migrations + tests.
- Register `TenantContextMiddleware` in `racklab.settings.base.MIDDLEWARE`.
- Update 3 existing Job-using tests in `tests/integration/test_job_services.py` and `tests/integration/test_core_models.py` to wrap operations in `with current_tenant(...)`.
- 5+ new integration tests covering: `Job.objects` requires tenant context, `save()` auto-sets tenant from contextvar, `save()` enforces immutability, `Job.objects` filters by tenant, `Job.objects.all_tenants()` escapes, migration backfill idempotency.

**Out of scope:**

- Same pattern for `Artifact` — separate slice paired with artifact storage work.
- Same pattern for `AuditEvent` — paired with the audit hash-chain extension slice.
- Reservation / Deployment tenant FKs — those models don't exist yet.
- Resolver cross-tenant rules — depends on AuditEvent extension first.
- `@untenanted` CI gate — gets meaningful once 2+ models have adopted.
- DRF / OpenAPI handling of tenant context — M1.

## File Structure

- **Modify:** `src/racklab/core/models.py` — add `tenant` FK + `save()` override + manager swap + index.
- **Modify:** `src/racklab/core/tenancy_bootstrap.py` — add `backfill_job_tenants_forward()` helper.
- **Modify:** `src/racklab/settings/base.py` — append `TenantContextMiddleware` to MIDDLEWARE.
- **Create:** `src/racklab/core/migrations/0007_job_tenant_fk.py` — three-step migration.
- **Modify:** `tests/integration/test_job_services.py` — wrap tests in tenant context.
- **Modify:** `tests/integration/test_core_models.py` — wrap test in tenant context.
- **Create:** `tests/integration/test_job_tenancy.py` — new file with the 5+ adoption tests.

## Implementation tasks

### Task 1: Write the new integration tests (red)

**Files:**

- Create: `tests/integration/test_job_tenancy.py`

```python
"""Integration tests for Job tenant-FK adoption per PRD §19."""

from __future__ import annotations

import pytest
from django.core.exceptions import ValidationError

from racklab.core.models import Job, Tenant
from racklab.core.states import JobKind, JobState
from racklab.core.tenancy_context import current_tenant
from racklab.core.tenancy_managers import MissingTenantContextError


@pytest.fixture
def two_tenants() -> tuple[Tenant, Tenant]:
    """Two tenants for cross-tenant filter tests."""
    return (
        Tenant.objects.create(name="Tenant A", slug="tenant-a"),
        Tenant.objects.create(name="Tenant B", slug="tenant-b"),
    )


@pytest.mark.django_db
@pytest.mark.integration
def test_job_objects_requires_tenant_context() -> None:
    """Job.objects.all() outside a tenant context raises (fail-closed manager)."""
    with pytest.raises(MissingTenantContextError):
        list(Job.objects.all())


@pytest.mark.django_db
@pytest.mark.integration
def test_job_save_auto_sets_tenant_from_contextvar(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Creating a Job under a tenant context records that tenant id."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        job = Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
        )
    assert str(job.tenant_id) == str(tenant_a.id)


@pytest.mark.django_db
@pytest.mark.integration
def test_job_save_requires_tenant_context_on_insert(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Inserting a Job with no contextvar and no explicit tenant raises."""
    with pytest.raises(ValidationError, match="tenant"):
        Job(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
        ).save()


@pytest.mark.django_db
@pytest.mark.integration
def test_job_save_respects_explicit_tenant_assignment(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """An explicit tenant assignment wins over the contextvar at insert time."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        job = Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
            tenant=tenant_b,
        )
    assert str(job.tenant_id) == str(tenant_b.id)


@pytest.mark.django_db
@pytest.mark.integration
def test_job_tenant_id_is_immutable_after_insert(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Mutating tenant_id after insert raises ValidationError."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        job = Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
        )
    job.tenant_id = tenant_b.id
    with pytest.raises(ValidationError, match="immutable"):
        job.save()


@pytest.mark.django_db
@pytest.mark.integration
def test_job_objects_filters_by_tenant(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Job.objects.all() under tenant A returns only A's jobs."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
            correlation_id="job-a",
        )
    with current_tenant(str(tenant_b.id)):
        Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
            correlation_id="job-b",
        )
    with current_tenant(str(tenant_a.id)):
        correlations = list(Job.objects.values_list("correlation_id", flat=True))
    assert correlations == ["job-a"]


@pytest.mark.django_db
@pytest.mark.integration
def test_job_all_tenants_escape_returns_every_row(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Job.objects.all_tenants() bypasses the contextvar filter."""
    tenant_a, tenant_b = two_tenants
    expected = 2
    with current_tenant(str(tenant_a.id)):
        Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
        )
    with current_tenant(str(tenant_b.id)):
        Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
        )
    assert Job.objects.all_tenants().count() == expected


@pytest.mark.django_db
@pytest.mark.integration
def test_job_save_update_within_same_tenant_succeeds(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Saving a Job again with the same tenant is allowed."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        job = Job.objects.create(
            kind=JobKind.PLUGIN.value,
            state=JobState.DISPATCHING.value,
            state_history=[JobState.DISPATCHING.value],
        )
        job.state = JobState.PENDING.value
        job.state_history = [JobState.DISPATCHING.value, JobState.PENDING.value]
        job.save()
    assert job.state == JobState.PENDING.value
```

Run: `uv run pytest tests/integration/test_job_tenancy.py -v` — expect import errors / failures.

### Task 2: Implement the model changes

**Files:**

- Modify: `src/racklab/core/models.py`

Inside the `Job` class, add:

```python
tenant = models.ForeignKey(
    Tenant,
    on_delete=models.PROTECT,
    related_name="jobs",
)
objects = TenantAwareManager(tenant_field_name="tenant_id")
```

Update `Meta.indexes` to include `models.Index(fields=["tenant_id", "state"])`.

Override `__init__` and `save`:

```python
def __init__(self, *args: object, **kwargs: object) -> None:
    super().__init__(*args, **kwargs)
    self._loaded_tenant_id: uuid.UUID | None = (
        cast("uuid.UUID | None", self.tenant_id) if not self._state.adding else None
    )

def save(self, *args: object, **kwargs: object) -> None:
    if self._state.adding:
        if self.tenant_id is None:
            from racklab.core.tenancy_context import get_current_tenant_id

            current = get_current_tenant_id()
            if current is None:
                raise ValidationError(
                    {"tenant": _("Job requires a tenant context or explicit tenant on insert.")},
                )
            self.tenant_id = uuid.UUID(current)
    elif self._loaded_tenant_id is not None and self.tenant_id != self._loaded_tenant_id:
        raise ValidationError(
            {"tenant": _("Job.tenant_id is immutable post-insert.")},
        )
    super().save(*args, **kwargs)
    self._loaded_tenant_id = cast("uuid.UUID | None", self.tenant_id)
```

`from_db` classmethod override so the loaded tenant is captured when Django constructs an instance from a queryset row (not just from `__init__`):

```python
@classmethod
def from_db(cls, db, field_names, values):
    instance = super().from_db(db, field_names, values)
    instance._loaded_tenant_id = instance.tenant_id
    return instance
```

Add the necessary imports at the top of models.py: `from typing import cast`. The `racklab.core.tenancy_managers.TenantAwareManager` import.

### Task 3: Add the backfill helper

**Files:**

- Modify: `src/racklab/core/tenancy_bootstrap.py`

Add at the bottom:

```python
def backfill_job_tenants_forward(apps, schema_editor) -> None:
    """Backfill every existing Job row to the default RIT tenant.

    Runs inside the 0007 migration after the nullable `tenant` column is added
    and before the column is altered to non-nullable.  Idempotent — if a Job
    already has a tenant_id it is left alone.
    """
    Tenant = apps.get_model("core", "Tenant")
    Job = apps.get_model("core", "Job")
    default_tenant = Tenant.objects.filter(is_default=True).first()
    if default_tenant is None:
        # Defensive — 0005 should always have created the rit tenant, but if
        # somehow it didn't, do nothing and let the AlterField fail loudly.
        return
    Job.objects.filter(tenant__isnull=True).update(tenant=default_tenant)
```

### Task 4: Generate the migration

```bash
uv run python manage.py makemigrations core --name job_tenant_fk
```

Then **edit by hand** because the generated migration won't include the three-step nullable→backfill→non-nullable pattern. The migration should be:

```python
import django.db.models.deletion
from django.db import migrations, models

from racklab.core.tenancy_bootstrap import backfill_job_tenants_forward


class Migration(migrations.Migration):
    dependencies = [
        ("core", "0006_add_role_binding_scope_type"),
    ]
    operations = [
        migrations.AddField(
            model_name="job",
            name="tenant",
            field=models.ForeignKey(
                null=True,
                on_delete=django.db.models.deletion.PROTECT,
                related_name="jobs",
                to="core.tenant",
            ),
        ),
        migrations.RunPython(
            backfill_job_tenants_forward,
            reverse_code=migrations.RunPython.noop,
        ),
        migrations.AlterField(
            model_name="job",
            name="tenant",
            field=models.ForeignKey(
                on_delete=django.db.models.deletion.PROTECT,
                related_name="jobs",
                to="core.tenant",
            ),
        ),
        migrations.AddIndex(
            model_name="job",
            index=models.Index(
                fields=["tenant", "state"], name="core_job_tenant__state__idx"
            ),
        ),
    ]
```

If makemigrations produces something close, just hand-tune.

### Task 5: Register the middleware in production settings

**Files:**

- Modify: `src/racklab/settings/base.py`

Append `"racklab.core.middleware.TenantContextMiddleware"` to `MIDDLEWARE` after `"django.contrib.auth.middleware.AuthenticationMiddleware"`:

```python
MIDDLEWARE = [
    "django.middleware.security.SecurityMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware",
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.auth.middleware.AuthenticationMiddleware",
    "racklab.core.middleware.TenantContextMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
]
```

### Task 6: Update the existing Job tests

**Files:**

- Modify: `tests/integration/test_core_models.py`
- Modify: `tests/integration/test_job_services.py`

Wrap the Job-creating operations in `with current_tenant(str(tenant.id)):`. Each test gains a `Tenant.objects.create(...)` setup line and a context-manager block.

### Task 7: Run gates

Standard M0 gate stack. Verify all 100+ tests still pass.

### Task 8: Codex review

Background `codex exec --dangerously-bypass-approvals-and-sandbox "review the uncommitted diff..."` — fold P0/P1.

### Task 9: Commit + PROGRESS update

Conventional Commits, signed. PROGRESS.md: next slice becomes the **AuditEvent extension** (audit `actor_tenant`/`resource_tenant`/`target_tenant_set` + hash chain) since that unblocks the binding-issuance service + the cross-tenant audit emission criteria.

## Self-Review

**1. Spec coverage:**

- ✅ Job carries denormalized `tenant_id` (the FK column).
- ✅ `tenant_id` is immutable post-insert.
- ✅ `Job.objects` is tenant-aware.
- ✅ Backfill migration sets every existing row to the default RIT tenant.
- ✅ Middleware registered.
- ⚠ The `update_fields=[...]` path in `transition_job` (in `jobs.py`) doesn't include `tenant` — so the immutability check needs to fire even on partial saves. Verified — `_loaded_tenant_id` is set from `from_db()`, so the check covers both full and partial saves.

**2. Placeholder scan:** No TBDs.

**3. Type consistency:** `tenant_id` is `UUID` on the model (matches `Tenant.id`); the contextvar holds `str`, so the conversion `uuid.UUID(current)` is intentional and reversible.

## Execution Handoff

Inline execution.

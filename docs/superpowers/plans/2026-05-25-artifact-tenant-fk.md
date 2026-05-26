# Artifact Tenant-FK Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adopt the same tenant-FK + immutability + fail-closed manager pattern on `Artifact` that landed for `Job` in slice 4 (commit `2beeba2`), satisfying M0 acceptance criterion **line 66** ("`Job`, `AuditEvent`, `Artifact` carry an immutable denormalized `tenant_id` column set at insert; updating it post-insert raises a model-level validation error") for the third of the three named models. Sets up the storage-backend work (PRD §13) that lands later in M0 by guaranteeing every artifact row is tenant-scoped.

**Architecture:**

- `Artifact.tenant` FK (`PROTECT`, `related_name="artifacts"`). Underlying column is `tenant_id` — PRD §19's denormalized immutable column.
- `Artifact.save()` override: auto-sets `tenant_id` from the contextvar on insert when not provided; raises `ValidationError({"tenant": ...})` if no context AND no explicit tenant. Enforces immutability on update via the `_loaded_tenant_id` snapshot pattern from `Job`. Covers the `force_update` bypass attack the same way `Job.save()` does.
- `Artifact.__init__` / `from_db` / `refresh_from_db` overrides capture the loaded tenant id so the immutability check is accurate for both fresh `__init__` paths and queryset-loaded instances.
- `Artifact.objects = TenantAwareManager(tenant_field_name="tenant_id")` — fail-closed without a tenant context.
- New composite index `(tenant, kind)` for tenant-scoped artifact listings (parallels Job's `(tenant, state)`).
- `ArtifactReference` is NOT modified. It references an `Artifact` (which carries the tenant); per PRD §19 only `Job` / `Artifact` / `Deployment` / `Reservation` / `AuditEvent` carry the denormalized column. `ArtifactReference` is a join table and inherits scoping transitively.
- Migration `0010_artifact_tenant_fk`: AddField nullable → RunPython backfill (existing rows → default RIT tenant) → AlterField non-nullable + AddIndex.
- Backfill helper `backfill_artifact_tenants_forward` in `src/racklab/core/tenancy_bootstrap.py`.
- Existing test in `tests/integration/test_core_models.py` updated to wrap the `Artifact.objects.create(...)` call in `with current_tenant(str(tenant.id)):`.

**Tech Stack:** Django 5.2 LTS, Python 3.12+, pytest + pytest-django + factory-boy, the `TenantAwareManager` + `TenantAwareQuerySet` from slice 3.

---

## Scope boundary

**In scope:**

- `Artifact.tenant` FK + DB column.
- `Artifact.tenant_id` immutability via `save()` override (including `force_update` bypass-attack coverage).
- `Artifact.objects` swap to `TenantAwareManager`.
- Composite index `(tenant, kind)`.
- Migration `0010_artifact_tenant_fk`: three-step pattern (nullable → backfill → non-nullable + index).
- Backfill helper in `tenancy_bootstrap.py`.
- Update existing `test_core_models_persist_job_artifact_and_audit_event` to wrap `Artifact.objects.create` in `current_tenant(...)`.
- 12+ new integration tests covering: fail-closed manager, contextvar auto-set, explicit tenant override, immutability after insert, manager filters per tenant, `all_tenants()` escape, same-tenant update succeeds, queryset update guard (inherited from slice 4 — verify Artifact picks it up), force_update bypass-attack regression, bulk_update guard, refresh_from_db keeps loaded-tenant marker.

**Out of scope:**

- `ArtifactReference.tenant` FK — not in PRD §19's denormalized list.
- `Artifact.sharing_scope` field for resource visibility — deferred to the storage-backend slice.
- `Deployment.tenant` FK — `Deployment` model doesn't exist yet.
- `Reservation.tenant` FK — `Reservation` model doesn't exist yet.
- `Artifact.kind` enum constraint — PRD §13 lists the catalog_* kinds; constraining the field comes with the storage-backend work.
- `Artifact.legal_flags` schema validation — separate concern.
- Storage-backend protocol or filesystem backend implementation — separate slice.

## File Structure

- **Modify:** `src/racklab/core/models.py` — add `tenant` FK + `save()` override + `__init__`/`from_db`/`refresh_from_db` overrides + manager swap + index.
- **Modify:** `src/racklab/core/tenancy_bootstrap.py` — add `backfill_artifact_tenants_forward()` helper.
- **Create:** `src/racklab/core/migrations/0010_artifact_tenant_fk.py` — three-step migration.
- **Modify:** `tests/integration/test_core_models.py` — wrap `Artifact.objects.create` in `current_tenant(...)`.
- **Create:** `tests/integration/test_artifact_tenancy.py` — new file with the 12+ adoption tests.

No settings changes.

## Implementation tasks

### Task 1: Write the failing red tests

**Files:**

- Create: `tests/integration/test_artifact_tenancy.py`

- [ ] **Step 1: Write the integration tests**

```python
"""Integration tests for Artifact tenant-FK adoption per PRD §19."""

from __future__ import annotations

import pytest
from django.core.exceptions import ValidationError

from racklab.core.models import Artifact, Tenant
from racklab.core.tenancy_context import current_tenant
from racklab.core.tenancy_managers import (
    MissingTenantContextError,
    TenantImmutabilityError,
)


@pytest.fixture
def two_tenants() -> tuple[Tenant, Tenant]:
    """Two tenants for cross-tenant filter tests."""
    return (
        Tenant.objects.create(name="Artifact A", slug="artifact-a"),
        Tenant.objects.create(name="Artifact B", slug="artifact-b"),
    )


def _make_artifact_kwargs(suffix: str = "") -> dict[str, object]:
    """Build a minimal kwargs set for Artifact.objects.create()."""
    return {
        "kind": "audit_export",
        "content_type": "application/json",
        "size_bytes": 2,
        "sha256": "0" * 64,
        "storage_key": f"audit/empty{suffix}.json",
        "owner_scope": "global",
    }


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_objects_requires_tenant_context() -> None:
    """Artifact.objects.all() outside a tenant context raises (fail-closed)."""
    with pytest.raises(MissingTenantContextError):
        list(Artifact.objects.all())


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_save_auto_sets_tenant_from_contextvar(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Creating an Artifact under a tenant context records that tenant id."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        artifact = Artifact.objects.create(**_make_artifact_kwargs())
    assert str(artifact.tenant_id) == str(tenant_a.id)


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_save_requires_tenant_context_on_insert() -> None:
    """Inserting an Artifact with no contextvar and no explicit tenant raises."""
    with pytest.raises(ValidationError, match="tenant"):
        Artifact(**_make_artifact_kwargs()).save()


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_save_respects_explicit_tenant_assignment(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """An explicit tenant assignment wins over the contextvar at insert time."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        artifact = Artifact.objects.create(tenant=tenant_b, **_make_artifact_kwargs())
    assert str(artifact.tenant_id) == str(tenant_b.id)


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_tenant_id_is_immutable_after_insert(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Mutating tenant_id after insert raises ValidationError."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        artifact = Artifact.objects.create(**_make_artifact_kwargs())
    artifact.tenant_id = tenant_b.id
    with pytest.raises(ValidationError, match="immutable"):
        artifact.save()


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_objects_filters_by_tenant(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Artifact.objects.all() under tenant A returns only A's artifacts."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        Artifact.objects.create(**_make_artifact_kwargs("-a"))
    with current_tenant(str(tenant_b.id)):
        Artifact.objects.create(**_make_artifact_kwargs("-b"))
    with current_tenant(str(tenant_a.id)):
        keys = list(Artifact.objects.values_list("storage_key", flat=True))
    assert keys == ["audit/empty-a.json"]


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_all_tenants_escape_returns_every_row(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Artifact.objects.all_tenants() bypasses the contextvar filter."""
    tenant_a, tenant_b = two_tenants
    expected = 2
    with current_tenant(str(tenant_a.id)):
        Artifact.objects.create(**_make_artifact_kwargs("-a"))
    with current_tenant(str(tenant_b.id)):
        Artifact.objects.create(**_make_artifact_kwargs("-b"))
    assert Artifact.objects.all_tenants().count() == expected


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_save_update_within_same_tenant_succeeds(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Saving an Artifact again with the same tenant is allowed."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        artifact = Artifact.objects.create(**_make_artifact_kwargs())
        artifact.storage_key = "audit/updated.json"
        artifact.save()
    artifact.refresh_from_db()
    assert artifact.storage_key == "audit/updated.json"


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_queryset_update_rejects_tenant_kwarg(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """TenantAwareQuerySet.update() refuses tenant / tenant_id (slice 4 guard)."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        Artifact.objects.create(**_make_artifact_kwargs())
        with pytest.raises(TenantImmutabilityError):
            Artifact.objects.update(tenant_id=tenant_b.id)
        with pytest.raises(TenantImmutabilityError):
            Artifact.objects.update(tenant=tenant_b)


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_force_update_bypass_attack_is_refused(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """force_update on a manually-constructed instance can't rewrite tenant_id."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        original = Artifact.objects.create(**_make_artifact_kwargs())
    attacker = Artifact(
        id=original.id,
        tenant=tenant_b,
        **_make_artifact_kwargs(),
    )
    with pytest.raises(ValidationError, match="immutable"):
        attacker.save(force_update=True)


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_bulk_update_tenant_is_refused(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """bulk_update routes through .update() — the queryset guard fires."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        artifact = Artifact.objects.create(**_make_artifact_kwargs())
    artifact.tenant_id = tenant_b.id
    with pytest.raises(TenantImmutabilityError), current_tenant(str(tenant_a.id)):
        Artifact.objects.bulk_update([artifact], ["tenant"])


@pytest.mark.django_db
@pytest.mark.integration
def test_artifact_refresh_from_db_keeps_loaded_tenant_marker(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """refresh_from_db must refresh _loaded_tenant_id or the immutability check breaks."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        artifact = Artifact.objects.create(**_make_artifact_kwargs())
    artifact.refresh_from_db()
    artifact.tenant_id = tenant_b.id
    with pytest.raises(ValidationError, match="immutable"):
        artifact.save()
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
uv run pytest tests/integration/test_artifact_tenancy.py -v
```

Expected: every test fails — `Artifact` has no `tenant` field yet, `Artifact.objects` is the default manager.

### Task 2: Add the tenant FK + manager + index to the model

**Files:**

- Modify: `src/racklab/core/models.py` — inside `class Artifact`.

- [ ] **Step 1: Add the FK field**

Insert at the top of the `Artifact` field block (right after the `id` field):

```python
tenant = models.ForeignKey(
    "core.Tenant",
    on_delete=models.PROTECT,
    related_name="artifacts",
)
```

- [ ] **Step 2: Swap the manager**

After the fields, replace the implicit default manager by adding:

```python
objects = TenantAwareManager(tenant_field_name="tenant_id")
```

- [ ] **Step 3: Update Meta.indexes**

Append a `(tenant, kind)` composite index to the existing `indexes` list:

```python
indexes: ClassVar[list[models.Index]] = [
    models.Index(fields=["kind", "owner_scope"]),
    models.Index(fields=["sha256"]),
    models.Index(fields=["retention_until"]),
    models.Index(fields=["tenant", "kind"]),
]
```

- [ ] **Step 4: Override `__init__`, `save`, `from_db`, `refresh_from_db`**

Mirror the Job implementation precisely (the immutability + force_update protection is identical):

```python
def __init__(self, *args: object, **kwargs: object) -> None:
    """Snapshot the loaded tenant id for the immutability check on save."""
    super().__init__(*args, **kwargs)
    self._loaded_tenant_id: uuid.UUID | None = None if self._state.adding else self.tenant_id

def save(
    self,
    *,
    force_insert: bool | tuple[ModelBase, ...] = False,
    force_update: bool = False,
    using: str | None = None,
    update_fields: Iterable[str] | None = None,
) -> None:
    """Auto-set tenant from context on insert; enforce immutability on update."""
    if self._state.adding:
        current_tenant_value = cast("uuid.UUID | None", self.tenant_id)
        if current_tenant_value is None:
            current = get_current_tenant_id()
            if current is None:
                msg = _("Artifact requires a tenant context or explicit tenant on insert.")
                raise ValidationError({"tenant": msg})
            self.tenant_id = uuid.UUID(current)
        if force_update:
            stored = (
                type(self)
                .objects.all_tenants()
                .filter(pk=self.pk)
                .values_list("tenant_id", flat=True)
                .first()
            )
            if stored is not None and stored != self.tenant_id:
                msg = _("Artifact.tenant_id is immutable post-insert.")
                raise ValidationError({"tenant": msg})
    elif self._loaded_tenant_id is not None and self.tenant_id != self._loaded_tenant_id:
        msg = _("Artifact.tenant_id is immutable post-insert.")
        raise ValidationError({"tenant": msg})
    super().save(
        force_insert=force_insert,
        force_update=force_update,
        using=using,
        update_fields=update_fields,
    )
    self._loaded_tenant_id = cast("uuid.UUID | None", self.tenant_id)

@classmethod
def from_db(
    cls,
    db: str | None,
    field_names: Collection[str],
    values: Collection[object],
) -> Artifact:
    """Capture the loaded tenant id when Django builds an instance from a row."""
    instance: Artifact = super().from_db(db, field_names, values)
    instance._loaded_tenant_id = instance.tenant_id
    return instance

def refresh_from_db(
    self,
    using: str | None = None,
    fields: Iterable[str] | None = None,
    from_queryset: models.QuerySet[Artifact] | None = None,
) -> None:
    """Refresh fields AND the loaded-tenant marker so save() stays accurate."""
    super().refresh_from_db(using=using, fields=fields, from_queryset=from_queryset)
    self._loaded_tenant_id = cast("uuid.UUID | None", self.tenant_id)
```

(All imports — `uuid`, `cast`, `ValidationError`, `get_current_tenant_id`, `gettext_lazy as _` — are already present from the Job slice.)

### Task 3: Add the backfill helper

**Files:**

- Modify: `src/racklab/core/tenancy_bootstrap.py` — append at the end.

- [ ] **Step 1: Add the helper**

```python
def backfill_artifact_tenants_forward(apps: Apps) -> None:
    """Backfill every existing Artifact row to the default RIT tenant.

    Called from 0010 between the nullable AddField and the non-nullable
    AlterField. Migration-only — uses ``apps.get_model`` so it bypasses the
    live ``TenantAwareManager`` on Artifact. Hard-fails if 0005 did not seed
    the default tenant.
    """
    tenant_model = apps.get_model("core", "Tenant")
    artifact_model = apps.get_model("core", "Artifact")
    default_tenant = tenant_model.objects.filter(is_default=True).first()
    if default_tenant is None:
        msg = (
            "Artifact tenant backfill cannot run: no default tenant exists. "
            "Verify migration 0005_add_tenancy ran successfully before 0010."
        )
        raise RuntimeError(msg)
    artifact_model.objects.filter(tenant__isnull=True).update(tenant=default_tenant)
```

### Task 4: Create the migration

**Files:**

- Create: `src/racklab/core/migrations/0010_artifact_tenant_fk.py`

- [ ] **Step 1: Generate the migration scaffold**

```bash
uv run python manage.py makemigrations core --name artifact_tenant_fk
```

- [ ] **Step 2: Hand-edit the migration to the three-step pattern**

```python
import django.db.models.deletion
from django.db import migrations, models

from racklab.core.tenancy_bootstrap import backfill_artifact_tenants_forward


class Migration(migrations.Migration):
    dependencies = [
        ("core", "0009_rolebinding_home_tenant"),
    ]
    operations = [
        migrations.AddField(
            model_name="artifact",
            name="tenant",
            field=models.ForeignKey(
                null=True,
                on_delete=django.db.models.deletion.PROTECT,
                related_name="artifacts",
                to="core.tenant",
            ),
        ),
        migrations.RunPython(
            backfill_artifact_tenants_forward,
            reverse_code=migrations.RunPython.noop,
        ),
        migrations.AlterField(
            model_name="artifact",
            name="tenant",
            field=models.ForeignKey(
                on_delete=django.db.models.deletion.PROTECT,
                related_name="artifacts",
                to="core.tenant",
            ),
        ),
        migrations.AddIndex(
            model_name="artifact",
            index=models.Index(fields=["tenant", "kind"], name="core_artifa_tenant__kind__idx"),
        ),
    ]
```

(If makemigrations produces different index name styling, accept what it generates.)

- [ ] **Step 3: Verify migration applies**

```bash
uv run python manage.py migrate --run-syncdb
```

### Task 5: Update the existing test that creates an Artifact

**Files:**

- Modify: `tests/integration/test_core_models.py`

- [ ] **Step 1: Wrap the existing Artifact.objects.create in current_tenant**

Find the existing test `test_core_models_persist_job_artifact_and_audit_event` and move the `Artifact.objects.create(...)` call inside the existing `with current_tenant(str(tenant.id)):` block (which currently only wraps the Job create). Diff:

```python
with current_tenant(str(tenant.id)):
    job = Job.objects.create(
        kind=JobKind.PLUGIN.value,
        state=JobState.DISPATCHING.value,
        state_history=[JobState.DISPATCHING.value],
        correlation_id="corr-001",
    )
    artifact = Artifact.objects.create(  # MOVED INTO BLOCK
        kind="audit_export",
        content_type="application/json",
        size_bytes=2,
        sha256="0" * 64,
        storage_key="audit/empty.json",
        owner_scope="global",
    )
reference = ArtifactReference.objects.create(  # stays outside, unchanged
    artifact=artifact,
    object_label=f"job:{job.id}",
    purpose="audit",
)
```

ArtifactReference, AuditEvent stay as-is (AuditEvent uses `actor_tenant=tenant` explicitly, and the wrapping `current_tenant(...)` block from the slice-4 update already covers the audit-emission contextvar requirement).

### Task 6: Run the gate stack

- [ ] **Step 1: Pre-commit on changed files**

```bash
uv run pre-commit run --files src/racklab/core/models.py src/racklab/core/tenancy_bootstrap.py src/racklab/core/migrations/0010_artifact_tenant_fk.py tests/integration/test_artifact_tenancy.py tests/integration/test_core_models.py
```

- [ ] **Step 2: Full gate stack**

```bash
uv lock --check
uv sync --locked
uv run ruff format --check .
uv run ruff check .
uv run mypy
uv run basedpyright
uv run pytest
uv run python manage.py check
uv run bandit -c pyproject.toml -r src
uv run pip-audit
```

Expected: every gate green; pytest count rises by 12 new tests; previously-passing tests still pass.

### Task 7: Codex diff review

- [ ] **Step 1: Launch codex review**

```bash
tmpfile=$(mktemp /tmp/codex-review.XXXXXX.md)
codex review --uncommitted --dangerously-bypass-approvals-and-sandbox > "$tmpfile" 2>&1
```

Background launch; wait for completion notification; Read the tmpfile; fold P0/P1 before commit.

### Task 8: Commit + PROGRESS.md update

- [ ] **Step 1: Update PROGRESS.md**

- New "Ninth M0 implementation slice (Artifact tenant-FK adoption)" subsection.
- M0 Gaps section: remove the `Artifact` tenant-FK adoption entry.
- Acceptance criteria status: extend line 66 ("`Job`, `AuditEvent`, `Artifact` ...") to mention Artifact is now covered too.
- Recommended Next Slice: outbox table + drain command.

- [ ] **Step 2: Commit**

```bash
git add src/racklab/core/models.py src/racklab/core/tenancy_bootstrap.py src/racklab/core/migrations/0010_artifact_tenant_fk.py tests/integration/test_artifact_tenancy.py tests/integration/test_core_models.py docs/superpowers/plans/2026-05-25-artifact-tenant-fk.md
git commit -m "feat(core): adopt tenant FK + tenant-aware manager on Artifact"
```

Then:

```bash
git add PROGRESS.md
git commit -m "docs(progress): update for the Artifact tenant-FK adoption slice"
```

## Self-Review

**1. Spec coverage:**

- ✅ Artifact gains denormalized `tenant_id`, immutable post-insert.
- ✅ `Artifact.objects` is tenant-aware (fail-closed).
- ✅ Backfill migration sets every existing row to the default RIT tenant.
- ✅ Force_update bypass attack covered.
- ✅ Composite index for tenant-scoped queries.
- ✅ Existing test fixture updated.

**2. Placeholder scan:** No TBDs; all code shown verbatim; migration body complete.

**3. Type consistency:** `tenant_id` is `UUID` on the model (matches `Tenant.id`); contextvar holds `str`; conversion `uuid.UUID(current)` is intentional. `_loaded_tenant_id` mirrors Job exactly.

## Execution Handoff

Subagent-driven (per the user request: "lets go, subagents style").

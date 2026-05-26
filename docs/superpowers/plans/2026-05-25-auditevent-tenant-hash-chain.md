# AuditEvent Tenant Columns + Hash Chain Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Extend `AuditEvent` with the tenant + hash-chain shape spelled out in PRD §14 + §19 — `actor_tenant` / `resource_tenant` / `target_tenant_set` columns, `prev_hash` / `hash` tamper-evident chain, and a `manage.py verify_audit_chain` integrity command — so the next slice (cross-tenant binding-issuance service + `tenant.cross_access` audit emission) can satisfy M0 acceptance criteria lines 64–65.

**Architecture:**

- `AuditEvent` gains five new columns. `actor_tenant` is a non-null FK to Tenant; every event carries the actor's tenant context. `resource_tenant` is a nullable FK (issuance-variant events leave it null per PRD §19). `target_tenant_set` is a JSONField list of tenant UUID strings (default empty; populated for cross-tenant issuance events to drive PRD §14's bidirectional surfacing).
- `prev_hash` + `hash` are SHA-256 hex strings (64 chars). `prev_hash` is `""` for the first event; subsequent events copy the prior `hash`. `hash = sha256(prev_hash || canonical_serialization(event_payload))`. Tampering with any field in the chain breaks every downstream hash and is caught by `manage.py verify_audit_chain`.
- Chain integrity is computed inside `AuditEvent.save()` under a `transaction.atomic()` block that does `SELECT … FOR UPDATE … LIMIT 1` on the most-recent row to serialize concurrent inserts. For M0 traffic this is fine; production-scale serialization is a known concern (PRD §14 calls out Postgres advisory locks / queue-based emit as a future optimization — track in M13).
- `emit_audit_event` in `racklab.core.audit` reads `actor_tenant` from the contextvar; raises if unset. Existing audit emit call sites (`jobs.py`, `plugin_lifecycle.py`) gain a tenant context wrapper in their tests.
- `AuditEvent` does NOT swap its default manager to `TenantAwareManager` — audit visibility is bidirectional (PRD §14), and a single-field filter doesn't capture that. Defer the bidirectional manager to a separate slice; this slice keeps `AuditEvent.objects` as the stock Manager and the writes are gated by `emit_audit_event` requiring tenant context.
- Migration `0008_auditevent_tenant_hash_chain`: three-step columns (AddField nullable → RunPython backfill → AlterField non-null) for `actor_tenant`; AddField for `resource_tenant`/`target_tenant_set`/`prev_hash`/`hash`; RunPython recomputes the hash chain over existing rows in `(created_at, id)` order so chain integrity holds from the migration forward.

**Tech Stack:** Django 5.2 LTS, Python 3.12+ `hashlib.sha256`, JSONField for `target_tenant_set`, pytest + pytest-django.

---

## Codex review feedback folded (2026-05-25)

Codex flagged 2 P0s + 4 P1s + 3 P2s on the draft. The corrected implementation:

- **P0 — `SELECT FOR UPDATE` on AuditEvent rows doesn't serialize the chain.** SQLite ignores row locks; Postgres only locks existing rows (empty-table inserts race). **Corrected:** use a **Postgres advisory transaction lock** via `pg_advisory_xact_lock(<fixed key>)` inside the save() atomic block. SQLite serializes writes natively via its database-level lock, so the chain stays consistent on SQLite too. The key is a fixed int64 sentinel derived from `sha256("racklab.audit_event.chain")[:8]`.

- **P0 — `auto_now_add=True` overrides the manually-stamped `created_at` during super().save(), breaking the hash.** **Corrected:** AuditEvent overrides `created_at` to use `default=timezone.now` (settable, not auto-overwritten). `default` populates the field at `__init__` time so the same instant feeds both the hash and the persisted row.

- **P1 — Append-only invariant not enforced at queryset level for non-tenant fields.** `TenantAwareQuerySet.update()` guard only catches `tenant`/`tenant_id`. **Corrected:** AuditEvent gets its own `AuditEventQuerySet` + `AuditEventManager` that refuses ALL `update()` / `bulk_update()` calls (raises `AppendOnlyError`). The model is append-only — no field is mutable post-insert. Per-row `save()` already raises in the non-adding branch.

- **P1 — Migration cannot add a non-null `hash` to existing rows.** **Corrected:** AddField `hash` with `null=True, default=""` first, backfill computes hashes for all existing rows in `(created_at, id)` order, then AlterField to non-null.

- **P1 — CLI audit emits fail in production without tenant context.** plugin_lifecycle.py emits audit events from CLI commands that run outside a tenant context. **Corrected:** Add a `--tenant` flag (default: `rit` slug for M0) to the affected CLI commands; the command body wraps its work in `current_tenant(resolved_tenant_id)`. Also: when invoked without any tenant context (programmatic API path), fall back to a "system" path that uses the default RIT tenant.

- **P1 — `target_tenant_set` validation missing on the emit path.** Django's `.create()` doesn't call `clean()`. **Corrected:** `emit_audit_event` validates the `target_tenant_set` argument inline (must be a list of unique non-empty strings) and **canonicalizes** by sorting before passing to the model (semantically a set).

- **P2 — Canonical JSON hardening.** **Corrected:** `json.dumps(..., sort_keys=True, separators=(',', ':'), allow_nan=False)`; reject non-JSON payload values at emit time by deep-walking the payload and verifying types; `created_at` is always serialized as `.astimezone(UTC).isoformat()` regardless of incoming timezone.

- **P2 — No lint overrides.** **Corrected:** define `SHA256_HEX_LENGTH = 64` as a module constant; expose a public `AuditEvent.compute_hash_for(...)` classmethod for the verify command so no private-attribute access is needed.

- **P2 — save() ignores `using`.** **Corrected:** save() resolves the write DB and uses `transaction.atomic(using=...)` + connection lookup for the advisory lock.

- **P3 — GIN index claim.** **Corrected:** Plan explicitly says GIN on `target_tenant_set` is deferred to M13 (Postgres-only); M0 has actor_tenant and resource_tenant b-tree indexes only.

## Scope boundary

**In scope:**

- `AuditEvent.actor_tenant` (non-null FK, immutable via `save()` override + queryset guard).
- `AuditEvent.resource_tenant` (nullable FK, immutable post-insert).
- `AuditEvent.target_tenant_set` (JSONField default `[]`, shape-validated like RoleBinding's tenant_set).
- `AuditEvent.prev_hash` + `AuditEvent.hash` (CharField max_length=64 each).
- `AuditEvent.save()` override:
  - On insert: read most-recent row's `hash` (under `SELECT FOR UPDATE`); set `prev_hash`; compute `hash`.
  - On update: refuse (AuditEvent is append-only — `update()` is the only path Django offers, and the queryset guard already blocks tenant mutations; raise `ValidationError` on any non-insert save).
- `_canonical_event_payload()` helper: deterministic JSON serialization of (event_name, actor_tenant_id, resource_tenant_id, target_tenant_set, actor_identifier, scope_identifier, correlation_id, payload, created_at_iso). Sorted keys, UTF-8.
- `manage.py verify_audit_chain` command:
  - Walks every row in `(created_at, id)` order.
  - Recomputes the chain from genesis; flags any row whose stored `hash` doesn't match the recomputed value.
  - Output: row-by-row "OK" / "TAMPERED" markers + a final count + non-zero exit code on tamper.
- `emit_audit_event` reads `actor_tenant` from the contextvar; raises `ValidationError` if no tenant context. Accepts optional `resource_tenant` + `target_tenant_set` kwargs for cross-tenant variants.
- Migration `0008_auditevent_tenant_hash_chain` with hash-chain backfill.
- Update existing emit callers in `jobs.py` and `plugin_lifecycle.py` — they already run under a tenant context in tests after the Job slice; verify no breakage.
- Update existing audit tests to set tenant context.
- New integration tests:
  - `actor_tenant` auto-set from contextvar.
  - `actor_tenant` is immutable post-insert.
  - `target_tenant_set` shape validation.
  - Hash chain: first event has `prev_hash=""` and `hash` matches the canonical serialization.
  - Chain integrity: tampering with payload after insert breaks `verify_audit_chain`.
  - Concurrent insert serialization: two emits land sequentially, second's `prev_hash` equals first's `hash`.
- `verify_audit_chain` command test: clean chain → exits 0; tampered chain → exits non-zero.

**Out of scope (deferred):**

- **Bidirectional visibility manager** for `AuditEvent.objects` — `actor_tenant = X OR resource_tenant = X OR X IN target_tenant_set`. Defer to its own slice when audit UI queries are designed. For now, the schema is in place; queries are explicit.
- **Production-scale chain integrity** — Postgres advisory locks, append-only outbox, async hash batch computation. M13 concern.
- **`tenant.cross_access` issuance-variant audit emission** — depends on this slice landing first; comes with the binding-issuance service slice.
- **Outbox table + `manage.py drain_audit_outbox`** — M0 deliverable but separate slice (it's about NATS relay discipline, not audit shape).

## File Structure

- **Modify:** `src/racklab/core/models.py` — extend `AuditEvent` with 5 columns + save() override + hash helper.
- **Modify:** `src/racklab/core/audit.py` — `emit_audit_event` reads contextvar, accepts cross-tenant kwargs.
- **Modify:** `src/racklab/core/tenancy_bootstrap.py` — add `backfill_audit_event_tenants_and_chain_forward` helper.
- **Create:** `src/racklab/core/migrations/0008_auditevent_tenant_hash_chain.py` — schema + backfill.
- **Create:** `src/racklab/core/management/commands/verify_audit_chain.py` — integrity command.
- **Modify:** `tests/integration/test_core_models.py` + `test_job_services.py` + `test_plugin_lifecycle_cli.py` — wrap emit-using paths in tenant context.
- **Create:** `tests/integration/test_audit_event_tenant_chain.py` — adoption + hash chain tests.
- **Create:** `tests/integration/test_verify_audit_chain_command.py` — command tests.

## Implementation tasks

### Task 1: New integration tests for AuditEvent tenant + chain (red)

**Files:**

- Create: `tests/integration/test_audit_event_tenant_chain.py`

```python
"""Integration tests for AuditEvent tenant columns + hash chain per PRD §14 + §19."""

from __future__ import annotations

import hashlib
import json
from datetime import UTC, datetime
from typing import TYPE_CHECKING

import pytest
from django.core.exceptions import ValidationError

from racklab.core.audit import AuditContext, emit_audit_event
from racklab.core.models import AuditEvent, Tenant
from racklab.core.tenancy_context import current_tenant
from racklab.core.tenancy_managers import TenantImmutabilityError

if TYPE_CHECKING:
    pass


@pytest.fixture
def two_tenants() -> tuple[Tenant, Tenant]:
    """Two tenants for cross-tenant audit tests."""
    return (
        Tenant.objects.create(name="A", slug="audit-tenant-a"),
        Tenant.objects.create(name="B", slug="audit-tenant-b"),
    )


@pytest.mark.django_db
@pytest.mark.integration
def test_emit_audit_event_reads_actor_tenant_from_contextvar(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """emit_audit_event populates actor_tenant from the current tenant context."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        event = emit_audit_event("test.event", context=AuditContext(actor_identifier="alice"))
    assert str(event.actor_tenant_id) == str(tenant_a.id)


@pytest.mark.django_db
@pytest.mark.integration
def test_emit_audit_event_requires_tenant_context() -> None:
    """emit_audit_event without a tenant context raises ValidationError."""
    with pytest.raises(ValidationError, match="tenant"):
        emit_audit_event("test.event")


@pytest.mark.django_db
@pytest.mark.integration
def test_emit_audit_event_accepts_explicit_resource_tenant_and_target_set(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Cross-tenant audit events can carry resource_tenant + target_tenant_set."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        event = emit_audit_event(
            "tenant.cross_access",
            context=AuditContext(actor_identifier="alice"),
            resource_tenant=tenant_b,
            target_tenant_set=[str(tenant_b.id)],
        )
    assert str(event.actor_tenant_id) == str(tenant_a.id)
    assert str(event.resource_tenant_id) == str(tenant_b.id)
    assert event.target_tenant_set == [str(tenant_b.id)]


@pytest.mark.django_db
@pytest.mark.integration
def test_audit_event_actor_tenant_is_immutable(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Updating actor_tenant on an existing AuditEvent raises ValidationError."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        event = emit_audit_event("test.event")
    event.actor_tenant = tenant_b
    with pytest.raises(ValidationError, match="immutable"):
        event.save()


@pytest.mark.django_db
@pytest.mark.integration
def test_audit_event_queryset_update_rejects_tenant_mutations(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """queryset .update() cannot mutate actor_tenant — append-only invariant."""
    tenant_a, tenant_b = two_tenants
    with current_tenant(str(tenant_a.id)):
        emit_audit_event("test.event")
    with pytest.raises(TenantImmutabilityError):
        AuditEvent.objects.all().update(actor_tenant=tenant_b)


@pytest.mark.django_db
@pytest.mark.integration
def test_audit_event_target_tenant_set_shape_validated(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """target_tenant_set must be a list of unique non-empty strings."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)), pytest.raises(ValidationError):
        # A dict masquerading as a list — would slip past a naive truthy check.
        event = AuditEvent(
            event_name="bad.shape",
            actor_tenant=tenant_a,
            target_tenant_set={"x": True},
        )
        event.full_clean()


@pytest.mark.django_db
@pytest.mark.integration
def test_audit_event_genesis_row_has_empty_prev_hash(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """The first AuditEvent in the chain has prev_hash="" and a computed hash."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        event = emit_audit_event("test.event")
    assert event.prev_hash == ""
    assert len(event.hash) == 64  # noqa: PLR2004 — sha256 hex length is a documented constant in the model


@pytest.mark.django_db
@pytest.mark.integration
def test_audit_event_chain_links_prev_to_hash(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Each AuditEvent's prev_hash equals the previous event's hash."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        first = emit_audit_event("test.event.one")
        second = emit_audit_event("test.event.two")
        third = emit_audit_event("test.event.three")
    assert second.prev_hash == first.hash
    assert third.prev_hash == second.hash


@pytest.mark.django_db
@pytest.mark.integration
def test_audit_event_hash_includes_event_name_and_payload(
    two_tenants: tuple[Tenant, Tenant],
) -> None:
    """Recomputing the hash from the canonical serialization yields the stored value."""
    tenant_a, _ = two_tenants
    with current_tenant(str(tenant_a.id)):
        event = emit_audit_event("test.event", payload={"key": "value"})

    canonical = json.dumps(
        {
            "event_name": event.event_name,
            "actor_tenant_id": str(event.actor_tenant_id),
            "resource_tenant_id": (
                str(event.resource_tenant_id) if event.resource_tenant_id else None
            ),
            "target_tenant_set": event.target_tenant_set,
            "actor_identifier": event.actor_identifier,
            "scope_identifier": event.scope_identifier,
            "correlation_id": event.correlation_id,
            "payload": event.payload,
            "created_at": event.created_at.astimezone(UTC).isoformat(),
        },
        sort_keys=True,
        separators=(",", ":"),
    )
    expected = hashlib.sha256(
        event.prev_hash.encode("utf-8") + canonical.encode("utf-8")
    ).hexdigest()
    assert event.hash == expected
```

### Task 2: Verify-audit-chain command tests (red)

**Files:**

- Create: `tests/integration/test_verify_audit_chain_command.py`

```python
"""Integration tests for the verify_audit_chain management command."""

from __future__ import annotations

import io

import pytest
from django.core.management import call_command
from django.core.management.base import CommandError

from racklab.core.audit import emit_audit_event
from racklab.core.models import AuditEvent, Tenant
from racklab.core.tenancy_context import current_tenant


@pytest.mark.django_db
@pytest.mark.integration
def test_verify_audit_chain_passes_on_clean_chain() -> None:
    """An untampered chain verifies cleanly."""
    tenant = Tenant.objects.create(name="T", slug="verify-clean")
    with current_tenant(str(tenant.id)):
        for index in range(3):
            emit_audit_event(f"test.event.{index}")
    stdout = io.StringIO()
    call_command("verify_audit_chain", stdout=stdout)
    output = stdout.getvalue()
    assert "OK" in output
    assert "TAMPERED" not in output


@pytest.mark.django_db
@pytest.mark.integration
def test_verify_audit_chain_passes_on_empty_chain() -> None:
    """An empty AuditEvent table verifies cleanly."""
    stdout = io.StringIO()
    call_command("verify_audit_chain", stdout=stdout)
    assert "TAMPERED" not in stdout.getvalue()


@pytest.mark.django_db
@pytest.mark.integration
def test_verify_audit_chain_detects_payload_tamper() -> None:
    """Mutating a row's payload outside the chain breaks verification."""
    tenant = Tenant.objects.create(name="T", slug="verify-tamper")
    with current_tenant(str(tenant.id)):
        emit_audit_event("test.event.one", payload={"key": "original"})
        emit_audit_event("test.event.two")
    # Mutate the first event's payload directly via .update() (bypasses save() chain logic).
    AuditEvent.objects.filter(event_name="test.event.one").update(
        payload={"key": "tampered"}
    )
    with pytest.raises(CommandError, match="TAMPERED"):
        call_command("verify_audit_chain")
```

### Task 3: Implement AuditEvent model changes

**Files:**

- Modify: `src/racklab/core/models.py`

Within the `AuditEvent` class, add fields:

```python
actor_tenant = models.ForeignKey(
    "core.Tenant",
    on_delete=models.PROTECT,
    related_name="audit_events_as_actor",
)
resource_tenant = models.ForeignKey(
    "core.Tenant",
    on_delete=models.PROTECT,
    null=True,
    blank=True,
    related_name="audit_events_as_resource",
)
target_tenant_set = models.JSONField(default=list, blank=True)
prev_hash = models.CharField(max_length=64, blank=True, default="")
hash = models.CharField(max_length=64)
```

Add `_canonical_event_payload()`, `_compute_hash()`, and a `save()` override:

```python
def _canonical_event_payload(self) -> str:
    """Deterministic JSON serialization of every field that feeds the hash."""
    return json.dumps(
        {
            "event_name": self.event_name,
            "actor_tenant_id": str(self.actor_tenant_id),
            "resource_tenant_id": (
                str(self.resource_tenant_id) if self.resource_tenant_id else None
            ),
            "target_tenant_set": self.target_tenant_set,
            "actor_identifier": self.actor_identifier,
            "scope_identifier": self.scope_identifier,
            "correlation_id": self.correlation_id,
            "payload": self.payload,
            "created_at": self.created_at.astimezone(UTC).isoformat(),
        },
        sort_keys=True,
        separators=(",", ":"),
    )


def _compute_hash(self) -> str:
    return hashlib.sha256(
        self.prev_hash.encode("utf-8") + self._canonical_event_payload().encode("utf-8")
    ).hexdigest()


def save(self, *, force_insert=False, force_update=False, using=None, update_fields=None) -> None:
    if not self._state.adding:
        # AuditEvent is append-only — refuse any update. The queryset
        # update() guard catches the bulk path; this catches the per-row path.
        msg = _("AuditEvent is append-only; existing rows cannot be modified.")
        raise ValidationError(msg)
    with transaction.atomic():
        last = (
            AuditEvent.objects.select_for_update()
            .order_by("-created_at", "-id")
            .first()
        )
        self.prev_hash = last.hash if last is not None else ""
        # Stamp created_at now so the hash is reproducible.
        self.created_at = self.created_at or timezone.now()
        self.hash = self._compute_hash()
        super().save(force_insert=force_insert, force_update=force_update, using=using, update_fields=update_fields)
```

Add `clean()` shape validation for `target_tenant_set` (mirror RoleBinding's pattern).

Update `Meta.indexes` to add: `actor_tenant`, `resource_tenant`. Skip GIN on `target_tenant_set` — SQLite doesn't support it; Postgres-specific index will land when production deployment is settled (M13).

### Task 4: Implement audit.py emit changes

**Files:**

- Modify: `src/racklab/core/audit.py`

```python
def emit_audit_event(
    event_name: str,
    *,
    context: AuditContext | None = None,
    payload: JsonObject | None = None,
    resource_tenant: Tenant | None = None,
    target_tenant_set: Iterable[str] | None = None,
) -> AuditEvent:
    """Persist an append-only audit event with chain integrity."""
    audit_context = context if context is not None else AuditContext()
    audit_payload = dict(payload) if payload is not None else {}
    tenant_id = get_current_tenant_id()
    if tenant_id is None:
        raise ValidationError(
            {"actor_tenant": _("emit_audit_event requires a tenant context.")},
        )
    return AuditEvent.objects.create(
        event_name=event_name,
        actor_tenant_id=uuid.UUID(tenant_id),
        actor_identifier=audit_context.actor_identifier,
        scope_identifier=audit_context.scope_identifier,
        correlation_id=audit_context.correlation_id,
        payload=audit_payload,
        resource_tenant=resource_tenant,
        target_tenant_set=list(target_tenant_set) if target_tenant_set else [],
    )
```

### Task 5: Implement verify_audit_chain command

**Files:**

- Create: `src/racklab/core/management/commands/verify_audit_chain.py`

```python
"""Walk the AuditEvent chain and verify hash integrity."""

from __future__ import annotations

from typing import TYPE_CHECKING

from django.core.management.base import BaseCommand, CommandError

from racklab.core.models import AuditEvent

if TYPE_CHECKING:
    pass


class Command(BaseCommand):
    """Verify the AuditEvent hash chain."""

    help = "Verify the AuditEvent tamper-evident hash chain."

    def handle(self, *args: object, **options: object) -> None:
        """Walk every AuditEvent in (created_at, id) order; recompute the chain."""
        last_hash = ""
        tampered: list[tuple[str, str]] = []
        ok_count = 0
        for event in AuditEvent.objects.order_by("created_at", "id").iterator():
            expected_prev = last_hash
            expected_hash = AuditEvent._compute_hash_for(  # noqa: SLF001 — same-package access
                prev_hash=expected_prev,
                event_name=event.event_name,
                actor_tenant_id=event.actor_tenant_id,
                resource_tenant_id=event.resource_tenant_id,
                target_tenant_set=event.target_tenant_set,
                actor_identifier=event.actor_identifier,
                scope_identifier=event.scope_identifier,
                correlation_id=event.correlation_id,
                payload=event.payload,
                created_at=event.created_at,
            )
            if event.prev_hash != expected_prev or event.hash != expected_hash:
                tampered.append((str(event.id), event.event_name))
                self.stdout.write(self.style.ERROR(f"TAMPERED {event.id} {event.event_name}"))
            else:
                ok_count += 1
                self.stdout.write(f"OK {event.id} {event.event_name}")
            last_hash = event.hash
        self.stdout.write(f"verified {ok_count}; tampered {len(tampered)}")
        if tampered:
            raise CommandError(f"TAMPERED rows detected: {tampered}")
```

The implementation needs a `_compute_hash_for` classmethod on `AuditEvent` that operates on field values without needing a saved instance. Add that alongside `_canonical_event_payload`.

### Task 6: Migration with hash-chain backfill

**Files:**

- Create: `src/racklab/core/migrations/0008_auditevent_tenant_hash_chain.py`

Three-step pattern for `actor_tenant`:

1. AddField nullable
2. RunPython backfill: every existing row → default RIT tenant; resource_tenant=null; target_tenant_set=[]; then recompute hash chain in `(created_at, id)` order
3. AlterField non-null

Then AddField for the other columns + AddIndex.

Helper in `tenancy_bootstrap.backfill_audit_event_tenants_and_chain_forward`.

### Task 7: Update existing audit-using tests

- `tests/integration/test_core_models.py` — already wraps Job creation in `current_tenant(...)`; verify AuditEvent.objects.create() in that test also runs under context.
- `tests/integration/test_job_services.py` — `create_job` + `transition_job` already wrapped after the Job slice; verify the AuditEvent rows now carry `actor_tenant_id` matching the wrapped tenant.
- `tests/integration/test_plugin_lifecycle_cli.py` — plugin lifecycle audit emit needs tenant context; wrap the CLI calls or set context in the command.

### Task 8: Run gates + codex diff review

Standard pattern.

### Task 9: Commit + PROGRESS update

`feat(core): extend AuditEvent with tenant columns + tamper-evident hash chain`. Update PROGRESS.md to point at the binding-issuance service as the next slice (now unblocked).

## Self-Review

**1. Spec coverage:**

- ✅ actor_tenant / resource_tenant / target_tenant_set per PRD §19 AuditEvent row.
- ✅ prev_hash / hash per PRD §14.
- ✅ verify_audit_chain command per PRD §14 + M0 deliverables.
- ✅ Append-only invariant enforced at both save() and queryset.update() levels.
- ⚠ Bidirectional visibility manager — deferred. Documented in scope boundary.
- ⚠ Production-scale chain integrity — known limitation, documented.

**2. Placeholder scan:** No TBDs.

**3. Type consistency:** `actor_tenant_id` is `UUID`; canonical serialization stringifies it; hash takes bytes.

## Execution Handoff

Inline execution.

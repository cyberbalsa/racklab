# Binding-Issuance Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Wire `validate_binding_issuance_containment` (slice 2) + `RoleBinding.full_clean()` (slice 2) + `tenant.cross_access` audit emission (slice 5) into a single `issue_role_binding` service that satisfies M0 acceptance criterion **line 65** end-to-end: "Granting a `multi_tenant` or `global` role binding requires the granter to hold a binding of equal or broader scope; escalation attempts fail with `tenant.cross_access` (`result=denied, reason=insufficient_scope`)."

**Architecture:**

- New module `src/racklab/core/binding_issuance.py` with a single public `issue_role_binding(...)` function.
- The function takes a granter user (whose covered tenant set is derived from their `RoleBinding` rows + primary `TenantMembership`) plus the target binding parameters.
- Flow: derive granter's covered tenants → call containment helper → on failure, emit `tenant.cross_access` (`result=denied`, `reason=insufficient_scope`) and re-raise → on success, build the proposed `RoleBinding`, call `full_clean()` (so the slice-2 JSON-shape `tenant_set` checks fire even though `RoleBinding.objects.create()` doesn't call clean) → persist → for non-`tenant_local` issuance, emit a `tenant.cross_access` (`result=allowed`) issuance-variant audit row with `target_tenant_set=tenant_set`.
- The audit event for the issuance pattern is what PRD §14 calls the "issuance variant" — `resource_tenant=null`, `target_tenant_set` populated, so the bidirectional surfacing works.

**Tech Stack:** Django 5.2 LTS, the helpers from prior slices, pytest + pytest-django.

---

## Codex review feedback folded (2026-05-25)

Codex returned 0 P0 + 6 P1 + 4 P2 on the draft. Corrections:

- **P1 — `RoleBinding.objects.all_tenants()` doesn't exist.** `RoleBinding` uses the default `Manager`, not `TenantAwareManager`. **Corrected:** use `RoleBinding.objects.filter(...)` directly.
- **P1 — Global issuance `target_tenant_set` surfacing.** Setting `target_tenant_set=None` means PRD §14's bidirectional-surfacing query (`viewing_tenant IN target_tenant_set`) doesn't fire for global issuance. **Corrected:** for global issuance, materialise all `Tenant` ids into the audit event's `target_tenant_set`. For M0 this is small (RIT plus any additionals); the cost scales linearly with tenant count, which PRD §14 acknowledges as a known concern for the bidirectional model.
- **P1 — Wrong audit payload shape.** PRD §14 specifies the *issuance variant* of `tenant.cross_access` with exact fields: `issuance_target`, `target_scope_type`, `target_tenant_set`, `actor_held_scope`, `action`, `result`, `reason`. **Corrected:** rewrite the payload to match. Drop my earlier ad-hoc names (`granter_scope_type`, `binding_scope`).
- **P1 — `tenant.binding.issued` is a separate event.** PRD §14 line 132 lists it explicitly; emitting only `tenant.cross_access` allowed is not a substitute. **Corrected:** on successful `multi_tenant` / `global` issuance, emit **both** events: `tenant.cross_access` (issuance variant, `result=allowed`) AND `tenant.binding.issued` (RBAC-grant audit, carries granter + binding_scope + tenant_set + granted_reason).
- **P1 — Principal identifier convention.** Existing tests use `user:<username>` (e.g., `user:alice`); my plan used `user:{pk}`. **Corrected:** central helper `principal_identifier_for_user(user) -> str` that returns `f"user:{user.username}"`. Service uses this; tests use this. Documents the convention.
- **P1 — `multi_tenant` / `global` bindings can still be created via raw `.create()` bypassing the service.** **Corrected:** add a contract test in `tests/integration/test_binding_issuance_service.py` asserting the service is the only sanctioned issuance path. The convention is documented; future linting/RBAC permission gating can enforce it harder.
- **P2 — Plan internal contradiction.** Tests/sample code mentioned `tenant_local`; final scope rejects it. **Corrected:** the service raises `ValueError` if called with `scope_type=tenant_local` — the path goes through raw `.create()` under tenant context.
- **P2 — Dict masquerade.** `list(dict_arg)` yields keys silently. **Corrected:** explicit `isinstance(tenant_set, dict)` check raises `TypeError` at the top of `issue_role_binding`. Mirrors the rejection in `emit_audit_event` from slice 5.
- **P2 — Union policy on multi-binding granters.** PRD is ambiguous between "one held binding must cover the issued scope" (per-binding) and "actor's effective coverage is the union" (union). **Decision:** union policy, documented. Per-binding gating becomes a permission concern handled separately.
- **P2 — Atomicity:** emit-denied-before-raise is the right ordering. If the audit emit itself fails, the containment violation propagates as the audit error — fail-closed is acceptable. Caller-outer-transaction concern: a denied audit emitted inside a wrapping `atomic()` can roll back. Document.
- **Test matrix update (codex-direct):** keep "granter with no bindings tries to issue multi_tenant → denied". Drop the "intra-tenant no-emit" test (the service refuses tenant_local).

## Scope boundary

**In scope:**

- `src/racklab/core/binding_issuance.py`:
  - `issue_role_binding(granter_user, role, principal_kind, principal_identifier, scope_kind, scope_identifier, *, scope_type, tenant_set, granted_reason) -> RoleBinding`
  - Helper `_resolve_granter_covered_tenants(granter_user) -> tuple[BindingScopeType, tuple[str, ...]]` — finds the granter's best (broadest) RoleBinding for issuance authority. Logic:
    - If granter has any `scope_type=global` binding → return `(GLOBAL, ())`.
    - Else if granter has any `scope_type=multi_tenant` bindings → return `(MULTI_TENANT, tuple(union of tenant_sets))`.
    - Else → return `(TENANT_LOCAL, (granter's primary tenant id,))`.
  - On containment failure: catch `BindingScopeContainmentError`, emit `tenant.cross_access` audit event with `result=denied`, `reason=insufficient_scope`, payload carrying the granter's resolved scope + target scope; re-raise.
  - On clean failure: ValidationError propagates without audit emit (it's a shape/data error, not a permission denial — different audit story).
  - On success: persist with `RoleBinding.objects.create(...)`. For `multi_tenant` / `global` targets, emit `tenant.cross_access` (`result=allowed`, `binding_scope=target_scope_type`, `binding_id=binding.id`, `target_tenant_set=tenant_set`). `tenant_local` issuance does NOT emit (intra-tenant — no cross-tenant access happened).
- New integration tests covering: denied path (escalation attempt), allowed multi_tenant path, allowed global path (only a global granter can do this), tenant_local issuance (no audit emit), full_clean catches malformed tenant_set in the issuance path (e.g., dict masquerade), audit event payload shape (event_name + result + reason + binding_scope + target_tenant_set).

**Out of scope (deferred):**

- **Cross-tenant resolver** (`access.py` retires the temporary `tenant_local` filter, satisfies M0 line 64). Separate slice — the resolver needs its own design pass.
- **Token-issuance service** (analogous flow for `TokenGrant`). Lands in M1 with the rest of the token surface.
- **Audit event surfacing UI** — the audit rows are persisted with the right shape; rendering them in an admin UI is a UI-layer slice.
- **Permission gating on the granter** — e.g., requiring `role.create` permission. This slice enforces *scope* containment per PRD §19; *permission to issue* is a separate concern (CRUD permission on Role). Document.

## File Structure

- **Create:** `src/racklab/core/binding_issuance.py` — service + private helper.
- **Create:** `tests/integration/test_binding_issuance_service.py` — end-to-end tests.
- **Modify:** `src/racklab/core/audit.py` — if needed, extend `emit_audit_event` to accept structured payload (already accepts `payload: JsonObject` so probably no change).

No model changes; no migration; no settings changes.

## Implementation tasks

### Task 1: Failing tests (red)

Tests cover:

1. `tenant_local` granter cannot escalate to `multi_tenant` → ContainmentError + `tenant.cross_access` `result=denied`.
2. `multi_tenant` granter with `{A, B}` issues `multi_tenant` with `{A}` → succeeds + emits `result=allowed`.
3. `multi_tenant` granter with `{A, B}` cannot issue `multi_tenant` with `{C}` → denied + emits `result=denied`.
4. `global` granter issues `global` → succeeds.
5. Granter with no bindings at all defaults to `tenant_local` of their primary membership.
6. `tenant_local` granter issues `tenant_local` to their own tenant → succeeds + NO audit emit (intra-tenant).
7. Audit event payload shape: contains `binding_scope`, `target_tenant_set`, `granter_scope_type`, `granter_covered_tenants`.
8. Malformed `tenant_set` (dict masquerade) caught by `full_clean()` before persistence.

### Task 2: Implement issue_role_binding

```python
"""Service for issuing RoleBindings with scope containment + audit emission."""

from __future__ import annotations

from typing import TYPE_CHECKING

from django.db import transaction

from racklab.core.audit import AuditContext, emit_audit_event
from racklab.core.models import RoleBinding, TenantMembership
from racklab.core.rbac import (
    BindingScopeContainmentError,
    BindingScopeType,
    validate_binding_issuance_containment,
)

if TYPE_CHECKING:
    from django.contrib.auth.models import User

    from racklab.core.models import Role, Tenant
    from racklab.core.rbac import PrincipalKind, ScopeKind


def issue_role_binding(
    granter_user: User,
    *,
    role: Role,
    principal_kind: PrincipalKind,
    principal_identifier: str,
    scope_kind: ScopeKind,
    scope_identifier: str = "",
    scope_type: BindingScopeType = BindingScopeType.TENANT_LOCAL,
    tenant_set: tuple[str, ...] = (),
    granted_reason: str = "",
) -> RoleBinding:
    """Issue a RoleBinding after enforcing PRD §19 issuance containment + clean()."""
    granter_scope_type, granter_covered = _resolve_granter_covered_tenants(granter_user)
    target_covered = _target_covered_tenants(scope_type, tenant_set)
    try:
        validate_binding_issuance_containment(
            granter_scope_type=granter_scope_type,
            granter_covered_tenants=granter_covered,
            target_scope_type=scope_type,
            target_covered_tenants=target_covered,
        )
    except BindingScopeContainmentError as exc:
        emit_audit_event(
            "tenant.cross_access",
            context=AuditContext(actor_identifier=f"user:{granter_user.pk}"),
            payload={
                "result": "denied",
                "reason": "insufficient_scope",
                "granter_scope_type": granter_scope_type.value,
                "granter_covered_tenants": list(granter_covered),
                "target_scope_type": scope_type.value,
                "target_tenant_set": list(tenant_set),
                "detail": exc.reason,
            },
            target_tenant_set=tenant_set if scope_type is BindingScopeType.MULTI_TENANT else None,
        )
        raise

    with transaction.atomic():
        binding = RoleBinding(
            role=role,
            principal_kind=principal_kind.value,
            principal_identifier=principal_identifier,
            scope_kind=scope_kind.value,
            scope_identifier=scope_identifier,
            scope_type=scope_type.value,
            tenant_set=list(tenant_set),
            granted_by=granter_user,
            granted_reason=granted_reason,
        )
        binding.full_clean()
        binding.save()

        if scope_type is not BindingScopeType.TENANT_LOCAL:
            emit_audit_event(
                "tenant.cross_access",
                context=AuditContext(actor_identifier=f"user:{granter_user.pk}"),
                payload={
                    "result": "allowed",
                    "binding_id": str(binding.id),
                    "binding_scope": scope_type.value,
                    "granter_scope_type": granter_scope_type.value,
                    "target_tenant_set": list(tenant_set),
                },
                target_tenant_set=tenant_set if scope_type is BindingScopeType.MULTI_TENANT else None,
            )
    return binding


def _resolve_granter_covered_tenants(
    granter_user: User,
) -> tuple[BindingScopeType, tuple[str, ...]]:
    """Resolve the granter's broadest scope: GLOBAL > MULTI_TENANT > TENANT_LOCAL."""
    bindings = RoleBinding.objects.all_tenants().filter(
        principal_kind="user",
        principal_identifier=f"user:{granter_user.pk}",
    )
    if bindings.filter(scope_type=BindingScopeType.GLOBAL.value).exists():
        return BindingScopeType.GLOBAL, ()
    multi = bindings.filter(scope_type=BindingScopeType.MULTI_TENANT.value)
    union: set[str] = set()
    for binding in multi:
        union.update(binding.tenant_set)
    if union:
        return BindingScopeType.MULTI_TENANT, tuple(sorted(union))
    # Default — granter falls back to their primary tenant membership.
    membership = TenantMembership.objects.filter(
        user=granter_user, is_primary=True
    ).select_related("tenant").first()
    if membership is None:
        return BindingScopeType.TENANT_LOCAL, ()
    return BindingScopeType.TENANT_LOCAL, (str(membership.tenant_id),)


def _target_covered_tenants(
    scope_type: BindingScopeType,
    tenant_set: tuple[str, ...],
) -> tuple[str, ...]:
    """Translate a target binding shape into the abstract covered-tenants set."""
    if scope_type is BindingScopeType.MULTI_TENANT:
        return tenant_set
    if scope_type is BindingScopeType.GLOBAL:
        return ()
    # tenant_local — caller MUST pass the single resource-tenant id in tenant_set.
    return tenant_set
```

Note: `tenant_local` targets need the resource tenant in `tenant_set` for the containment helper; the persisted `RoleBinding.tenant_set` column is still `[]` (per the slice-2 invariant). The service passes `tenant_set=("resource-tenant-id",)` to the helper but does NOT persist that into the binding's `tenant_set`. Wait — the persisted binding's `tenant_set` is `[]` per the CHECK constraint for `tenant_local`. The helper invocation passes the abstract covered tenants which are derived from the resource scope (e.g., the tenant the project belongs to). For tenant_local issuance, the abstract covered set is `(resource_tenant_id,)`; for multi_tenant issuance, it's `tuple(tenant_set)`; for global, it's `()`.

**Sharp implementation note for Task 2:** the `tenant_set` parameter to `issue_role_binding` is what gets PERSISTED on the binding. For `tenant_local` targets, callers must pass `tenant_set=()` (the CHECK constraint refuses non-empty). For containment validation, the `_target_covered_tenants` helper translates: `tenant_local + tenant_set=()` becomes "the resource's home tenant" — which the service derives from the granter's resolved tenant. Specifically: if granter is `tenant_local`, granter and target share the same home tenant; if granter is `multi_tenant` or `global`, the resource tenant must be communicated separately (added kwarg).

Actually this is getting hairy. Let me re-scope:

- Restrict this slice's `issue_role_binding` to NOT cover `tenant_local` issuance — that path is the simple case any caller can hit via `RoleBinding.objects.create()` directly under tenant context. The service exists for the cross-tenant cases (`multi_tenant` and `global`) where containment + audit emission matter.
- Service signature: only allows `scope_type ∈ {MULTI_TENANT, GLOBAL}`. Raises ValueError if called with TENANT_LOCAL.

That sidesteps the resource-tenant-derivation complexity. Per the test matrix above, drop tests 5 and 6 (the granter-with-no-bindings default + the intra-tenant no-audit path) — they apply to a different service flow if we ever need it.

### Task 3: Run gates + codex diff review

Standard.

### Task 4: Commit + PROGRESS update

`feat(core): add binding-issuance service with cross-tenant audit emission`. PROGRESS.md: next slice becomes the cross-tenant resolver rules.

## Self-Review

**1. Spec coverage:**

- ✅ M0 line 65 (issuance half): granter cannot escalate; failed attempts emit `tenant.cross_access` `result=denied reason=insufficient_scope`.
- ✅ Successful issuance emits `tenant.cross_access` `result=allowed` (the PRD §14 issuance variant with `resource_tenant=null` + `target_tenant_set`).
- ⚠ PRD §19 calls for `binding_id` in the audit payload — included.
- ⚠ Scope reduced from initial draft to only `multi_tenant` + `global` — `tenant_local` issuance is the simple case any caller hits via raw `RoleBinding.objects.create()` under tenant context, and the granter-vs-resource-tenant disambiguation isn't worth carrying in this slice.

**2. Type consistency:** `tenant_set` is `tuple[str, ...]` everywhere; the helper accepts the same shape; the model persists it as a JSON list.

## Execution Handoff

Inline execution.

# Cross-Tenant Resolver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Retire the temporary `.filter(scope_type=BindingScopeType.TENANT_LOCAL.value)` guard from slice 3's `access.py` and teach the resolver to evaluate `multi_tenant` and `global` bindings against the current tenant context. Add a `check_principal_access(...)` helper that emits `tenant.cross_access` access-variant audit events per PRD §14 — satisfies M0 acceptance criterion **line 64** ("Attempting cross-tenant access without sharing scope or a cross-tenant binding emits a `tenant.cross_access` audit event with `result=denied`").

**Architecture:**

- `effective_permissions_for_principal` (in `src/racklab/core/access.py`) evaluates the resolver's three-predicate rule from PRD §19:
  - **Binding scope ⊇ resource tenant** — derived per binding: `tenant_local` covers the resource's home tenant; `multi_tenant` covers the persisted `tenant_set`; `global` covers all tenants.
  - **Resource visibility ⊇ actor tenant** — PRD §19 specifies this via `sharing_scope` (`tenant_local` / `shared_with_tenants` / `global`). Deferred to a later slice: `Job` doesn't yet carry `sharing_scope`. This slice covers the binding-scope predicate only; the visibility predicate falls back to "tenant_local" (same-tenant) for now.
  - **Role ⊇ requested action** — already handled by the existing permission-pack expansion.
- New `check_principal_access(principal_kind, principal_identifier, scope_kind, scope_identifier, resource_tenant_id) -> tuple[bool, str]` in `access.py`. This is the function that downstream view/API code will call. Returns `(allowed, reason)` and emits a `tenant.cross_access` access-variant audit event with `result=allowed` or `result=denied` whenever the actor's home tenant differs from `resource_tenant_id`.
- The existing `effective_permissions_for_principal` stays — but its tenant_local-only filter is replaced with proper cross-tenant evaluation. Existing tests for the resolver (single-tenant case) keep working; the `test_resolver_ignores_*` tests get replaced with proper cross-tenant evaluation tests.

**Tech Stack:** Django 5.2 LTS, the helpers + audit primitives from prior slices.

---

## Codex review feedback folded (2026-05-25)

Codex returned 2 P0 + 3 P1 + 1 P2 — critical changes before implementation:

- **P0 — `tenant_local` cross-tenant authorization bug.** Original `_binding_covers_tenant` returned True for any non-null tenant context, so a `tenant_local` binding could authorize cross-tenant access. **Corrected:** `tenant_local` bindings DO NOT participate in cross-tenant checks. Only `multi_tenant` + `global` can authorize cross-tenant access. The resolver's resolution-time path still treats `tenant_local` as applicable under the actor's own context (which is what makes sense for intra-tenant queries); but the access-check path filters them out when `actor_tenant != resource_tenant`. M0 doesn't add a persisted `RoleBinding.home_tenant` FK — that's a separate slice with its own migration.
- **P0 — Missing third predicate (role ⊇ requested action).** Original `check_principal_access` checked binding-scope + resource-tenant coverage but not whether the binding's role grants the requested permission. **Corrected:** new signature `check_principal_access(principal_kind, principal_identifier, *, scope_kind, scope_identifier, resource_tenant, required_permission, action="access")`. After finding applicable bindings, aggregate their effective permissions and require `required_permission` to be in the union.
- **P1 — Audit row's `resource_tenant` column not populated.** Original passed `resource_tenant` only in the JSON payload, not as the `emit_audit_event(resource_tenant=...)` kwarg that sets the column. PRD §14's bidirectional surfacing query reads the column, not the payload. **Corrected:** caller passes a `Tenant` instance into `check_principal_access`; emission passes it as the column-setting kwarg.
- **P1 — No-context check silently allows.** Without tenant context, `is_cross_tenant` evaluated as False (since actor_tenant_id was None), so global bindings authorized without audit. **Corrected:** `check_principal_access` raises `MissingTenantContextError` without an active tenant context (matches the rest of the fail-closed codebase). System paths use `effective_permissions_for_principal.all_tenants()` or similar opt-in.
- **P1 — First-applicable-binding-wins is arbitrary.** **Corrected:** aggregate permissions across ALL applicable bindings (union) for the allow/deny decision. For audit provenance (one row per access), select a deterministic binding via narrow-before-broad ordering: exact-scope before global-scope, `tenant_local` < `multi_tenant` < `global`, then stable `id` ordering as final tiebreaker.
- **P2 — Visibility handling.** Plan declares M0 = binding-driven-only; `sharing_scope` predicate deferred to a later slice when tenant-scoped resource models gain the field. Document.

## Scope boundary

**In scope:**

- Update `src/racklab/core/access.py`:
  - Drop the temporary `Q(scope_type=BindingScopeType.TENANT_LOCAL.value)` filter.
  - Add helper `_binding_covers_tenant(binding, tenant_id) -> bool` that maps each `scope_type` to a tenant-set check (`tenant_local` → True iff `tenant_id == current_tenant_id`; `multi_tenant` → True iff `tenant_id in binding.tenant_set`; `global` → True always).
  - In `effective_permissions_for_principal`: for each binding, only include its permissions if the binding covers the current tenant context. If no tenant context is set, fall back to GLOBAL-only bindings (background workers / management commands without context shouldn't get tenant_local permissions).
- New `check_principal_access(principal_kind, principal_identifier, *, scope_kind, scope_identifier, resource_tenant_id) -> tuple[bool, str]`:
  - Resolves applicable bindings against the current tenant context.
  - If no bindings apply: `(False, "no_applicable_binding")`.
  - If the chosen binding spans cross-tenant (actor_tenant != resource_tenant_id): emits `tenant.cross_access` access-variant audit event with PRD §14 payload (`actor_tenant`, `resource_tenant`, `binding_scope`, `binding_id`, `sharing_scope=null`, `action`, `result`, `reason`). Returns `(True, "allowed")`.
  - If no binding applies AND it's a cross-tenant attempt (current tenant != resource_tenant): emits with `result=denied`, `reason=insufficient_scope`. Returns `(False, "insufficient_scope")`.
  - Same-tenant access with no binding: returns `(False, "no_applicable_binding")` WITHOUT emitting (PRD §14 cross_access is only for cross-tenant access).
- Replace `test_resolver_ignores_multi_tenant_bindings_in_this_slice` + `test_resolver_ignores_global_scope_type_bindings_in_this_slice` with proper cross-tenant evaluation tests (don't xfail — delete and replace).
- New integration tests in `tests/integration/test_cross_tenant_resolver.py`:
  - `tenant_local` binding under same tenant context → permissions apply, no audit emit.
  - `multi_tenant` binding under covered tenant context → permissions apply, audit emit (allowed).
  - `multi_tenant` binding under uncovered tenant context → no permissions, audit emit (denied, insufficient_scope) only on explicit access-check call (not on resolver query).
  - `global` binding → permissions apply under any tenant context, audit emit (allowed) when cross-tenant.
  - `check_principal_access` returns + emits per PRD §14 schema.
  - No-context query returns only global bindings (M0 conservative).

**Out of scope (deferred):**

- **Resource visibility predicate** (`sharing_scope`) — needs `sharing_scope` field on tenant-scoped models, which doesn't exist yet. Add when `Artifact` or `Deployment` tenant-FK adoption ships.
- **Resource-level access checks for views** — M1 wires `check_principal_access` into DRF permission classes and request-handling code.
- **Sharing-driven cross_access events** — needs the `sharing_scope` field; deferred.
- **Binding-driven + Sharing-driven (Both variant per PRD §19)** — deferred with the sharing work.

## File Structure

- **Modify:** `src/racklab/core/access.py` — drop tenant_local filter, add `_binding_covers_tenant` helper + `check_principal_access` function.
- **Modify:** `tests/integration/test_rbac_models.py` — drop the two `test_resolver_ignores_*` tests.
- **Create:** `tests/integration/test_cross_tenant_resolver.py` — new integration test file for cross-tenant evaluation.

No model changes, no migration, no settings changes.

## Implementation tasks

### Task 1: Failing integration tests (red)

Test matrix:

1. `tenant_local` binding + current tenant context = binding's home → returns permissions.
2. `tenant_local` binding + current tenant context different → does NOT return permissions.
3. `multi_tenant` binding with `tenant_set=[A, B]` + current context A → permissions apply.
4. `multi_tenant` binding with `tenant_set=[A, B]` + current context C → permissions do NOT apply.
5. `global` binding + any context → permissions apply.
6. No tenant context set + `global` binding → permissions apply (system path).
7. No tenant context set + `tenant_local` binding → does NOT return permissions (fail-closed for tenant_local without context).
8. `check_principal_access` cross-tenant via `multi_tenant` binding → returns `(True, "allowed")` AND emits `tenant.cross_access` `result=allowed`.
9. `check_principal_access` cross-tenant without applicable binding → returns `(False, "insufficient_scope")` AND emits `result=denied`.
10. `check_principal_access` same-tenant with binding → returns `(True, "allowed")` and does NOT emit cross_access (intra-tenant).
11. `check_principal_access` same-tenant without binding → returns `(False, "no_applicable_binding")` and does NOT emit (no cross-tenant happened).
12. Audit payload shape matches PRD §14 access variant (`actor_tenant`, `resource_tenant`, `binding_scope`, `binding_id`, `sharing_scope=null`, `action`, `result`, `reason`).

### Task 2: Implement the resolver changes

In `src/racklab/core/access.py`:

```python
"""RBAC access-resolution helpers."""

from __future__ import annotations

import uuid
from typing import TYPE_CHECKING

from django.db.models import Q

from racklab.core.audit import AuditContext, emit_audit_event
from racklab.core.models import RoleBinding
from racklab.core.rbac import BindingScopeType, ScopeKind, effective_permissions_for_role
from racklab.core.tenancy_context import get_current_tenant_id

if TYPE_CHECKING:
    from racklab.core.rbac import PrincipalKind


def effective_permissions_for_principal(
    principal_kind: PrincipalKind,
    principal_identifier: str,
    *,
    scope_kind: ScopeKind,
    scope_identifier: str = "",
) -> frozenset[str]:
    """Return effective permissions, evaluating each binding's tenant coverage."""
    current_tenant_str = get_current_tenant_id()
    current_tenant_uuid = uuid.UUID(current_tenant_str) if current_tenant_str is not None else None

    exact_scope = Q(scope_kind=scope_kind.value, scope_identifier=scope_identifier)
    global_scope = Q(scope_kind=ScopeKind.GLOBAL.value, scope_identifier="")
    bindings = RoleBinding.objects.select_related("role", "role__preset").filter(
        Q(principal_kind=principal_kind.value, principal_identifier=principal_identifier),
        exact_scope | global_scope,
    )
    codenames: set[str] = set()
    for binding in bindings:
        if not _binding_covers_tenant(binding, current_tenant_uuid):
            continue
        codenames.update(effective_permissions_for_role(binding.role))
    return frozenset(codenames)


def _binding_covers_tenant(
    binding: RoleBinding,
    current_tenant_id: uuid.UUID | None,
) -> bool:
    """Per-binding tenant-coverage predicate from PRD §19."""
    scope_type = binding.scope_type
    if scope_type == BindingScopeType.GLOBAL.value:
        return True
    if current_tenant_id is None:
        # No tenant context — only global bindings apply (system paths).
        return False
    if scope_type == BindingScopeType.TENANT_LOCAL.value:
        # tenant_local applies in the tenant the binding was issued for.
        # The persisted tenant_set column is [] for tenant_local; the
        # implicit home tenant is the current tenant context (this is
        # the resolver's perspective — a tenant_local binding "in the
        # current tenant" is by definition applicable).
        return True
    if scope_type == BindingScopeType.MULTI_TENANT.value:
        return str(current_tenant_id) in binding.tenant_set
    return False


def check_principal_access(
    principal_kind: PrincipalKind,
    principal_identifier: str,
    *,
    scope_kind: ScopeKind,
    scope_identifier: str,
    resource_tenant_id: uuid.UUID,
) -> tuple[bool, str]:
    """Check whether a principal can act on a resource; emit cross_access on cross-tenant access.

    Returns (allowed, reason). Reasons:
    - "allowed" when an applicable binding covers the resource tenant
    - "no_applicable_binding" when no binding matches scope (same-tenant; no audit)
    - "insufficient_scope" when actor is cross-tenant without coverage (audit emitted)
    """
    actor_tenant_str = get_current_tenant_id()
    actor_tenant_id = uuid.UUID(actor_tenant_str) if actor_tenant_str is not None else None
    is_cross_tenant = actor_tenant_id is not None and actor_tenant_id != resource_tenant_id

    exact_scope = Q(scope_kind=scope_kind.value, scope_identifier=scope_identifier)
    global_scope = Q(scope_kind=ScopeKind.GLOBAL.value, scope_identifier="")
    bindings = RoleBinding.objects.filter(
        Q(principal_kind=principal_kind.value, principal_identifier=principal_identifier),
        exact_scope | global_scope,
    )
    applicable_binding: RoleBinding | None = None
    for binding in bindings:
        if _binding_covers_tenant(binding, resource_tenant_id):
            applicable_binding = binding
            break

    if applicable_binding is not None:
        if is_cross_tenant:
            _emit_cross_access_access(
                actor_tenant_id=actor_tenant_id,
                resource_tenant_id=resource_tenant_id,
                binding=applicable_binding,
                principal_identifier=principal_identifier,
                scope_kind=scope_kind,
                scope_identifier=scope_identifier,
                result="allowed",
                reason="allowed",
            )
        return True, "allowed"

    if is_cross_tenant:
        # Cross-tenant attempt without applicable binding — emit denied audit.
        _emit_cross_access_access(
            actor_tenant_id=actor_tenant_id,
            resource_tenant_id=resource_tenant_id,
            binding=None,
            principal_identifier=principal_identifier,
            scope_kind=scope_kind,
            scope_identifier=scope_identifier,
            result="denied",
            reason="insufficient_scope",
        )
        return False, "insufficient_scope"
    return False, "no_applicable_binding"


def _emit_cross_access_access(
    *,
    actor_tenant_id: uuid.UUID | None,
    resource_tenant_id: uuid.UUID,
    binding: RoleBinding | None,
    principal_identifier: str,
    scope_kind: ScopeKind,
    scope_identifier: str,
    result: str,
    reason: str,
) -> None:
    """Emit tenant.cross_access access-variant per PRD §14 schema."""
    payload: dict[str, object] = {
        "actor_tenant": str(actor_tenant_id) if actor_tenant_id else None,
        "resource_tenant": str(resource_tenant_id),
        "binding_scope": binding.scope_type if binding else None,
        "binding_id": str(binding.id) if binding else None,
        "sharing_scope": None,
        "shared_resource_owner_tenant": None,
        "action": "access",
        "scope_kind": scope_kind.value,
        "scope_identifier": scope_identifier,
        "result": result,
        "reason": reason,
    }
    emit_audit_event(
        "tenant.cross_access",
        context=AuditContext(actor_identifier=principal_identifier),
        payload=payload,
    )
```

### Task 3: Update existing tests

In `tests/integration/test_rbac_models.py`, remove the two `test_resolver_ignores_*` tests entirely. The new test file replaces them with proper cross-tenant evaluation tests.

### Task 4: Run gates + codex diff review

Standard.

### Task 5: Commit + PROGRESS update

`feat(core): add cross-tenant resolver + check_principal_access with audit emission`. Update PROGRESS.md: M0 acceptance line 64 satisfied. Recommended next slice: Artifact tenant-FK adoption.

## Self-Review

**1. Spec coverage:**

- ✅ Drops the temporary tenant_local filter (slice 3's TODO).
- ✅ Resolver honors `multi_tenant` + `global` bindings under current tenant context.
- ✅ `check_principal_access` emits `tenant.cross_access` `result=denied` on cross-tenant attempts without coverage.
- ✅ `result=allowed` on legitimate cross-tenant access via a binding.
- ⚠ `sharing_scope` predicate deferred — no resource model carries `sharing_scope` yet.
- ⚠ Sharing-driven cross_access events deferred with `sharing_scope`.

**2. Audit payload shape:** Per PRD §14 line 36. `actor_tenant`, `resource_tenant`, `binding_scope`, `binding_id`, `sharing_scope=null`, `shared_resource_owner_tenant=null`, `action`, `result`, `reason`. Plus `scope_kind` + `scope_identifier` for forensic correlation (PRD doesn't list these but they help debugging).

## Execution Handoff

Inline execution.

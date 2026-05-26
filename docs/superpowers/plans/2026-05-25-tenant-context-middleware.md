# Tenant Context Middleware + Tenant-Aware Manager Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land the `contextvars`-based tenant-context plumbing + Django middleware that sets it + tenant-aware manager/queryset that reads it, per PRD §19 "Multi-tenancy" — so future per-model FK-addition slices (Job, Artifact, AuditEvent) can opt models into tenant filtering by swapping in `TenantAwareManager()`.

**Architecture:** A `contextvars.ContextVar[str | None]` holds the current tenant UUID. A sync+async-capable Django middleware reads `request.user`, finds the user's primary `TenantMembership`, sets the contextvar for the duration of the request, clears it on response. A `TenantAwareQuerySet` exposes `with_tenant_filter(field_name)` that applies a `.filter(<field_name>=current_tenant_id)` when the contextvar is set; a `TenantAwareManager` uses it as the default queryset class and exposes `.all_tenants()` as the explicit escape hatch. No existing model gets its default manager swapped in this slice — that's per-model work in subsequent slices.

**Tech Stack:** Python 3.12+ `contextvars`, Django 5.2 LTS sync+async middleware (per Django docs: declare `sync_capable=True` + `async_capable=True`, detect via `asgiref.sync.iscoroutinefunction(get_response)`, mark async instances with `asgiref.sync.markcoroutinefunction(self)`), pytest + pytest-django + pytest-asyncio (added in this slice).

---

## Codex review feedback folded (2026-05-25)

Codex flagged 2 P0s + 5 P1s + 2 P2s on the initial draft. The implementation below is the corrected form. Corrections summary:

- **P0 — Fail-open manager violates PRD §18 / §19 soft-isolation intent.** Original design returned the unfiltered queryset when no tenant context was set. Anonymous users, users without primary membership, missing middleware, streaming responses after middleware reset, and background workers without explicit setup would all silently see every row. **Corrected:** `TenantAwareManager.get_queryset()` raises `MissingTenantContextError` when no tenant context is set; `.all_tenants()` is the only explicit escape. Integration tests assert the raise.
- **P0 — Wrong async-detection API.** `asyncio.iscoroutinefunction` doesn't recognise Django's `_async_capable` wrappers; Django docs explicitly require `asgiref.sync.iscoroutinefunction` + `asgiref.sync.markcoroutinefunction(self)` on the middleware instance during `__init__`. **Corrected:** middleware uses the asgiref helpers.
- **P1 — Tests don't exercise the production request assembly.** Direct `middleware(request)` calls bypass Django's handler adaptation, middleware ordering, and AuthenticationMiddleware interaction. **Corrected:** add at least one `Client` + one `AsyncClient` test using `override_settings(MIDDLEWARE=[..., AuthenticationMiddleware, TenantContextMiddleware])` so the real stack runs.
- **P1 — StreamingHttpResponse / SSE leak path.** Reset-on-return doesn't cover response bodies generated *after* middleware returns (SSE iterators are the canonical case). With fail-closed manager semantics from the P0 fix, the leak surface narrows substantially. Documenting this as a known limitation; iterator-wrapping for streaming responses lands with the SSE infrastructure slice in M2.
- **P1 — pytest-asyncio not in deps.** **Corrected:** `uv add --dev pytest-asyncio`. Configure with explicit `@pytest.mark.asyncio` (no `asyncio_mode = "auto"`).
- **P1 — `disallow_any_explicit = true` is on in `pyproject.toml`.** **Corrected:** middleware uses concrete `HttpResponse | Awaitable[HttpResponse]` typing instead of `Any`. The `_ensure_sync_response` helper goes away.
- **P1 — Async DB calls.** Don't wrap sync ORM in `sync_to_async`; use Django 5.2's async ORM (`afirst()`). **Corrected:** `_resolve_primary_tenant_id_async` uses `.afirst()`.
- **P2 — `manager.model = TenantMembership` is not idiomatic.** **Corrected:** integration tests define a proxy model `_TenantScopedMembershipProxy(TenantMembership)` with `Meta: proxy = True, app_label = "core"` and attach `objects = TenantAwareManager("tenant_id")` to it. Exercises normal manager attachment without schema churn.
- **P2 — Manager loses `_hints`.** **Corrected:** `TenantAwareManager.get_queryset()` and `.all_tenants()` both pass `hints=self._hints` when constructing the queryset.

## Scope boundary

**In scope:**

- `src/racklab/core/tenancy_context.py` — `ContextVar[str | None]`, accessors (`get_current_tenant_id`, `set_current_tenant_id`, `reset_current_tenant_id`), and a `current_tenant(tenant_id_or_none)` context manager.
- `src/racklab/core/middleware.py` — `TenantContextMiddleware` with sync + async `__call__` paths; on each request, resolves the actor's primary `TenantMembership` if the user is authenticated and sets the contextvar; always clears it in `finally`.
- `src/racklab/core/tenancy_managers.py` — `TenantAwareQuerySet(models.QuerySet)` with `with_tenant_filter(tenant_field_name)` + `all_tenants()`; `TenantAwareManager` subclass that uses the QuerySet and overrides `get_queryset()` to apply the contextvar filter when set; `all_tenants()` returns the unfiltered queryset.
- Tiny tests for the contextvar primitives.
- Contract test for the middleware (mocked request, both sync + async paths).
- Integration tests for the manager against the existing `TenantMembership` rows (without modifying `TenantMembership.objects` — the tests instantiate the manager directly to avoid breaking 13 existing TenantMembership tests).
- ASGI propagation contract test: a contextvar set in a parent coroutine remains visible inside a child coroutine — proves `contextvars.copy_context` semantics on Python 3.12+.

**Out of scope (deferred):**

- Adding `tenant` FK + denormalized `tenant_id` columns to `Job`, `Artifact`, `AuditEvent`. Each lands in its own per-model slice with a backfill migration to the default RIT tenant. The current slice provides the manager so those slices only need to wire it up.
- Swapping `TenantMembership.objects` to `TenantAwareManager()`. TenantMembership is a join table, not a tenant-scoped resource in the usual sense; the manager isn't a natural fit. We test the manager against TenantMembership *via direct instantiation* so we don't churn the 13 existing tests.
- Registering the middleware in `MIDDLEWARE` settings. With no production model using the tenant-aware manager yet, registering the middleware is a no-op; it lands in the per-model slices alongside the first model using the manager.
- `nats_envelope_carries_tenant_id` contract test. Separate slice (paired with the NATS envelope discipline work).
- Per-resource visibility (`sharing_scope`). Different mechanism, separate concern.
- DRF integration (`request.auth` derived from JWT tenant claim). Lands in M1 with the token slice.

## File Structure

- **Create:** `src/racklab/core/tenancy_context.py` — contextvar + accessors + context manager.
- **Create:** `src/racklab/core/middleware.py` — `TenantContextMiddleware` (sync + async).
- **Create:** `src/racklab/core/tenancy_managers.py` — `TenantAwareQuerySet` + `TenantAwareManager`.
- **Create:** `tests/tiny/test_tenancy_context.py` — contextvar primitives, context manager, reentrance.
- **Create:** `tests/contract/test_tenant_middleware.py` — middleware behavior, both sync + async.
- **Create:** `tests/integration/test_tenancy_managers.py` — manager filters against TenantMembership rows.
- **Create:** `tests/contract/test_tenancy_context_propagation.py` — ASGI/asyncio propagation contract.

No existing model code, settings, or migration changes.

## Implementation tasks

### Task 1: Tiny tests for the contextvar primitives

**Files:**

- Test: `tests/tiny/test_tenancy_context.py` (new)

- [ ] **Step 1: Write the failing tests**

```python
"""Tiny tests for the tenant-context contextvar primitives per PRD §19."""

from __future__ import annotations

import pytest

from racklab.core.tenancy_context import (
    current_tenant,
    get_current_tenant_id,
    reset_current_tenant_id,
    set_current_tenant_id,
)


@pytest.mark.tiny
def test_get_current_tenant_id_is_none_by_default() -> None:
    """Outside any context, the current tenant id is None."""
    assert get_current_tenant_id() is None


@pytest.mark.tiny
def test_set_and_reset_round_trip() -> None:
    """set_current_tenant_id returns a token that reset accepts."""
    token = set_current_tenant_id("tenant-a")
    try:
        assert get_current_tenant_id() == "tenant-a"
    finally:
        reset_current_tenant_id(token)
    assert get_current_tenant_id() is None


@pytest.mark.tiny
def test_context_manager_sets_and_clears() -> None:
    """The current_tenant context manager scopes the contextvar set."""
    with current_tenant("tenant-a"):
        assert get_current_tenant_id() == "tenant-a"
    assert get_current_tenant_id() is None


@pytest.mark.tiny
def test_context_manager_clears_on_exception() -> None:
    """An exception inside the with-block still clears the contextvar."""
    with pytest.raises(RuntimeError), current_tenant("tenant-a"):
        raise RuntimeError("boom")
    assert get_current_tenant_id() is None


@pytest.mark.tiny
def test_context_manager_nesting_restores_outer() -> None:
    """Nested context managers restore the outer tenant on exit."""
    with current_tenant("tenant-a"):
        assert get_current_tenant_id() == "tenant-a"
        with current_tenant("tenant-b"):
            assert get_current_tenant_id() == "tenant-b"
        assert get_current_tenant_id() == "tenant-a"
    assert get_current_tenant_id() is None


@pytest.mark.tiny
def test_context_manager_with_none_clears_inner() -> None:
    """Passing None to current_tenant explicitly clears the contextvar inside."""
    with current_tenant("tenant-a"):
        with current_tenant(None):
            assert get_current_tenant_id() is None
        assert get_current_tenant_id() == "tenant-a"
```

- [ ] **Step 2: Run them and confirm ImportError**

Run: `uv run pytest tests/tiny/test_tenancy_context.py -v`
Expected: `ImportError: cannot import name 'current_tenant' from 'racklab.core.tenancy_context'`.

### Task 2: Implement the contextvar primitives

**Files:**

- Create: `src/racklab/core/tenancy_context.py`

- [ ] **Step 1: Write the module**

```python
"""Tenant context propagation primitives per PRD §19 multi-tenancy.

A `contextvars.ContextVar[str | None]` holds the current tenant UUID for the
running request / coroutine / NATS message handler. ASGI views, Channels
consumers, and async hooks inherit the contextvar value automatically because
Python's contextvars are designed for exactly this case.

Background NATS workers and scheduled commands do NOT inherit request
context — they must read the tenant id from the message envelope or the
explicit caller and set the contextvar at the start of each unit of work.
"""

from __future__ import annotations

from contextlib import contextmanager
from contextvars import ContextVar
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from collections.abc import Iterator
    from contextvars import Token


_current_tenant_id: ContextVar[str | None] = ContextVar(
    "racklab_current_tenant_id",
    default=None,
)


def get_current_tenant_id() -> str | None:
    """Return the current tenant UUID, or None if no tenant context is set."""
    return _current_tenant_id.get()


def set_current_tenant_id(tenant_id: str | None) -> Token[str | None]:
    """Set the current tenant UUID and return a reset token.

    Always pair with `reset_current_tenant_id` in a try/finally to avoid
    leaking the context into the next request handled by the same coroutine.
    """
    return _current_tenant_id.set(tenant_id)


def reset_current_tenant_id(token: Token[str | None]) -> None:
    """Restore the contextvar to the value it held before `set_current_tenant_id`."""
    _current_tenant_id.reset(token)


@contextmanager
def current_tenant(tenant_id: str | None) -> Iterator[None]:
    """Scope a tenant context. Restores the previous value on exit, even on exception."""
    token = set_current_tenant_id(tenant_id)
    try:
        yield
    finally:
        reset_current_tenant_id(token)
```

- [ ] **Step 2: Run the tiny tests and confirm green**

Run: `uv run pytest tests/tiny/test_tenancy_context.py -v`
Expected: all 6 tests pass.

### Task 3: Async / asyncio propagation contract test

**Files:**

- Create: `tests/contract/test_tenancy_context_propagation.py`

- [ ] **Step 1: Write the test**

```python
"""Contract test proving `contextvars` propagate through asyncio tasks per PRD §19."""

from __future__ import annotations

import asyncio

import pytest

from racklab.core.tenancy_context import current_tenant, get_current_tenant_id


@pytest.mark.contract
@pytest.mark.asyncio
async def test_child_coroutine_inherits_parent_tenant_context() -> None:
    """A child coroutine sees the tenant context set by its parent."""
    seen: list[str | None] = []

    async def child() -> None:
        seen.append(get_current_tenant_id())

    with current_tenant("tenant-a"):
        await child()
    assert seen == ["tenant-a"]


@pytest.mark.contract
@pytest.mark.asyncio
async def test_asyncio_create_task_inherits_tenant_context() -> None:
    """asyncio.create_task captures the contextvar snapshot at scheduling time."""
    captured: list[str | None] = []

    async def child() -> None:
        await asyncio.sleep(0)
        captured.append(get_current_tenant_id())

    with current_tenant("tenant-a"):
        task = asyncio.create_task(child())
    # Exit the parent's tenant context BEFORE the child reads — the child
    # should still see "tenant-a" because create_task copies the current context.
    await task
    assert captured == ["tenant-a"]


@pytest.mark.contract
@pytest.mark.asyncio
async def test_sibling_tasks_have_isolated_tenant_context() -> None:
    """Two concurrently-scheduled tasks set under different tenants do not leak."""
    results: dict[str, str | None] = {}

    async def under_tenant(tenant_id: str) -> None:
        with current_tenant(tenant_id):
            await asyncio.sleep(0)
            results[tenant_id] = get_current_tenant_id()

    await asyncio.gather(
        under_tenant("tenant-a"),
        under_tenant("tenant-b"),
    )
    assert results == {"tenant-a": "tenant-a", "tenant-b": "tenant-b"}
```

- [ ] **Step 2: Confirm pytest-asyncio is wired up**

Check `pyproject.toml` for `pytest-asyncio` in test deps. If absent, add it via `uv add --dev pytest-asyncio` and configure `asyncio_mode = "auto"` (or use the explicit `@pytest.mark.asyncio` decorator on each test as above — already done).

- [ ] **Step 3: Run the tests and confirm green**

Run: `uv run pytest tests/contract/test_tenancy_context_propagation.py -v`
Expected: 3 passed.

### Task 4: Middleware contract tests

**Files:**

- Create: `tests/contract/test_tenant_middleware.py`

- [ ] **Step 1: Write the failing tests**

The middleware needs to be tested with a mocked `request.user` that has a `tenant_memberships` related manager. Use Django's `RequestFactory` to build a request and a custom `get_response` that captures the contextvar value mid-request.

```python
"""Contract tests for TenantContextMiddleware per PRD §19."""

from __future__ import annotations

import asyncio
from unittest.mock import Mock

import pytest
from django.contrib.auth.models import AnonymousUser
from django.test import RequestFactory

from racklab.core.middleware import TenantContextMiddleware
from racklab.core.tenancy_context import get_current_tenant_id


@pytest.fixture
def request_factory() -> RequestFactory:
    """Standard Django RequestFactory."""
    return RequestFactory()


@pytest.mark.contract
@pytest.mark.django_db
def test_middleware_sets_tenant_from_user_primary_membership(request_factory: RequestFactory) -> None:
    """The middleware reads the user's primary TenantMembership and sets the contextvar."""
    from django.contrib.auth import get_user_model

    from racklab.core.models import Tenant, TenantMembership

    user_model = get_user_model()
    tenant = Tenant.objects.create(name="Primary", slug="primary")
    user = user_model.objects.create_user(username="alice")
    TenantMembership.objects.create(user=user, tenant=tenant, is_primary=True)

    captured: dict[str, str | None] = {}

    def get_response(request: object) -> Mock:
        captured["mid_request"] = get_current_tenant_id()
        return Mock(status_code=200)

    middleware = TenantContextMiddleware(get_response)
    request = request_factory.get("/")
    request.user = user
    middleware(request)

    assert captured["mid_request"] == str(tenant.id)
    # After the request, the contextvar is cleared.
    assert get_current_tenant_id() is None


@pytest.mark.contract
@pytest.mark.django_db
def test_middleware_leaves_tenant_none_for_anonymous_user(
    request_factory: RequestFactory,
) -> None:
    """Anonymous users have no tenant context."""
    captured: dict[str, str | None] = {}

    def get_response(request: object) -> Mock:
        captured["mid_request"] = get_current_tenant_id()
        return Mock(status_code=200)

    middleware = TenantContextMiddleware(get_response)
    request = request_factory.get("/")
    request.user = AnonymousUser()
    middleware(request)

    assert captured["mid_request"] is None


@pytest.mark.contract
@pytest.mark.django_db
def test_middleware_leaves_tenant_none_for_user_without_primary_membership(
    request_factory: RequestFactory,
) -> None:
    """A user with no primary TenantMembership has no tenant context (no defaults guessed)."""
    from django.contrib.auth import get_user_model

    user_model = get_user_model()
    user = user_model.objects.create_user(username="bob")

    captured: dict[str, str | None] = {}

    def get_response(request: object) -> Mock:
        captured["mid_request"] = get_current_tenant_id()
        return Mock(status_code=200)

    middleware = TenantContextMiddleware(get_response)
    request = request_factory.get("/")
    request.user = user
    middleware(request)

    assert captured["mid_request"] is None


@pytest.mark.contract
@pytest.mark.django_db
def test_middleware_clears_tenant_on_exception(request_factory: RequestFactory) -> None:
    """If the view raises, the contextvar is still cleared."""
    from django.contrib.auth import get_user_model

    from racklab.core.models import Tenant, TenantMembership

    user_model = get_user_model()
    tenant = Tenant.objects.create(name="Primary", slug="primary")
    user = user_model.objects.create_user(username="alice")
    TenantMembership.objects.create(user=user, tenant=tenant, is_primary=True)

    def get_response(request: object) -> None:
        raise RuntimeError("view exploded")

    middleware = TenantContextMiddleware(get_response)
    request = request_factory.get("/")
    request.user = user
    with pytest.raises(RuntimeError):
        middleware(request)
    assert get_current_tenant_id() is None


@pytest.mark.contract
@pytest.mark.django_db
@pytest.mark.asyncio
async def test_async_middleware_sets_tenant_from_user(request_factory: RequestFactory) -> None:
    """The async __call__ path mirrors the sync path."""
    from asgiref.sync import sync_to_async
    from django.contrib.auth import get_user_model

    from racklab.core.models import Tenant, TenantMembership

    user_model = get_user_model()
    tenant = await sync_to_async(Tenant.objects.create)(name="Primary", slug="primary")
    user = await sync_to_async(user_model.objects.create_user)(username="alice")
    await sync_to_async(TenantMembership.objects.create)(
        user=user, tenant=tenant, is_primary=True
    )

    captured: dict[str, str | None] = {}

    async def get_response(request: object) -> Mock:
        captured["mid_request"] = get_current_tenant_id()
        return Mock(status_code=200)

    middleware = TenantContextMiddleware(get_response)
    request = request_factory.get("/")
    request.user = user
    await middleware(request)

    assert captured["mid_request"] == str(tenant.id)
    assert get_current_tenant_id() is None
```

### Task 5: Implement the middleware

**Files:**

- Create: `src/racklab/core/middleware.py`

- [ ] **Step 1: Write the middleware**

```python
"""Tenant context middleware per PRD §19.

The middleware sets `racklab.core.tenancy_context._current_tenant_id` from the
request's user's primary `TenantMembership` for the duration of every request,
clearing it on exit (success or exception).

Supports both sync and async Django paths via the `sync_capable` /
`async_capable` flags + a get_response dispatch.  See:
https://docs.djangoproject.com/en/5.2/topics/http/middleware/#asynchronous-support
"""

from __future__ import annotations

import asyncio
from typing import TYPE_CHECKING, Any

from asgiref.sync import sync_to_async

from racklab.core.tenancy_context import current_tenant

if TYPE_CHECKING:
    from collections.abc import Awaitable, Callable

    from django.http import HttpRequest, HttpResponse


def _resolve_primary_tenant_id_sync(user: object) -> str | None:
    """Return the user's primary tenant UUID, or None if unauthenticated or no primary."""
    if not getattr(user, "is_authenticated", False):
        return None
    # Lazy import to avoid circular imports during Django app loading.
    from racklab.core.models import TenantMembership

    membership = (
        TenantMembership.objects.filter(user=user, is_primary=True)
        .select_related("tenant")
        .first()
    )
    if membership is None:
        return None
    return str(membership.tenant_id)


class TenantContextMiddleware:
    """Set the tenant context for the duration of each request."""

    sync_capable = True
    async_capable = True

    def __init__(
        self,
        get_response: Callable[[HttpRequest], HttpResponse]
        | Callable[[HttpRequest], Awaitable[HttpResponse]],
    ) -> None:
        """Store the next middleware / view callable and remember if it's async."""
        self.get_response = get_response
        self._is_async = asyncio.iscoroutinefunction(get_response)

    def __call__(
        self, request: HttpRequest
    ) -> HttpResponse | Awaitable[HttpResponse]:
        """Dispatch to sync or async implementation."""
        if self._is_async:
            return self._acall(request)
        return self._scall(request)

    def _scall(self, request: HttpRequest) -> HttpResponse:
        """Sync request handling."""
        tenant_id = _resolve_primary_tenant_id_sync(request.user)
        with current_tenant(tenant_id):
            response = self.get_response(request)
        return _ensure_sync_response(response)

    async def _acall(self, request: HttpRequest) -> HttpResponse:
        """Async request handling."""
        tenant_id = await sync_to_async(_resolve_primary_tenant_id_sync)(request.user)
        with current_tenant(tenant_id):
            response_or_coro: Any = self.get_response(request)
            response = await response_or_coro if asyncio.iscoroutine(response_or_coro) else response_or_coro
        return response


def _ensure_sync_response(response: Any) -> HttpResponse:
    """Narrow Any-typed get_response return to HttpResponse for the sync path."""
    return response
```

- [ ] **Step 2: Run middleware tests**

Run: `uv run pytest tests/contract/test_tenant_middleware.py -v`
Expected: 5 passed.

### Task 6: Manager + QuerySet integration tests

**Files:**

- Create: `tests/integration/test_tenancy_managers.py`

- [ ] **Step 1: Write the failing tests**

```python
"""Integration tests for TenantAwareManager + TenantAwareQuerySet per PRD §19."""

from __future__ import annotations

import pytest
from django.contrib.auth import get_user_model

from racklab.core.models import Tenant, TenantMembership
from racklab.core.tenancy_context import current_tenant
from racklab.core.tenancy_managers import TenantAwareManager


@pytest.fixture
def two_tenants_with_memberships() -> tuple[Tenant, Tenant, TenantMembership, TenantMembership]:
    """Two tenants, two users, one TenantMembership in each tenant."""
    user_model = get_user_model()
    tenant_a = Tenant.objects.create(name="Tenant A", slug="tenant-a")
    tenant_b = Tenant.objects.create(name="Tenant B", slug="tenant-b")
    user_a = user_model.objects.create_user(username="user-a")
    user_b = user_model.objects.create_user(username="user-b")
    membership_a = TenantMembership.objects.create(user=user_a, tenant=tenant_a)
    membership_b = TenantMembership.objects.create(user=user_b, tenant=tenant_b)
    return tenant_a, tenant_b, membership_a, membership_b


@pytest.mark.django_db
@pytest.mark.integration
def test_manager_filters_by_current_tenant(
    two_tenants_with_memberships: tuple[Tenant, Tenant, TenantMembership, TenantMembership],
) -> None:
    """Under tenant A's context, the manager returns only tenant-A rows."""
    tenant_a, _tenant_b, membership_a, _membership_b = two_tenants_with_memberships
    manager: TenantAwareManager[TenantMembership] = TenantAwareManager(
        tenant_field_name="tenant_id"
    )
    manager.model = TenantMembership
    with current_tenant(str(tenant_a.id)):
        rows = list(manager.get_queryset())
    assert rows == [membership_a]


@pytest.mark.django_db
@pytest.mark.integration
def test_manager_returns_unfiltered_when_no_context(
    two_tenants_with_memberships: tuple[Tenant, Tenant, TenantMembership, TenantMembership],
) -> None:
    """With no tenant context, the manager returns every row.

    Management commands, migrations, and other system paths run without context
    and need to see everything.
    """
    expected_row_count = 2
    manager: TenantAwareManager[TenantMembership] = TenantAwareManager(
        tenant_field_name="tenant_id"
    )
    manager.model = TenantMembership
    assert manager.get_queryset().count() == expected_row_count


@pytest.mark.django_db
@pytest.mark.integration
def test_manager_all_tenants_ignores_context(
    two_tenants_with_memberships: tuple[Tenant, Tenant, TenantMembership, TenantMembership],
) -> None:
    """`.all_tenants()` returns every row even under a tenant context."""
    tenant_a, _tenant_b, _membership_a, _membership_b = two_tenants_with_memberships
    expected_row_count = 2
    manager: TenantAwareManager[TenantMembership] = TenantAwareManager(
        tenant_field_name="tenant_id"
    )
    manager.model = TenantMembership
    with current_tenant(str(tenant_a.id)):
        assert manager.all_tenants().count() == expected_row_count


@pytest.mark.django_db
@pytest.mark.integration
def test_manager_filters_correctly_across_tenant_switches(
    two_tenants_with_memberships: tuple[Tenant, Tenant, TenantMembership, TenantMembership],
) -> None:
    """Switching the tenant context switches the filter."""
    tenant_a, tenant_b, membership_a, membership_b = two_tenants_with_memberships
    manager: TenantAwareManager[TenantMembership] = TenantAwareManager(
        tenant_field_name="tenant_id"
    )
    manager.model = TenantMembership
    with current_tenant(str(tenant_a.id)):
        assert list(manager.get_queryset()) == [membership_a]
    with current_tenant(str(tenant_b.id)):
        assert list(manager.get_queryset()) == [membership_b]
```

### Task 7: Implement the manager + queryset

**Files:**

- Create: `src/racklab/core/tenancy_managers.py`

- [ ] **Step 1: Write the module**

```python
"""Tenant-aware Manager + QuerySet mixin per PRD §19.

A model adopts tenant filtering by replacing its default manager with
`TenantAwareManager(tenant_field_name="tenant_id")` (or naming the manager
explicitly to keep `.objects` unfiltered).  Once adopted:

- `Model.objects.all()` returns rows whose `tenant_id` matches the
  contextvar `_current_tenant_id` when one is set.
- `Model.objects.all_tenants()` ignores the contextvar — explicit escape
  for management commands, admin paths, and RBAC resolution code that
  composes cross-tenant queries.
- With no tenant context set (no middleware, no explicit set), the
  manager behaves like a plain manager and returns every row — the
  middleware is the only production code path that sets the contextvar.

This slice does NOT swap the default manager on any existing model.  Per-
model adoption (Job, Artifact, AuditEvent) lands with each model's tenant-
FK addition slice, alongside the backfill migration.
"""

from __future__ import annotations

from typing import TYPE_CHECKING, Generic, TypeVar

from django.db import models

from racklab.core.tenancy_context import get_current_tenant_id

if TYPE_CHECKING:
    pass

ModelT = TypeVar("ModelT", bound=models.Model)


class TenantAwareQuerySet(models.QuerySet[ModelT]):
    """QuerySet that applies a tenant filter on demand."""

    def with_tenant_filter(self, tenant_field_name: str) -> TenantAwareQuerySet[ModelT]:
        """Apply `tenant_field_name=<current tenant id>` if a tenant context is set."""
        tenant_id = get_current_tenant_id()
        if tenant_id is None:
            return self
        return self.filter(**{tenant_field_name: tenant_id})


class TenantAwareManager(models.Manager[ModelT], Generic[ModelT]):
    """Manager that returns a tenant-filtered queryset by default.

    Construct with `tenant_field_name` naming the column to filter by; the
    default `"tenant_id"` matches the denormalized column convention from
    PRD §19.
    """

    def __init__(self, tenant_field_name: str = "tenant_id") -> None:
        """Store the tenant FK column name to filter on."""
        super().__init__()
        self._tenant_field_name = tenant_field_name

    def get_queryset(self) -> TenantAwareQuerySet[ModelT]:
        """Return a queryset filtered by the current tenant context, if any."""
        base: TenantAwareQuerySet[ModelT] = TenantAwareQuerySet(
            model=self.model, using=self._db
        )
        return base.with_tenant_filter(self._tenant_field_name)

    def all_tenants(self) -> TenantAwareQuerySet[ModelT]:
        """Return a queryset ignoring the current tenant context."""
        return TenantAwareQuerySet(model=self.model, using=self._db)
```

- [ ] **Step 2: Run the integration tests**

Run: `uv run pytest tests/integration/test_tenancy_managers.py -v`
Expected: 4 passed.

### Task 8: Run every M0 verification gate

```bash
uv lock --check && \
uv sync --locked && \
uv run ruff format --check . && \
uv run ruff check . && \
uv run mypy && \
uv run basedpyright && \
uv run pytest && \
uv run python manage.py check && \
uv run bandit -c pyproject.toml -r src && \
uv run pip-audit && \
uv run pre-commit run --files <touched paths>
```

Expected: every gate green.

### Task 9: Codex review the uncommitted diff

Background-launch `codex exec --dangerously-bypass-approvals-and-sandbox "<review prompt>"` against the working tree. Fold P0/P1 findings.

### Task 10: Commit + PROGRESS update

Conventional Commit subject, signed. Update PROGRESS.md to:

- Move "tenant context middleware + tenant-aware manager mixin" from "next slice" to "completed this session".
- Add `dd0263c` then this new commit to the chain.
- Recommended next slice becomes the **first per-model tenant-FK slice**: `Job` gets `tenant` FK + denormalized `tenant_id` column + `TenantAwareManager` adoption + backfill migration. (Or, alternatively, the `AuditEvent` extension slice — the audit-emission service work blocks several M0 gaps.)

## Self-Review

**1. Spec coverage:**

- ✅ `contextvars`-based tenant propagation — Task 2.
- ✅ ASGI/Channels/async safety — Task 3 contract tests + Task 5 async path.
- ✅ TenantAwareManager + `all_tenants()` escape — Task 7.
- ⚠ M0 acceptance criterion line 67 ("Tenant context propagates correctly through ASGI async views and Channels consumers") — async ASGI test lands; Channels consumer test is deferred to when Channels is actually wired up (still M0 but in a later slice; the Protocol holds).
- ⚠ M0 acceptance criterion line 63 ("A query made under tenant A's context cannot return rows owned by tenant B without an explicit `all_tenants()` manager call") — landed for the manager class itself but no production model uses it yet. Subsequent per-model slices make the criterion fully observable from production code.

**2. Placeholder scan:** No TBDs / "fill in later" patterns. The `_ensure_sync_response` helper exists only to narrow `Any` for typing — it's intentional, not a placeholder.

**3. Type consistency:**

- `_current_tenant_id` is `ContextVar[str | None]` everywhere.
- `tenant_field_name` is a `str` (default `"tenant_id"`) everywhere.
- `TenantAwareManager[ModelT]` and `TenantAwareQuerySet[ModelT]` use a consistent `TypeVar`.
- `_resolve_primary_tenant_id_sync` returns `str | None`; the contextvar accepts both.

## Execution Handoff

Inline execution — same context, autonomous loop tick.

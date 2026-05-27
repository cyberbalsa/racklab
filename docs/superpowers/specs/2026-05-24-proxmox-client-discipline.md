# Proxmox VE Client Library and Discipline

**Date:** 2026-05-24
**Status:** Decided.
**Decision owner:** Forrest Fuqua
**Scope:** Which Python library RackLab uses to talk to Proxmox VE, how the rest of the codebase interacts with it, and what operational discipline the provider plugin must enforce.

## 1. Decision

1. **Use `proxmoxer` 2.3.0** as the Proxmox VE transport library. It is the best fit identified in the 2026 survey of Python Proxmox clients.
2. **Wrap `proxmoxer` behind a typed, in-tree facade.** The rest of RackLab does not import `proxmoxer` directly. The facade owns Pydantic models, async ergonomics, retry/backoff, the task state machine, structured error mapping, TLS and timeout configuration, PVE capability discovery, and the integration-test seam.
3. **Encode explicit task-polling and reconciliation discipline.** `proxmoxer.tools.Tasks.blocking_status` is unsuitable at RackLab's scale because it lacks jitter, distributed concurrency limits, and per-operation timeouts. The facade owns the polling loop, persists task state durably in its own table, and distinguishes "stop waiting" from "retry the original operation."
4. **Keep the provider abstraction Proxmox-agnostic.** The generic provider interface above the facade does not leak Proxmox-specific concepts (see §7).

## 2. Context

The PRD specifies Proxmox VE as the first provider backend (`docs/prd/12-proxmox-provider.md`) and a plugin system for future providers (`docs/prd/13-plugin-system.md`). RackLab worker code calls the Proxmox API for every meaningful operation: clone, snapshot, power, network attach, console ticket, task poll, node inventory, cluster status. Those calls run under Horizon workers (Redis-backed) that must be idempotent, retry-safe, and audit-logged.

The PRD's engineering quality section (`docs/prd/17-engineering-quality-typing-ci.md`) requires strict typing via `mypy --strict` + `django-stubs` + `drf-stubs` + `pyright`/`basedpyright`. The chosen Proxmox library must not poison that.

## 3. Library survey (summary)

Bottom line:

- **`proxmoxer` 2.3.0** (2026-03-04, 782 stars, MIT, Python 3.10–3.14, 2-maintainer bus factor). HTTPS / SSH / local backends. Auth modes: API token (header-only) and Proxmox ticket (username/password, including modern 2FA fixed in 2.3.0). Proxmox user realms (`pam`, `pve`, OIDC, LDAP, etc.) are passed as part of the username (`user@realm`) — they are server-side concepts, not separate proxmoxer auth modes. Task helpers in `proxmoxer.tools.Tasks`: `blocking_status`, `decode_upid`, `decode_log` (singular; formats an already-fetched log list — pagination of the underlying task log is a Proxmox API concern, not a helper concern). **No type hints, no `py.typed`, no async, raw-dict responses.** Every Proxmox API path is reachable via the dynamic attribute proxy — but that is not the same as typed, modelled, or validated endpoint coverage.
- **`pyproxmox-ve`**: async + Pydantic by design but pre-production (2 stars, 6 commits, examples marked TO-DO, solo maintainer).
- **`proxmoxmanager`**: abandoned 2021.
- **`pmxc`**, older `pyproxmox`: CLI / dead.
- **Codegen from a community OpenAPI spec**: viable on paper but Proxmox's schema is Perl-flavored, community specs lag, and a generated client still needs hand-patches and cluster-version validation. High maintenance for one team.
- **Upstream Proxmox Python client**: does not exist and is not planned. Proxmox ships Perl (`pvesh`, `PVE::APIClient::LWP`) and an embedded JS SDK in the web UI.

`proxmoxer` 2.3.0 is the best fit. Everything else is either dead, immature, or self-owned maintenance debt RackLab does not need.

## 4. The typed facade

### 4.1 Where it lives

`racklab/providers/proxmox/client.py` exposes the typed API the rest of RackLab uses. Internal modules in the same package hold the `proxmoxer`-touching code and the Pydantic models.

### 4.2 What it owns

- **Pydantic v2 domain models** for the response shapes RackLab actually uses: `Node`, `VM` (config + status), `Task`, `Snapshot`, `NetworkInterface`, `StorageVolume`, `ConsoleAccessGrant`. Models are defined for what the worker code consumes; extend as new endpoints are wrapped.
- **Async methods** backed by `asyncio.to_thread` around the sync `proxmoxer` calls. The facade configures and owns a **bounded `ThreadPoolExecutor`** sized in settings; it never uses the default executor. Workers' async concurrency against Proxmox is then a visible knob, not an emergent property of default executor sizing.
- **TLS and request transport configuration**: production requires `verify_ssl=True` with an operator-configured CA bundle. The facade must accept Proxmox endpoints whose server certificates were issued by any of:
  - **Let's Encrypt (or any public-trust CA)** — validated against the system trust store.
  - **A self-signed certificate** — validated against an operator-supplied bundle path that pins the specific Proxmox CA or the cert itself.
  - **A custom ACME issuer** (e.g., step-ca / smallstep, an internal corporate ACME) — validated against an operator-supplied bundle containing the custom ACME's root and any intermediates.
  The operator-supplied bundle is a single configuration knob; it composes with the system trust store rather than replacing it, so a deployment can mix Proxmox clusters from different issuers without separate configuration per cluster. `verify_ssl=False` is rejected by configuration validation outside development. The facade also exposes explicit connect and read timeouts for every operation (defaults: 5 s connect, 30 s read) and forwards proxy configuration where deployed. `proxmoxer` exposes the underlying knobs; the facade enforces safe defaults.
- **PVE capability and version discovery.** SDN, backup, console, cloud-init, and several response shapes vary by Proxmox version and cluster configuration. The facade probes capability on connection setup and exposes a typed `ClusterCapabilities` object the rest of RackLab uses for feature gating.
- **Task state machine** (see §5).
- **Retry + backoff policy** for transient failures (5xx, connection errors, request-timeout exceptions). Explicit non-retry classes for 4xx semantic errors that should propagate to the worker.
- **Structured error mapping** from `proxmoxer.ResourceException` / `AuthenticationError` to RackLab-defined exception types: `ProviderAuthError`, `ProviderResourceConflict`, `ProviderNotFound`, `ProviderTransient`, `ProviderBug`, and three distinct timeout types: `ProviderRequestTimeout` (HTTP-level connect/read timeout), `ProviderTaskWaitTimeout` (we gave up waiting for the task, but the task may still be running), and `ProviderOperationDeadlineExceeded` (the worker-side deadline budget elapsed). These three timeouts trigger different recovery paths and must not be conflated.
- **A `MockProxmoxClient`** implementation of the same Protocol for unit tests, plus a real-PVE integration-test fixture (env-var-gated).

### 4.3 What it does not own

- Higher-level RackLab concepts: projects, quotas, RBAC, audit-event emission, scheduler placement decisions, plugin contracts. The facade is a transport-and-shape boundary; the worker / service layer composes it.
- Caching. The facade does not cache Proxmox state. RackLab's reconciliation loop is the authority for state freshness.
- Provider-agnostic interfaces. The generic provider interface (in `racklab/providers/base.py` or wherever the PRD's plugin contract lands) is separate and Proxmox-agnostic (§7).

### 4.4 Why not raw `httpx`-from-scratch

`proxmoxer`'s dynamic attribute proxy reaches every Proxmox API path essentially for free. Replicating that surface — covering only RackLab's wrapped endpoints — is weeks of work; covering the *full* surface with parity on auth, uploads, errors, task logs, console proxy, cluster/version quirks, and integration tests is months. The right time to drop `proxmoxer` is when (a) the library stalls after a Proxmox API/auth break, (b) mypy-strict friction at the boundary becomes intolerable despite typed-facade isolation, or (c) async I/O concurrency is genuinely capped by the executor at observed scale. None of those is true today.

## 5. Task-polling and reconciliation discipline

`proxmoxer.tools.Tasks.blocking_status(...)` ships with an unhelpful default (the published docs say `polling_interval=0.01`; the 2.3.0 source uses `polling_interval=1` — either way the helper has no jitter, no per-operation timeout, no distributed concurrency limit, and persists nothing). The facade does not use `blocking_status` directly. It owns the polling loop and persists state in RackLab's own provider-task table.

The discipline:

- **Durable task-row persistence — a real table, not just an audit row.** When the facade submits a clone/snapshot/power/network operation, it parses the returned UPID with `Tasks.decode_upid` and immediately inserts (or updates) a `ProviderTask` row — which is a subtype of the universal `Job` ledger in PRD §19. The row carries the UPID, decoded node + pid + starttime + type + id + user, the dispatching worker's lease id, the operation class, the deadline, attempt count, last-poll timestamp, and final status (initially `pending`). Audit rows reference the parent `Job` row by id; they are not the same row. Reconciliation reads from `Job` and pivots to `ProviderTask` only when it needs provider-specific fields.
- **Backoff with jitter and a minimum floor.** Start at 500 ms, exponential to a 2 s cap, full jitter with a 100 ms floor (so we never busy-loop on near-zero sleeps). The 100 ms floor is a guardrail against pathological jitter combined with cluster pressure.
- **Per-operation-class timeouts.** Clone-from-large-template: minutes. Power op: seconds. Snapshot: variable. The facade exposes a registry of named operation classes with sane default deadlines and an override on the call site. A timeout maps to `ProviderTaskWaitTimeout` — *the task may still be running on Proxmox*, the facade has only stopped waiting.
- **Distributed per-node concurrency limit.** A per-process semaphore is the wrong layer — it would let a 20-worker fleet open 160 simultaneous polls against a single Proxmox node. The facade acquires a distributed concurrency lease against the target node before each poll batch. Implementation candidates (decision deferred to implementation planning, but the facade exposes the same interface either way):
  1. **Postgres advisory locks** keyed on `(node, slot)`, with `slot ∈ [0..N)` where N is the configured per-node poll budget. Lowest operational cost, leverages the existing Postgres dependency.
  2. **Redis token bucket** — Redis is already a hard dependency (Horizon queue); this is a natural secondary use.
  Default plan: Postgres advisory locks, until profiling says otherwise.
- **Node-loss handling.** If the node a task is on becomes unreachable, the facade raises `ProviderNodeUnreachable` and leaves the provider-task row in `pending` for the reconciler. It does not silently retry against a different node; that's reconciler policy.
- **Non-`OK` exit-status mapping.** Proxmox tasks complete with an `exitstatus` string; only `"OK"` is success. Anything else maps to a typed `ProviderTaskFailed` with `exitstatus`, partial log, and UPID. The provider-task row transitions to `failed`.
- **Log retrieval.** Verbose task logs are paginated via the Proxmox API endpoint `nodes/{node}/tasks/{upid}/log?start=…&limit=…`. The facade owns the paging loop, writes the full log to RackLab's artifact storage (PRD §14) keyed by UPID, and writes a bounded summary into the provider-task row. `Tasks.decode_log` is used only to format an already-fetched list — it is not a pagination tool.
- **"Stop waiting" vs "retry the original operation" — explicitly distinct.** This is the most important discipline in this section.
  - **Stop waiting** = the facade gives up polling. The provider-task row stays in its current state (`pending`); the reconciler resumes polling by UPID later. **The original operation is not re-submitted.**
  - **Retry original operation** = re-issue the clone/delete/snapshot/etc. This is only safe when either (a) the original submission *provably* did not reach Proxmox (connection refused before request body sent, with no UPID returned), or (b) the operation has a Proxmox-side idempotency property and we have reconciled target state and confirmed it is consistent with re-issue. The default is **never re-submit**; the reconciler picks up the existing UPID.
- **Cancellation discipline.** User-initiated cancellation or worker job-lease expiry does **not** automatically issue a Proxmox task cancel. The facade exposes an explicit, audited `cancel(task_row)` operation that issues the Proxmox cancel API call and logs both the request and the Proxmox-side outcome. Reconciler-initiated cleanup uses the same path.

These are guardrails the facade enforces, not policies workers can opt out of.

## 6. Auth and credential handling

- **API tokens are the only supported production auth mode.** Username + ticket (with or without 2FA) is supported only for development. This matches PRD §06 — Proxmox-side credentials are RackLab service tokens, audited at issuance and rotation.
- **Proxmox user realms** (`pam`, `pve`, OIDC, LDAP) are server-side configuration on the Proxmox cluster. They appear in the username (`user@realm`) but are not a separate library auth mode. RackLab does not care which realm a Proxmox API-token user lives in.
- **No Proxmox credential ever appears in a worker process that runs untrusted user scripts.** Script workers have their own isolated runner profile (PRD §10). Provider workers and script workers must run on different processes — see PRD §05.
- **2FA is a Proxmox feature for password/ticket authentication.** API tokens are single-factor by design (header-only, no challenge). RackLab's choice to use API tokens in production sidesteps 2FA at the library boundary.

## 7. Provider abstraction: keep it Proxmox-agnostic

The generic provider interface (the contract every provider plugin implements) does not surface any Proxmox-specific concept. The abstraction must not expose, type, or assume any of:

- **VMID** as the primary VM identifier. Use opaque `ProviderInstanceId` strings.
- **UPID** as the task identifier. Use opaque `ProviderTaskId` strings.
- **Proxmox node names** as a placement primitive. Use the generic `ProviderHostId`.
- **Proxmox storage IDs, pools, HA groups, tags, or realm/ACL paths.** All of these are Proxmox-shape; map to generic provider concepts inside the facade.
- **QEMU-vs-LXC** assumptions. The generic interface speaks "instance," not "VM-type" — Proxmox's distinction is internal to the plugin.
- **Linked-clone vs full-clone** semantics. The generic interface asks for `clone(template, target, mode=Mode.LINKED|FULL)`; whether Proxmox honors it is a capability flag.
- **`cicustom` and Proxmox-shaped cloud-init plumbing.** RackLab's cloud-init model is the abstraction (PRD §10); the plugin translates.
- **Bridge / VNet / VLAN-only networking.** RackLab's Neutron-shaped networking (PRD §09) is the abstraction; the plugin translates.
- **Proxmox firewall dialect.** Security-group equivalence is a generic provider capability; the Proxmox firewall is the plugin's translation layer.
- **PBS-specific backup semantics** (incremental dedup, prune policies expressed in PBS terms). Backup is a generic capability; semantics live in RackLab's backup model.
- **Console-ticket coupling.** The console subsystem asks the provider for "give me a short-lived access grant for instance X scoped to user Y for N seconds" and gets back a `ConsoleAccessGrant`. The grant may *contain* a Proxmox-style vncticket internally, but the generic interface never types or surfaces one.
- **noVNC / xterm.js / SPICE protocol details.** The generic interface exposes a console-grant URL plus a protocol enum (`vnc`, `terminal`, `spice`, …); concrete protocols and renderer choice are plugin capabilities. The Proxmox plugin's KVM graphical consoles surface as `vnc`; LXC and serial consoles surface as `terminal`.

This discipline keeps the plugin system per PRD §13 honest. The Proxmox plugin is the first concrete implementation; nothing about the generic interface should encode "we assume Proxmox underneath."

## 8. Laravel + Horizon transaction-boundary discipline

The Proxmox client is one end of a chain that includes a Laravel HTTP/API request, a Laravel DB transaction, a Horizon job dispatch (Redis-backed), a worker consumer, and the actual Proxmox API call. The transaction boundaries on RackLab's side must be airtight, regardless of the facade itself:

- **Persist before dispatch.** A request that triggers a provider operation writes the deployment row, the audit row, and the *intended* provider-task row (status `dispatching`) inside the same DB transaction, *before* the Horizon job dispatch.
- **Dispatch after commit.** Horizon dispatch happens via `ShouldBroadcastAfterCommit` semantics — after the DB transaction commits, not from inside it. This is enforced by dispatching inside the `afterCommit()` lifecycle hook on the job.
- **Update state before acknowledging.** A Horizon worker processes a job to completion only after it has updated the provider-task row (to `pending` with the UPID, or to `failed` with reason) inside its own transaction. Horizon retry + idempotency-key uniqueness on the provider-task row is what prevents duplicate Proxmox submissions on worker retry.
- **Idempotency keys are first-class.** Every mutating operation crossing the boundary carries an idempotency key persisted in the provider-task row with a unique constraint. Re-running a Horizon worker against the same job must produce the same provider-task row, not a second submission.

This section is short on purpose; the canonical place for these patterns is the worker/event spec, not this client doc. It's recorded here so that the facade's `ProviderTaskWaitTimeout` and "never re-submit by default" semantics in §5 are read in the right Laravel/Horizon context.

## 9. Testing

- **Unit tests** against `MockProxmoxClient` (in-tree mock implementing the same Protocol as the real client). No network, no `proxmoxer`-internal mocking.
- **Library-boundary tests** against `proxmoxer` itself using `responses`-style HTTP fixtures, validating that the facade correctly translates Proxmox raw-dict responses into Pydantic models and correctly maps `proxmoxer` exceptions into RackLab provider exceptions.
- **Integration tests** against a real Proxmox VE instance (env-var-gated, skipped in default CI, run nightly / on-demand). Cover: clone, snapshot create/restore/delete, power lifecycle, network attach/detach, console-grant issue, task polling under load, and the §5 distributed concurrency limit under a horizontally-scaled worker fleet.

## 10. Migration path off `proxmoxer` if it ever stalls

Because everything above the facade depends only on Pydantic models + the typed Protocol:

1. Build a `RawProxmoxHttpxClient` in the same package, implementing the same internal `_ProxmoxTransport` interface.
2. Port endpoint by endpoint, behind a feature flag, with the integration test suite as the regression seam.
3. Cut over when coverage is at parity for RackLab's wrapped endpoints; remove the `proxmoxer` dep.

Estimated effort: **weeks for parity on RackLab's wrapped endpoints; months for full Proxmox-surface parity** (auth, uploads, errors, task logs, console proxy, cluster/version quirks, integration tests across PVE versions). The typed facade is what makes the wrapped-endpoint path tractable; full parity is a real project.

## 11. Open risks

- **No type stubs upstream.** `# type: ignore` at the facade boundary is acceptable; a small in-tree `proxmoxer-stubs/` package can be written if friction grows.
- **`asyncio.to_thread` caps concurrency at the configured executor size.** Sizing is a visible setting per §4.2; raise it before considering a raw async transport.
- **`proxmoxer` maintainer health is acceptable but not strong** — 2-maintainer bus factor, low contributor depth, some open issues in non-HTTPS backends. RackLab only uses the HTTPS backend, which limits exposure.
- **Risk response (not default plan)**: if `proxmoxer` upstream stalls after a Proxmox API or auth break, vendor the source into `third_party/proxmoxer/` so a patch can be applied locally while we evaluate the §10 migration. This is a contingency, not a setup step.
- **PVE version drift.** Capability discovery (§4.2) is the first-line mitigation; integration tests across at least two PVE major versions catch the rest.
- **Don't bet on OpenAPI codegen as an escape hatch.** Community Proxmox OpenAPI specs lag and Proxmox's schema is Perl-flavored. The escape hatch is the `httpx`-based in-tree client per §10, not a generated SDK.

## 12. Confidence

**High** on the library choice — `proxmoxer` 2.3.0 is the best-fit Python client identified in the 2026 survey, and the `Tasks` helpers are good starting points even though the facade owns the actual polling loop.

**High** on the typed-facade pattern — it solves the strict-typing requirement without forking the library, and it preserves the migration option in §10.

**Medium** on the specific task-polling parameter values (500 ms initial, 2 s cap, 100 ms minimum floor, per-node poll budget). These are reasoned defaults that should be re-tuned against real Proxmox cluster behavior in early integration testing.

**Medium** on the choice of Postgres advisory locks as the §5 distributed concurrency primitive. Postgres is already a hard dependency, the API is simple, and the operational story is clear; if profiling shows the lock-acquire latency itself becomes a bottleneck against busy clusters, switch to a Redis token bucket (Redis is already a hard dependency for Horizon).

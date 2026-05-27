# Proxmox VE Client Library and Discipline

**Date:** 2026-05-24
**Status:** Decided — body written in Python (pre-Laravel-redesign); discipline carries forward verbatim to the Laravel stack. Endpoint-mapping strategy updated 2026-05-27 to codegen-from-schema (see §1 and §5a).
**Decision owner:** Forrest Fuqua
**Scope:** How RackLab talks to Proxmox VE, how the rest of the codebase interacts with the client, and what operational discipline the provider plugin must enforce.

> **Note on stack-carry-forward (added 2026-05-27):** This document predates the Laravel redesign (`docs/superpowers/specs/2026-05-26-laravel-redesign.md`). The *discipline* in §3–§10 — typed facade, owned task-polling loop, structured error mapping, multi-issuer TLS trust, integration-test seam, migration-off-the-library plan — applies to the Laravel stack unchanged. The *examples* are Python and use the following mapping when ported to PHP:
>
> | Python (this doc) | PHP (Laravel redesign) |
> | --- | --- |
> | `proxmoxer` 2.3.0 transport | Codegen-from-schema typed PHP client; Guzzle 7.10 is the HTTP transport |
> | Pydantic v2 typed models | PHP 8 `readonly` DTOs emitted by the code generator under `app/Providers/Proxmox/Generated/`; hand-written domain models in `app/Providers/Proxmox/Models/` |
> | `mypy --strict` + `django-stubs` + `drf-stubs` + `pyright`/`basedpyright` | Larastan PHPStan 2 max level + `declare(strict_types=1)` |
> | `asyncio.to_thread` around blocking calls | Horizon worker job dispatch (`App\Jobs\PollProxmoxTask`); no in-process async needed because work happens in queue workers |
> | `ThreadPoolExecutor` for bounded concurrency | Horizon queue concurrency knobs (`--max-processes`, queue tags) |
> | `racklab/providers/proxmox/client.py` typed facade | `App\Providers\Proxmox\Client` (PHP class) — hand-written discipline layer over the generated client; wired in `ProxmoxServiceProvider` |
> | `MockProxmoxClient` in-tree mock | `FakeProxmoxClient` in `app/Testing/Fakes/` |
> | `proxmoxer.ResourceException` / `AuthenticationError` mapping | RackLab provider exception types mapped from `GuzzleHttp\Exception\*` |
>
> When the PHP scaffold lands (sub-plan `laravel-scaffold`), this spec's §5 (typed facade), §5a (codegen architecture), §6 (owned polling), §7 (provider-agnostic interface), §8 (TLS trust), §9 (testing seam) translate row-by-row using the table above. Until then, treat the Python body as the canonical discipline statement.

## 1. Decision

1. **Generate the typed PHP client from Proxmox's `pve-doc-generator` JSON Schema dump**; Guzzle 7.10 is the HTTP transport. The code generator (an Artisan command, `php artisan racklab:proxmox:generate-client`) reads the authoritative Proxmox schema — the same source Proxmox's official `libpve-apiclient-perl` uses — and emits typed PHP classes: readonly DTOs for response models and typed methods grouped by API tree (`Access`, `Cluster`, `Nodes`, `Storage`, `Pools`, `Version`). Generated code lives under `app/Providers/Proxmox/Generated/`. No community PHP Proxmox packages — they are too thin/unmaintained.
2. **Wrap the generated client behind a hand-written discipline layer.** The rest of RackLab does not call the generated client directly. The hand-written facade (`App\Providers\Proxmox\Client`) owns the `ProxmoxClientContract` interface, `TaskPoller`, structured error mapping, TLS trust composition, PVE capability discovery, and the integration-test seam. The generated layer handles the endpoint-to-PHP-class mapping; the discipline layer handles everything else.
3. **Encode explicit task-polling and reconciliation discipline.** Any polling helper that lacks jitter, distributed concurrency limits, and per-operation timeouts is unsuitable at RackLab's scale. The facade owns the polling loop via `App\Jobs\PollProxmoxTask` (a Horizon job), persists task state durably in its own `ProviderTask` table, and distinguishes "stop waiting" from "retry the original operation."
4. **Keep the provider abstraction Proxmox-agnostic.** The generic provider interface above the facade does not leak Proxmox-specific concepts (see §7).

## 2. Context

The PRD specifies Proxmox VE as the first provider backend (`docs/prd/12-proxmox-provider.md`) and a plugin system for future providers (`docs/prd/13-plugin-system.md`). RackLab worker code calls the Proxmox API for every meaningful operation: clone, snapshot, power, network attach, console ticket, task poll, node inventory, cluster status. Those calls run under Horizon workers (Redis-backed) that must be idempotent, retry-safe, and audit-logged.

The PRD's engineering quality section (`docs/prd/17-engineering-quality-typing-ci.md`) requires strict typing via Larastan at PHPStan max level + `declare(strict_types=1)` throughout. The Proxmox client boundary must not undermine that — hence the typed facade that exposes only PHP `readonly` models, never raw `array` responses.

## 3. Library survey (summary)

Bottom line (PHP ecosystem):

- **Community PHP Proxmox packages** (`Corsinvest/cv4pve-api-php`, `zzantares/proxmox`, others): thin wrappers, infrequently maintained, no strict typing, raw-array responses. Not suitable for Larastan-max compliance.
- **`jefersonflus/proxmox-php-sdk`** (community): rejected — bus-factor-1 (3 stars, 14 Packagist downloads as of 2026-05-27) and HTTP-client mismatch (uses `php-curl-class` instead of Guzzle).
- **Perl sidecar daemon wrapping `libpve-apiclient-perl`** (Option A): rejected — requires a Perl runtime on every host and raises contributor-pool concerns for a PHP/Laravel project.
- **Codegen from a community OpenAPI spec**: community Proxmox OpenAPI specs lag and are not authoritative. Rejected in favour of codegen from the canonical Proxmox schema below.
- **Codegen from `pve-doc-generator` JSON Schema** (the Proxmox authoritative source, same as `libpve-apiclient-perl`): correct authoritative surface, typed PHP output, no Perl runtime, regenerates automatically on Proxmox version bumps. **Selected.**
- **Upstream Proxmox PHP client**: does not exist. Proxmox ships Perl (`pvesh`, `PVE::APIClient::LWP`) and an embedded JS SDK in the web UI.
- **Guzzle 7.10**: already a hard Laravel dependency, supports all required features (async via `Pool`, `HttpClient::async()`, streaming, middleware, retry handler), and exposes `GuzzleHttp\Exception\*` which can be mapped cleanly to RackLab provider exception types.

**Codegen-from-schema with Guzzle 7.10 as the HTTP transport is the best fit.** The generator reads the same authoritative source as Proxmox's own Perl client; typed PHP output satisfies Larastan-max without hand-patching every endpoint; Guzzle already exists in the dependency graph; no Perl runtime required at any stage.

## 4. The typed facade

### 4.1 Where it lives

`app/Providers/Proxmox/Client.php` exposes the typed API the rest of RackLab uses via the `ProxmoxClientContract` interface. The discipline layer (`Client`, `TaskPoller`, `Tls`, `CapabilityProbe`, `Exceptions/`) lives in `app/Providers/Proxmox/`. The generated layer (readonly DTOs + typed endpoint methods) lives in `app/Providers/Proxmox/Generated/` (see §5a). Registered as a singleton in `App\Providers\ProxmoxServiceProvider` with per-tenant credential injection.

### 4.2 What it owns

- **PHP `readonly` domain models** for the response shapes RackLab actually uses: `Node`, `Vm` (config + status), `Task`, `Snapshot`, `NetworkInterface`, `StorageVolume`, `ConsoleAccessGrant`. RackLab-facing domain models are in `app/Providers/Proxmox/Models/`; raw response DTOs are in `app/Providers/Proxmox/Generated/` (emitted by the code generator). The discipline layer translates generated DTOs to domain models where shapes differ.
- **Horizon worker dispatch** via `App\Jobs\PollProxmoxTask` for all task-polling work; no in-process async needed because work happens in queue workers. Concurrency against Proxmox is controlled by Horizon's `--max-processes` and queue tag configuration — a visible operational knob, not an emergent property.
- **TLS and request transport configuration**: production requires `verify_ssl=true` with an operator-configured CA bundle. The facade must accept Proxmox endpoints whose server certificates were issued by any of:
  - **Let's Encrypt (or any public-trust CA)** — validated against the system trust store.
  - **A self-signed certificate** — validated against an operator-supplied bundle path that pins the specific Proxmox CA or the cert itself.
  - **A custom ACME issuer** (e.g., step-ca / smallstep, an internal corporate ACME) — validated against an operator-supplied bundle containing the custom ACME's root and any intermediates.
  The operator-supplied bundle is a single configuration knob; it composes with the system trust store rather than replacing it, so a deployment can mix Proxmox clusters from different issuers without separate configuration per cluster. `verify_ssl=false` is rejected by configuration validation outside development. The facade also exposes explicit connect and read timeouts for every operation (defaults: 5 s connect, 30 s read) and forwards proxy configuration where deployed. Guzzle exposes the underlying transport knobs; the facade enforces safe defaults.
- **PVE capability and version discovery.** SDN, backup, console, cloud-init, and several response shapes vary by Proxmox version and cluster configuration. The facade probes capability on connection setup and exposes a typed `ClusterCapabilities` object the rest of RackLab uses for feature gating.
- **Task state machine** (see §5).
- **Retry + backoff policy** for transient failures (5xx, connection errors, request-timeout exceptions) via Guzzle's `RetryMiddleware`. Explicit non-retry classes for 4xx semantic errors that should propagate to the worker.
- **Structured error mapping** from `GuzzleHttp\Exception\RequestException` / `ConnectException` / `TransferException` to RackLab-defined exception types: `ProviderAuthError`, `ProviderResourceConflict`, `ProviderNotFound`, `ProviderTransient`, `ProviderBug`, and three distinct timeout types: `ProviderRequestTimeout` (HTTP-level connect/read timeout), `ProviderTaskWaitTimeout` (we gave up waiting for the task, but the task may still be running), and `ProviderOperationDeadlineExceeded` (the worker-side deadline budget elapsed). These three timeouts trigger different recovery paths and must not be conflated.
- **A `FakeProxmoxClient`** in `app/Testing/Fakes/` implementing the same `ProxmoxClientContract` interface for unit and contract tests, plus a real-PVE integration-test fixture (env-var-gated).

### 4.3 What it does not own

- Higher-level RackLab concepts: projects, quotas, RBAC, audit-event emission, scheduler placement decisions, plugin contracts. The facade is a transport-and-shape boundary; the worker / service layer composes it.
- Caching. The facade does not cache Proxmox state. RackLab's reconciliation loop is the authority for state freshness.
- Provider-agnostic interfaces. The generic provider interface (in `app/Events/Hookspecs/Provider/` typed hookspec event classes, per PRD §13) is separate and Proxmox-agnostic (§7).

### 4.4 Why not a community PHP Proxmox package

Community packages reach the Proxmox API cheaply but return raw `array` responses that poison Larastan-strict analysis. The best-known community option (`jefersonflus/proxmox-php-sdk`) is bus-factor-1 and uses `php-curl-class` instead of Guzzle, which mismatch our transport. Codegen from `pve-doc-generator` produces typed PHP output that satisfies Larastan-max without hand-patching, and tracks Proxmox versions automatically on regeneration — the same guarantee community packages can't provide.

## 5. Task-polling and reconciliation discipline

Any polling helper that ships without jitter, per-operation timeout, distributed concurrency limits, or durable persistence is unsuitable at RackLab's scale. The facade's `App\Jobs\PollProxmoxTask` Horizon job owns the polling loop and persists state in RackLab's own `ProviderTask` table.

The discipline:

- **Durable task-row persistence — a real table, not just an audit row.** When the facade submits a clone/snapshot/power/network operation, it parses the returned UPID using a static `Tasks::decodeUpid()`-equivalent helper and immediately inserts (or updates) a `ProviderTask` row — which is a subtype of the universal `Job` ledger in PRD §19. The row carries the UPID, decoded node + pid + starttime + type + id + user, the dispatching worker's lease id, the operation class, the deadline, attempt count, last-poll timestamp, and final status (initially `pending`). Audit rows reference the parent `Job` row by id; they are not the same row. Reconciliation reads from `Job` and pivots to `ProviderTask` only when it needs provider-specific fields.
- **Backoff with jitter and a minimum floor.** Start at 500 ms, exponential to a 2 s cap, full jitter with a 100 ms floor (so we never busy-loop on near-zero sleeps). The 100 ms floor is a guardrail against pathological jitter combined with cluster pressure.
- **Per-operation-class timeouts.** Clone-from-large-template: minutes. Power op: seconds. Snapshot: variable. The facade exposes a registry of named operation classes with sane default deadlines and an override on the call site. A timeout maps to `ProviderTaskWaitTimeout` — *the task may still be running on Proxmox*, the facade has only stopped waiting.
- **Distributed per-node concurrency limit.** A per-process semaphore is the wrong layer — it would let a 20-worker fleet open 160 simultaneous polls against a single Proxmox node. The facade acquires a distributed concurrency lease against the target node before each poll batch. Implementation candidates (decision deferred to implementation planning, but the facade exposes the same interface either way):
  1. **Postgres advisory locks** keyed on `(node, slot)`, with `slot ∈ [0..N)` where N is the configured per-node poll budget. Lowest operational cost, leverages the existing Postgres dependency.
  2. **Redis token bucket** — Redis is already a hard dependency (Horizon queue); this is a natural secondary use.
  Default plan: Postgres advisory locks, until profiling says otherwise.
- **Node-loss handling.** If the node a task is on becomes unreachable, the facade raises `ProviderNodeUnreachable` and leaves the provider-task row in `pending` for the reconciler. It does not silently retry against a different node; that's reconciler policy.
- **Non-`OK` exit-status mapping.** Proxmox tasks complete with an `exitstatus` string; only `"OK"` is success. Anything else maps to a typed `ProviderTaskFailed` with `exitstatus`, partial log, and UPID. The provider-task row transitions to `failed`.
- **Log retrieval.** Verbose task logs are paginated via the Proxmox API endpoint `nodes/{node}/tasks/{upid}/log?start=…&limit=…`. The facade owns the paging loop via a PHP helper method, writes the full log to RackLab's artifact storage (PRD §14) keyed by UPID, and writes a bounded summary into the provider-task row.
- **"Stop waiting" vs "retry the original operation" — explicitly distinct.** This is the most important discipline in this section.
  - **Stop waiting** = the facade gives up polling. The provider-task row stays in its current state (`pending`); the reconciler resumes polling by UPID later. **The original operation is not re-submitted.**
  - **Retry original operation** = re-issue the clone/delete/snapshot/etc. This is only safe when either (a) the original submission *provably* did not reach Proxmox (connection refused before request body sent, with no UPID returned), or (b) the operation has a Proxmox-side idempotency property and we have reconciled target state and confirmed it is consistent with re-issue. The default is **never re-submit**; the reconciler picks up the existing UPID.
- **Cancellation discipline.** User-initiated cancellation or worker job-lease expiry does **not** automatically issue a Proxmox task cancel. The facade exposes an explicit, audited `cancel(task_row)` operation that issues the Proxmox cancel API call and logs both the request and the Proxmox-side outcome. Reconciler-initiated cleanup uses the same path.

These are guardrails the facade enforces, not policies workers can opt out of.

## 5a. Codegen architecture

### Where the generator lives

The generator is an Artisan command: `php artisan racklab:proxmox:generate-client`. It lives in `app/Console/Commands/Proxmox/GenerateClientCommand.php` and runs at build time (not at runtime). The output tree is committed to the repository under `app/Providers/Proxmox/Generated/`; the generator is the source of truth for that directory's contents, not manual edits.

### How it consumes the JSON Schema

Proxmox ships a machine-readable API schema via its `pve-doc-generator` tool. The authoritative dump is available at `GET /api2/json` (the `children` field) on any running PVE cluster, and as a static JSON file extracted from the `pve-doc-generator` Debian package. The generator reads the static JSON file — pinned by version — so generation is hermetic and does not require a live cluster.

The schema describes every API endpoint as a path tree with HTTP method, parameter names and types, response shapes, and human-readable descriptions. The generator traverses this tree and maps Proxmox type annotations (`string`, `integer`, `boolean`, `array`, `object`) to PHP scalar and `readonly` class types. Nullable and optional parameters become nullable PHP types.

### What it emits

- **`app/Providers/Proxmox/Generated/`** — the generated tree. Structure mirrors the Proxmox API path tree:
  - `Access/` — `AccessClient.php` with typed methods for every `/api2/json/access/*` endpoint.
  - `Cluster/` — `ClusterClient.php` and sub-namespace classes.
  - `Nodes/` — `NodesClient.php` and per-node sub-namespace classes.
  - `Storage/` — `StorageClient.php`.
  - `Pools/`, `Version/` — etc.
- **`app/Providers/Proxmox/Generated/Dto/`** — readonly PHP 8 DTO classes for structured response models. Each DTO is a `readonly` class with constructor property promotion; all properties are typed. Arrays of structured objects are typed as `array<int, SomeDtoClass>`.
- **`app/Providers/Proxmox/Generated/GeneratedProxmoxClient.php`** — the root client class that composes the namespace clients; implements `GeneratedProxmoxClientContract` (a sub-interface the discipline layer calls through).

### Schema-version pinning discipline

The generator reads a pinned schema file at `proxmox-schema.json` (committed to the repository root, excluded from the PHP autoloader). The pinned file is named with the PVE version it came from: `proxmox-schema-pve9.2.json`. Regenerating for a new PVE version requires:

1. Extract the new schema JSON from the `pve-doc-generator` package for the target PVE version.
2. Commit the new schema file alongside the old one.
3. Run `php artisan racklab:proxmox:generate-client --schema=proxmox-schema-pveX.Y.json`.
4. Review the generated diff — new endpoints, removed endpoints, changed parameter types.
5. Update `proxmox-schema.json` symlink (or config entry) to the new version.
6. Commit generated code and schema pin together.

The `composer.json` (or a dedicated `proxmox-schema.json` config file) records the current pinned schema version so CI can assert that the generated code and the pinned schema are in sync.

### Regeneration workflow

A nightly CI job re-runs the generator against the latest schema extracted from the current PVE release and opens a PR if the output diff is non-empty. This surfaces API changes before they become gaps in coverage. The PR includes:

- The new schema file.
- The generated diff with a machine-readable summary of added/removed/changed endpoints.
- A Larastan run against the new generated code.

The discipline layer (`TaskPoller`, error mapping, `CapabilityProbe`) is not regenerated — it is hand-maintained and reviewed as part of any nightly-CI PR that touches generated code adjacent to those files.

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
- **Dispatch after commit.** Horizon job dispatch is deferred until after the DB transaction commits by calling `->afterCommit()` on the dispatched job (the `$afterCommit` property on the queued job class). This is not Laravel's broadcast-after-commit pattern (`ShouldBroadcast` plus `ShouldDispatchAfterCommit`), which applies to broadcast events; for Horizon jobs the `afterCommit()` chained call or the `$afterCommit = true` property is the correct mechanism.
- **Update state before acknowledging.** A Horizon worker processes a job to completion only after it has updated the provider-task row (to `pending` with the UPID, or to `failed` with reason) inside its own transaction. Horizon retry + idempotency-key uniqueness on the provider-task row is what prevents duplicate Proxmox submissions on worker retry.
- **Idempotency keys are first-class.** Every mutating operation crossing the boundary carries an idempotency key persisted in the provider-task row with a unique constraint. Re-running a Horizon worker against the same job must produce the same provider-task row, not a second submission.

This section is short on purpose; the canonical place for these patterns is the worker/event spec, not this client doc. It's recorded here so that the facade's `ProviderTaskWaitTimeout` and "never re-submit by default" semantics in §5 are read in the right Laravel/Horizon context.

## 9. Testing

- **Unit tests** against `FakeProxmoxClient` in `app/Testing/Fakes/` (implementing the same `ProxmoxClientContract` interface as the real client). No network, no Guzzle-internal mocking.
- **Generator snapshot test**: runs `GenerateClientCommand` against a committed schema fixture and asserts the output diff against the committed snapshot in `app/Providers/Proxmox/Generated/`. Fails CI if the generated code is not in sync with the pinned schema. Larastan runs against the generated output as part of the same CI gate.
- **Guzzle-boundary tests** using Guzzle's `MockHandler` + `HandlerStack` to inject HTTP fixtures, validating that the facade correctly translates Proxmox raw JSON responses into PHP `readonly` models and correctly maps `GuzzleHttp\Exception\*` into RackLab provider exceptions.
- **Integration tests** against a real Proxmox VE instance (env-var-gated, skipped in default CI, run nightly / on-demand). Cover: clone, snapshot create/restore/delete, power lifecycle, network attach/detach, console-grant issue, task polling under load, and the §5 distributed concurrency limit under a horizontally-scaled worker fleet.

## 10. Migration path if the generated client or transport becomes inadequate

Because everything above the discipline layer depends only on PHP `readonly` domain models + the `ProxmoxClientContract` interface:

1. Build an alternative `App\Providers\Proxmox\AlternativeClient` implementing the same `ProxmoxClientContract` interface (e.g., backed by a regenerated client from an updated schema, a community PHP Proxmox package if one matures to strict-typed status, or a different HTTP transport).
2. Port endpoint by endpoint, behind a feature flag, with the Guzzle-boundary test suite as the regression seam.
3. Cut over when coverage is at parity for RackLab's used endpoints; the fallback is the existing generated client until the alternative is stable.

Estimated effort: **days to weeks** depending on the scope of change. The discipline layer abstraction (`ProxmoxClientContract`) is what makes this path tractable — the rest of RackLab never calls the generated layer directly.

## 11. Open risks

- **Generator regeneration discipline.** The generated client falls behind Proxmox API changes if it is not regenerated after Proxmox cluster upgrades. Mitigation: (a) the nightly CI job re-runs the generator against the latest schema and opens a PR if the diff is non-empty; (b) schema version is pinned in `proxmox-schema.json` and the generator-snapshot CI gate fails if the committed generated code is not in sync with the pinned schema. Regeneration on a major PVE bump requires a review pass: new endpoints, removed/renamed parameters, changed response shapes.
- **Schema-version pinning overhead.** Each PVE upgrade requires extracting the new schema, pinning it, regenerating, and reviewing the diff. This is intentional — the review step is what catches breaking API changes before they reach production. The nightly CI job automates detection; the human review step is the discipline.
- **Generated DTO types may not capture all Proxmox response shape variants.** Proxmox uses loosely-typed Perl internally; some response fields are conditionally present (present only when a flag is set, absent otherwise). The generator emits nullable types for optional fields; the discipline layer validates shapes at runtime via constructor property promotion and treats missing optional fields as null. Integration tests against real PVE clusters surface shape mismatches.
- **Guzzle concurrency is bounded by Horizon worker pool size.** The `--max-processes` and per-pool queue tag configuration is the visible knob; size it before load testing against a real Proxmox cluster.
- **PVE version drift.** Capability discovery (§4.2) is the first-line mitigation; integration tests across at least two PVE major versions (PVE 8.x and PVE 9.x) catch the rest. The nightly CI generator run also surfaces API additions/removals before they become gaps.

## 12. Confidence

**High** on the transport choice — Guzzle 7.10 is already a hard Laravel dependency, covers all required features, and the discipline-layer pattern ensures the rest of RackLab never touches raw HTTP or raw `array` responses.

**High** on the codegen strategy — reading from Proxmox's authoritative `pve-doc-generator` schema gives us the same endpoint surface as Proxmox's own `libpve-apiclient-perl`, with typed PHP output and no Perl runtime dependency. The nightly regeneration CI job keeps the client in sync automatically.

**High** on the discipline-layer pattern — it solves the Larastan-strict requirement, isolates the generated-client boundary, and preserves the migration option in §10.

**Medium** on the specific task-polling parameter values (500 ms initial, 2 s cap, 100 ms minimum floor, per-node poll budget). These are reasoned defaults that should be re-tuned against real Proxmox cluster behavior in early integration testing.

**Medium** on the choice of Postgres advisory locks as the §5 distributed concurrency primitive. Postgres is already a hard dependency, the API is simple, and the operational story is clear; if profiling shows the lock-acquire latency itself becomes a bottleneck against busy clusters, switch to a Redis token bucket (Redis is already a hard dependency for Horizon).

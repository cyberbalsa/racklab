# M3 — Proxmox Provider

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M2.
**Unblocks:** M4, M5a, M6, M12.

## Goal

Replace the fake provider with the real Proxmox VE integration. After M3, a user deploys a real VM on a real Proxmox cluster from the RackLab dashboard. Clone, snapshot, power, and inventory operations all work end-to-end through the `App\Providers\Proxmox\Client` typed facade per the design spec. The client's task-polling discipline (distributed per-node concurrency, durable `ProviderTask` rows, "stop waiting" vs "retry" semantics) is exercised in production-shaped tests against a real Proxmox cluster.

## In scope

- `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md` — the discipline applies through the PHP stack-carry-forward mapping note in the spec header; Python examples translate row-by-row to the PHP/Guzzle implementation described there.
- PRD §12 Proxmox provider.
- PRD §19 data model `ProviderTask` subtype (Proxmox UPID parts).
- The `racklab/provider-proxmox` plugin per PRD §13.

## Dependencies

- M2 deployment lifecycle running against the fake provider.
- M2 Horizon provider-worker + scheduler-reconciler infrastructure.
- M0 plugin framework — `racklab-provider-proxmox` is a real plugin installed/migrated/enabled via the lifecycle.

## Deliverables

- `app/Providers/Proxmox/` module: the typed facade per the spec.
  - `Client.php` — the `ProxmoxClientContract` interface + `GuzzleProxmoxClient` concrete implementation backed by Guzzle 7.10 (no community PHP Proxmox packages — they are too thin/unmaintained). Registered as a singleton in `App\Providers\ProxmoxServiceProvider` with per-tenant credential injection.
  - `Models/` — PHP readonly domain models (`Node`, `Vm`, `Task`, `Snapshot`, `NetworkInterface`, `StorageVolume`, `ConsoleAccessGrant`). Populated from Guzzle-decoded JSON; construction validated via constructor property promotion and `readonly` enforcement.
  - `Exceptions/` — structured exception hierarchy (`ProviderRequestTimeout`, `ProviderTaskWaitTimeout`, `ProviderOperationDeadlineExceeded`, `ProviderResourceConflict`, `ProviderNodeUnreachable`, `ProviderTaskFailed`, `ProviderAuthError`, `ProviderNotFound`, `ProviderTransient`, `ProviderBug`).
  - `TaskPoller.php` — the polling loop: backoff with jitter (500 ms start, 2 s cap, 100 ms floor), per-operation-class deadline registry, distributed per-node concurrency via Postgres advisory locks (keyed on `(node_id, slot)` where `slot ∈ [0..N)`), durable `ProviderTask` row updates, UPID decode via `Tasks::decodeUpid()`-equivalent static helper.
  - `Tls.php` — the multi-issuer CA bundle composition (Let's Encrypt / self-signed / custom ACME) per the spec §4.2. `verify_ssl=false` rejected by configuration validation outside dev mode.
  - `CapabilityProbe.php` — probes SDN, backup, console, cloud-init, and version at connection setup; exposes a typed `ClusterCapabilities` value object.
- `racklab-provider-proxmox` plugin (`packages/racklab/provider-proxmox`): declares the `racklab-provider-proxmox` ServiceProvider, registers capability `provider:proxmox:v1`, declares the `ProviderTask` subtype migration, exposes the provider contract via the `app/Events/Hookspecs/Provider/` typed hookspec event classes.
- `ProviderTask` model with Proxmox-specific fields per PRD §19: `upid`, `proxmox_node`, `proxmox_pid`, `proxmox_starttime`, `proxmox_type`, `proxmox_vm_id`, `proxmox_user`, `idempotency_key` (unique constraint), `lease_expires_at`, `attempt_count`, `last_polled_at`. The idempotency-key uniqueness constraint is the barrier that prevents duplicate Proxmox submissions on Horizon worker retry.
- Capability/version discovery against the connected PVE cluster (SDN, backup, console, cloud-init capabilities probed at startup and surfaced as a typed `ClusterCapabilities` object).
- Filament admin resource: Proxmox endpoint configuration (URL, API token id + secret, CA bundle upload for self-signed / custom ACME, `verify_ssl` toggle rejected in non-dev mode).
- A `FakeProxmoxClient` shipped in `app/Testing/Fakes/` implementing `ProxmoxClientContract` — used for unit and contract tests so the actual Guzzle boundary is exercised separately.
- **Provider inventory models**: `Provider`, `ProviderEndpoint`, `ProviderCluster`, `ProviderNode`, `ProviderStorage`, `ProviderNetworkBinding`, `ProviderCapacitySnapshot` (per PRD §19 §Providers). The inventory-discovery side of the Proxmox plugin produces `ProviderCapacitySnapshot` rows on a Horizon scheduled job. M6 consumes them.
- `App\Jobs\PollProxmoxTask` Horizon job: implements the "stop waiting / resume by UPID" discipline — consumes a `ProviderTask` row, polls the Proxmox API, updates the row, never re-submits the original operation; reconciler resumes polling by UPID on crash.

## Acceptance criteria

- [ ] A user deploys a real VM from a Proxmox-template-backed catalog item; clone completes; the VM is powered on; the deployment reaches `running` with the real Proxmox VMID persisted on the `DeploymentResource` row.
- [ ] The deployment's audit trail includes Proxmox UPIDs for every API action with target node + result + elapsed time per PRD §14.
- [ ] The `GuzzleProxmoxClient` facade succeeds against a Proxmox endpoint whose server certificate was issued by: (a) Let's Encrypt (public-trust validation), (b) a self-signed CA (operator-pinned bundle), (c) a custom-ACME issuer (operator-supplied root + intermediates).
- [ ] `verify_ssl=false` is rejected at configuration validation outside dev mode.
- [ ] Distributed per-node concurrency limit holds under load: 20 simultaneous Horizon worker requests against one Proxmox node poll with the configured concurrency (default 8); no node receives more than the configured limit in parallel; advisory-lock release behaves correctly on worker crash.
- [ ] `ProviderTaskWaitTimeout` correctly does **not** re-submit the original operation; the reconciler resumes polling by UPID.
- [ ] Snapshot create / list / restore / delete against a real Proxmox cluster passes the integration suite.
- [ ] An operator-issued cancellation calls the Proxmox task cancel API explicitly and audits both the request and the Proxmox-side outcome.
- [ ] Capability discovery against PVE 8.x and **PVE 9.2** (released 2026-05-21, current line) both succeed and surface the appropriate feature flags; the catalog refuses deployments requiring features the connected cluster doesn't expose. PVE 9.2's SDN dynamic-load-balancer behavior is in the test matrix.

## Test layers

- **Tiny / unit** (Pest 4): UPID parsing; CA-bundle composition logic; the polling backoff curve (assertions on retry timings); the exception mapping table; idempotency-key uniqueness assertion.
- **Contract** (Pest 4 + `Http::fake()`): `FakeProxmoxClient` passes the same `ProxmoxClientContract` suite as the real client; Guzzle-boundary tests using `Http::fake()` with Proxmox API response fixtures, validating that the facade correctly translates raw JSON responses into PHP domain models and correctly maps Guzzle exceptions into RackLab provider exceptions.
- **Integration** (Pest 4 + Testcontainers): real Guzzle client against a testcontainers-style Proxmox-API mock (a small HTTP server speaking enough of the Proxmox API for clone/snapshot/power/console-ticket — same mock RackLab uses in CI for E2E in M2 and later); distributed concurrency test with multiple Horizon worker processes against testcontainers Postgres for advisory locks.
- **Integration (nightly)**: full suite against a real Proxmox VE 8.x and 9.x test cluster (operator-provided; skip-unless-env-vars-set). Same test code, real PVE.
- **Browser E2E** (Dusk + axe-core): the M2 E2E flow swaps from fake provider to real (mocked) Proxmox; the user-facing deploy + console-link (M4) + release works end-to-end. axe-core regression gate stays green.

## Risks / open questions

- **Proxmox API token permissions**: the API token RackLab uses needs explicit permissions on every required path. Document the minimum-permission set; an Artisan command generates the right token.
- **PVE 9.0 SDN behavior**: PVE 9.0 ships on Debian 13 with a newer kernel and the SDN behavior differs subtly from 8.x. Capability discovery handles known differences; unknown differences need integration tests against both.
- **Guzzle-level task-poll defaults**: unlike `proxmoxer`, Guzzle is a general-purpose HTTP client — the polling loop is entirely owned by `TaskPoller.php` from day one. No inherited defaults to override; verify in CI that `PollProxmoxTask` never calls `POST` twice on the same idempotency key.
- **Guzzle connection pool sizing**: configure `GuzzleHttp\HandlerStack` with connection concurrency appropriate for a 4-core lab box and a 32-core production host. Default to `min(32, cpu_count() + 4)` with a config override.
- **Octane singleton hazard for the Proxmox client**: `SetTenantContextForOctane` middleware must reset the Proxmox client singleton between requests if per-tenant credentials apply (codex P1 from the Laravel redesign spec §5). Contract test verifies two consecutive requests for different tenants get separate credentials.

## Out of scope (deferred)

- Console plugin (noVNC + xterm.js) — M4.
- Network attach (Proxmox SDN, bridges, VLANs, VNets) — M5a.
- Cloud-init injection at provision time — M7a.
- Provider scheduler with placement decisions — M6.
- Multi-cluster federation — post-v1.

# M3 — Proxmox Provider

**Status:** Not started.
**Estimated effort:** 3–4 weeks.
**Depends on:** M2.
**Unblocks:** M4, M5a, M6, M12.

## Goal

Replace the fake provider with the real Proxmox VE integration. After M3, a user deploys a real VM on a real Proxmox cluster from the RackLab dashboard. Clone, snapshot, power, and inventory operations all work end-to-end through the `ProxmoxClient` typed facade per the design spec. The Proxmox client's task-polling discipline (distributed per-node concurrency, durable `ProviderTask` rows, "stop waiting" vs "retry" semantics) is exercised in production-shaped tests against a real Proxmox cluster.

## In scope

- `docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md` in its entirety.
- PRD §12 Proxmox provider.
- PRD §19 data model `ProviderTask` subtype (Proxmox UPID parts).
- The `racklab-provider-proxmox` plugin per PRD §13.

## Dependencies

- M2 deployment lifecycle running against the fake provider.
- M2 NATS + provider-worker + reconciler infrastructure.
- M0 plugin framework — `racklab-provider-proxmox` is a real plugin installed/migrated/enabled via the lifecycle.

## Deliverables

- `racklab/providers/proxmox/` package: the typed facade per the spec.
  - `client.py` — the `ProxmoxClient` Protocol + the concrete implementation.
  - `models.py` — Pydantic v2 domain models (`Node`, `VM`, `Task`, `Snapshot`, `NetworkInterface`, `StorageVolume`, `ConsoleAccessGrant`).
  - `errors.py` — the structured exception hierarchy (`ProviderRequestTimeout`, `ProviderTaskWaitTimeout`, `ProviderOperationDeadlineExceeded`, `ProviderResourceConflict`, `ProviderNodeUnreachable`, `ProviderTaskFailed`, etc.).
  - `task_polling.py` — the polling loop with backoff + jitter + distributed per-node concurrency via Postgres advisory locks; durable `ProviderTask` rows.
  - `tls.py` — the multi-issuer CA bundle composition (LE / self-signed / custom ACME) per the spec §4.2.
  - `executor.py` — the bounded `ThreadPoolExecutor` used by `asyncio.to_thread`.
- `racklab-provider-proxmox` plugin: registers entry points, declares capability `provider:proxmox:v1`, declares the `ProviderTask` subtype migration, exposes the provider Protocol via the `racklab_provider_*` hookspecs.
- `ProviderTask` model with Proxmox-specific fields per PRD §19.
- Capability/version discovery against the connected PVE cluster (SDN, backup, console, cloud-init capabilities probed at startup and surfaced as a typed `ClusterCapabilities` object).
- Admin UI: Proxmox endpoint configuration (URL, API token id + secret, CA bundle upload for self-signed / custom ACME, verify_ssl toggle that is rejected in non-dev mode).
- A `MockProxmoxClient` shipped in `racklab/testing/fakes/` implementing the same Protocol — used for unit and contract tests so the actual `proxmoxer` boundary is exercised separately.
- **In-tree `proxmoxer` type stubs** at `stubs/proxmoxer/` (with `mypy_path` in `pyproject.toml` pointing at them) so the typed facade's boundary code is mypy-strict-clean without `# type: ignore`. This resolves the contradiction between the Proxmox client spec §10's allowance for `# type: ignore` and PRD §17's no-overrides discipline: the stubs are the answer.
- **Provider inventory models**: `Provider`, `ProviderEndpoint`, `ProviderCluster`, `ProviderNode`, `ProviderStorage`, `ProviderNetworkBinding`, `ProviderCapacitySnapshot` (per PRD §19 §Providers). The inventory-discovery side of the Proxmox plugin produces `ProviderCapacitySnapshot` rows on a schedule. M6 consumes them.

## Acceptance criteria

- [ ] A user deploys a real VM from a Proxmox-template-backed catalog item; clone completes; the VM is powered on; the deployment reaches `running` with the real Proxmox VMID persisted on the `DeploymentResource` row.
- [ ] The deployment's audit trail includes Proxmox UPIDs for every API action with target node + result + elapsed time per PRD §14.
- [ ] The `ProxmoxClient` facade succeeds against a Proxmox endpoint whose server certificate was issued by: (a) Let's Encrypt (public-trust validation), (b) a self-signed CA (operator-pinned bundle), (c) a custom-ACME issuer (operator-supplied root + intermediates).
- [ ] `verify_ssl=False` is rejected at configuration validation outside dev mode.
- [ ] Distributed per-node concurrency limit holds under load: 20 simultaneous worker requests against one Proxmox node poll with the configured concurrency (default 8); no node receives more than the configured limit in parallel; advisory-lock release behaves correctly on worker crash.
- [ ] `ProviderTaskWaitTimeout` correctly does **not** re-submit the original operation; the reconciler resumes polling by UPID.
- [ ] Snapshot create / list / restore / delete against a real Proxmox cluster passes the integration suite.
- [ ] An operator-issued cancellation calls the Proxmox task cancel API explicitly and audits both the request and the Proxmox-side outcome.
- [ ] Capability discovery against PVE 8.x and **PVE 9.2** (released 2026-05-21, current line) both succeed and surface the appropriate feature flags; the catalog refuses deployments requiring features the connected cluster doesn't expose. PVE 9.2's SDN dynamic-load-balancer behavior is in the test matrix.

## Test layers

- **Tiny / unit**: UPID parsing; CA-bundle composition logic; the polling backoff curve (assertions on retry timings); the exception mapping table.
- **Contract**: `MockProxmoxClient` passes the same Protocol suite as the real client; `proxmoxer` boundary tests using its in-built test fixtures + `responses` to fake Proxmox HTTP responses (validates raw-dict → Pydantic translation and exception mapping).
- **Integration**: real `proxmoxer` against testcontainers-style Proxmox-API mock (a small Python HTTP server speaking enough of the Proxmox API for clone/snapshot/power/console-ticket — same mock RackLab uses in CI for E2E in M2 and later); distributed concurrency test with multiple worker processes against testcontainers Postgres for advisory locks.
- **Integration (nightly)**: full suite against a real Proxmox VE 8.x and 9.x test cluster (operator-provided; skip-unless-env-vars-set). Same test code, real PVE.
- **E2E**: the M2 E2E flow swaps from fake provider to real (mocked) Proxmox; the user-facing deploy + console-link (M4) + release works end-to-end. axe-core regression gate stays green.

## Risks / open questions

- **Proxmox API token permissions**: the API token RackLab uses needs explicit permissions on every required path. Document the minimum-permission set; a bootstrap script generates the right token.
- **PVE 9.0 SDN behavior**: codex flagged that PVE 9.0 ships on Debian 13 with a newer kernel and the SDN behavior differs subtly from 8.x. Capability discovery handles known differences; unknown differences need integration tests against both.
- **`proxmoxer` 2.3.0 task-poll defaults are wrong/docs-source mismatched** (docs say 0.01s, source says 1s). The facade owns the polling loop, so this is contained — but verify in CI that we never invoke `blocking_status` with defaults.
- **`asyncio.to_thread` pool sizing**: the bounded executor needs a default size that's reasonable for a 4-core lab box and a 32-core production host. Probably default to `min(32, (os.cpu_count() or 4) + 4)` with an override.
- **Connection pooling at the `proxmoxer` boundary**: `proxmoxer`'s default `requests` session doesn't pool aggressively; under load this matters. Configure a `requests.Session` with appropriate `HTTPAdapter` settings.

## Out of scope (deferred)

- Console plugin (noVNC + xterm.js) — M4.
- Network attach (Proxmox SDN, bridges, VLANs, VNets) — M5a.
- Cloud-init injection at provision time — M7a.
- Provider scheduler with placement decisions — M6.
- Multi-cluster federation — post-v1.
- The future `proxmoxer` migration to a custom `httpx` client — documented in the spec §10; not in M3.

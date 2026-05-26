# RackLab Roadmap Review

## TL;DR

- Verdict: **not implementable as written**. The roadmap is close in intent, but the dependency graph and several acceptance criteria need rework before the team commits.
- Biggest blockers: M10/M11 dependency contradictions, non-existent `uv pip audit`, plugin package persistence in containerized installs, and M5 networking scope that promises both read-mostly SDN and full Neutron-like realization.
- The prior architecture review is mostly reflected, but some corrections were only half-applied: universal `Job` is present, warmed-replica metrics are present, but operational packaging, CI reality, and TLS/UI ordering are still unstable.
- 2026 ecosystem check found several roadmap updates needed: Tiptap is 3.x with Markdown still marked beta, Proxmox VE 9.2 is current, Nomad has IBM-era 2.0 support/licensing changes, and `uv pip audit` is not a valid command.

## P0 — Will Break The Implementation

- [docs/roadmap/README.md:Dependency graph; docs/roadmap/M10-ui-a11y-i18n.md:Header; docs/roadmap/M10-ui-a11y-i18n.md:Acceptance criteria] M10’s graph is wrong. README says `M2 --> M10`, but M10 says it depends on “M2 + everything down to M9” and its acceptance requires screens/features from M3-M9. Starting M10 after M2 is impossible. Fix the graph to depend on M3-M9, or split M10 into an early UI shell milestone and a final polish/a11y hard-pass.

- [docs/roadmap/M11-tls-acme.md:Header; docs/roadmap/M11-tls-acme.md:Dependencies; docs/roadmap/M10-ui-a11y-i18n.md:Test layers] M10 and M11 form an implicit cycle. M11 header says it depends only on M0, but its dependencies say it needs M10 admin GUI plumbing. M10’s E2E list includes “admin-configure-ACME-issuer” as a preview for M11. Fix by splitting TLS into: `TLS backend + bootstrap + Traefik config` before M10, then `TLS admin GUI + E2E` after M10’s admin shell exists.

- [docs/roadmap/M00-foundations.md:Deliverables; docs/prd/17-engineering-quality-typing-ci.md:CI] M0 requires CI to run `uv pip audit`, but current `uv` does not expose that subcommand. Astral’s docs describe the `uv pip` interface as pip-like package/env operations, and inspection includes `uv pip check`, not audit: <https://docs.astral.sh/uv/pip/> and <https://docs.astral.sh/uv/pip/inspection/>. Fix M0 to use `pip-audit` explicitly, e.g. `uv run pip-audit` or `uv export --format requirements-txt | pip-audit -r -`.

- [docs/roadmap/M00-foundations.md:Deliverables; docs/prd/13-plugin-system.md:Plugin Lifecycle; docs/prd/16-container-operations.md:Images And Services] Runtime plugin installation is underspecified for immutable Podman/Nomad deployments. `racklab plugin install` puts packages into “the Python environment,” but PRD §16 ships versioned container images and upgrade/rollback flows. Without a persistent plugin wheelhouse/venv/lock strategy, installed plugins can disappear or drift across container restarts and image refreshes. Add a packaging/install milestone or M0 requirement for plugin artifact persistence, lockfile updates, SBOM/license scanning, and rollback across Baseline and Scale.

- [docs/roadmap/M05-networking.md:Deliverables; docs/roadmap/M05-networking.md:Acceptance criteria; docs/roadmap/M05-networking.md:Out of scope; docs/prd/09-networking.md:Provider Mapping] M5 promises full Network/Subnet/Router/FloatingIP/SecurityGroup realization while also saying plugin-managed Proxmox SDN object creation is out of scope and M5 is read-mostly. Those cannot both be true for router/FIP/SG acceptance. Split M5 into `M5a network attach + reachability + admin-published provider networks` and `M5b managed router/FIP/SG realization`, or move managed SDN/router/FIP to v1.1.

## P1 — Needs Fix Before The Milestone Starts

- [docs/roadmap/M01-auth-identity.md:Acceptance criteria; docs/prd/06-auth-rbac-sharing-tokens.md:Authentication] M1 only ships local auth, but acceptance requires audit events for `oauth_link_attempt` and `saml_link_attempt`. Those flows are explicitly deferred as plugins. Move those audit criteria to the auth-plugin milestones or require only schema registration in M1.

- [docs/roadmap/M00-foundations.md:Acceptance criteria; docs/prd/13-plugin-system.md:Plugin Lifecycle] M0 says “Disabling and rolling back” reverses schema/config changes. PRD §13 says disabling does **not** reverse migrations; rollback/uninstall is a separate disabled-only step. Rewrite the acceptance criterion as disable leaves schema/data intact, rollback reverses migrations, uninstall rolls back to zero.

- [docs/roadmap/M04-console-proxmox.md:Dependencies; docs/roadmap/M02-deployment-lifecycle.md:Deliverables; docs/prd/17-engineering-quality-typing-ci.md:Baseline Stack] M4 depends on a `console-worker` scaffold and Channels baseline, but M0/M2 do not clearly deliver a console-worker process skeleton. Add Channels and empty `console-worker` pool to M0/M2, or make M4 explicitly create both.

- [docs/roadmap/M06-quotas-scheduling.md:Dependencies; docs/roadmap/M03-proxmox-provider.md:Deliverables; docs/prd/19-data-model.md:Providers] Provider inventory ownership is unclear. M6 depends on `ProviderCapacitySnapshot` rows from M3, but M3 does not explicitly deliver Provider/Endpoint/Cluster/Node/Storage inventory models. Move the provider inventory model and capacity snapshot producer into M3, then let M6 consume it.

- [docs/roadmap/M02-deployment-lifecycle.md:Deliverables; docs/roadmap/M06-quotas-scheduling.md:Deliverables; docs/prd/08-catalog-stacks-deployments.md:Deployment Lifecycle] `Lease` is delivered in both M2 and M6. Decide ownership: either M2 owns basic lease model/expiry cleanup and M6 adds quota-coupled lease limits, or M6 owns leases and M2 removes lease expiration acceptance.

- [docs/roadmap/M07-scripts-automation.md:Deliverables; docs/roadmap/M09-ssh-plugin.md:Dependencies; docs/prd/23-ssh-plugin.md:Host-Key Verification] M9 depends on M7 for cloud-init host-key phone-home and service-key injection, but M7 does not make those explicit deliverables or acceptance criteria. Add them to M7, or move that whole cloud-init extension into M9 and remove the ambiguous “or here if M7 didn’t include it.”

- [docs/roadmap/M08-docs-plugin.md:Header; docs/prd/15-ui-ux.md:UI Architecture; docs/prd/22-docs-plugin.md:Editor] M8’s dependency on M4 is unjustified. M4 establishes xterm/noVNC console assets, not TipTap/ProseMirror. If the real dependency is “frontend asset pipeline,” create that in M0/M1/M2; otherwise make M8 depend on M1 only.

- [docs/roadmap/M08-docs-plugin.md:Risks / open questions; docs/prd/22-docs-plugin.md:Editor] The roadmap says TipTap Markdown matured, but official Tiptap docs still label Markdown “Beta” and warn about edge cases: <https://tiptap.dev/docs/editor/markdown>. Tiptap 3.0 became stable in July 2025, not 6.x: <https://tiptap.dev/blog/release-notes/tiptap-3-0-is-stable>. M8 needs a required spike for Markdown/custom `racklabRef` round-trip fidelity before implementation.

- [docs/roadmap/M03-proxmox-provider.md:Risks / open questions; docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md:Open risks; docs/prd/17-engineering-quality-typing-ci.md:No overrides] Proxmox spec says `# type: ignore` at the `proxmoxer` facade boundary is acceptable, but PRD/M0 ban production `# type: ignore`. Decide before M3: write in-tree stubs for `proxmoxer`, isolate with typed adapters returning `Any` only at one boundary, or create a documented linter exception. Do not leave this to implementation.

- [docs/roadmap/M02-deployment-lifecycle.md:Test layers; docs/prd/17-engineering-quality-typing-ci.md:End-to-end] Quadlet/systemd E2E in hosted CI is not a normal GitHub Actions path. Specify a self-hosted Linux/systemd runner, nested VM runner, or a direct-process E2E profile. Otherwise the “real worker fleet / Quadlets in CI” requirement will stall M2.

- [docs/roadmap/M12-scale-profile.md:Deliverables; docs/roadmap/M12-scale-profile.md:Acceptance criteria; docs/superpowers/specs/2026-05-24-podman-orchestration.md:Minimum viable v1] M12 says Nomad servers are 3-node Raft, but acceptance says a two-host install with “one Nomad-server host, one worker host.” That acceptance cannot prove the delivered HA topology. Either call it a non-HA smoke install or require 3 server nodes for the acceptance drill.

- [docs/roadmap/M12-scale-profile.md:Out of scope; docs/roadmap/M13-operational-maturity.md:Deliverables] NATS HA is contradictory. M12 says HA NATS JetStream is deferred to M13, while also saying Scale uses a 3-node NATS Quadlet cluster. Pick one: single NATS in M12 and HA NATS in M13, or 3-node NATS in M12 with M13 adding failover drills.

- [docs/roadmap/M12-scale-profile.md:Goal; docs/roadmap/M12-scale-profile.md:Dependencies] M12 says Nomad schedules every RackLab container and all worker pools, but it does not depend on M7/M9 and can run before script/SSH workers exist. Scope M12 to “all worker pools implemented so far” or add dependencies on M7/M9.

- [docs/roadmap/M13-operational-maturity.md:Deliverables; docs/prd/14-audit-logging-observability.md:Observability; docs/prd/16-container-operations.md:Operational Requirements] M13 is too broad for one milestone: HA Postgres, HA NATS, OTel, Grafana, backup/restore, upgrades, mutation testing, soak, security review. Split it or the milestone will become a GA catch-all that cannot be managed.

## P2 — Nice To Fix

- [docs/roadmap/M03-proxmox-provider.md:Acceptance criteria; docs/roadmap/M05-networking.md:Risks / open questions] Proxmox VE 9.2 was released May 21, 2026 with Dynamic Load Balancer and expanded SDN. M3/M5/M6 should test 8.x plus current 9.2, not just generic 9.x. Source: <https://proxmox.com/en/about/company-details/press-releases/proxmox-virtual-environment-9-2>.

- [docs/roadmap/M04-console-proxmox.md:Deliverables; docs/roadmap/M09-ssh-plugin.md:Deliverables] xterm.js should be pinned as `@xterm/xterm` rather than the legacy `xterm` package; current npm reports `@xterm/xterm` 6.0.0, and upstream notes package migration to `@xterm`: <https://github.com/xtermjs/xterm.js/releases>. noVNC should be pinned from upstream releases; noVNC 1.7.0 is current: <https://github.com/novnc/noVNC/releases>.

- [docs/roadmap/M07-scripts-automation.md:Risks / open questions] Ansible Runner is current enough, but the roadmap should pin a tested `ansible-runner`/`ansible-core` pair and execution environment image, not just Runner. PyPI shows Ansible Runner 2.4.3 in 2026: <https://pypi.org/project/ansible-runner/>.

- [docs/roadmap/M09-ssh-plugin.md:Risks / open questions] AsyncSSH is current and suitable, but M9 should pin a tested version and optional extras policy. PyPI shows AsyncSSH 2.23.0 and Python >=3.10: <https://pypi.org/project/asyncssh/>.

- [docs/roadmap/M00-foundations.md:Risks / open questions; docs/prd/17-engineering-quality-typing-ci.md:Baseline Stack] Pin Django 5.2 LTS explicitly in M0. Django 6.0 is current on PyPI but requires Python >=3.12; PRD pins Django 5.2 LTS. Official Django 5.2 notes confirm LTS and Python 3.10-3.14 as of 5.2.8: <https://docs.djangoproject.com/en/6.0/releases/5.2/>.

- [docs/roadmap/M11-tls-acme.md:Deliverables; docs/superpowers/specs/2026-05-24-server-side-tls-acme.md:Hot-reload vs restart boundary] Keep the OCSP restart boundary explicit. Traefik documents OCSP as static config: <https://doc.traefik.io/traefik/v3.5/reference/install-configuration/tls/ocsp/>.

## P3 — Notes For The Record

- [docs/roadmap/README.md:Milestones] The 45-65 person-week estimate is optimistic. The roadmap has at least three “product-sized” chunks hidden as milestones: M5 networking, M7 scripting sandbox, and M13 operational maturity.

- [docs/roadmap/M12-scale-profile.md:Risks / open questions; docs/superpowers/specs/2026-05-24-podman-orchestration.md:License acceptance] The Nomad BSL note is correctly present, but the roadmap should reflect IBM-era Nomad 2.0 support/version changes before production commitment. Source: <https://docs.hashicorp.com/nomad/docs/ce-license-support>.

- [docs/roadmap/M03-proxmox-provider.md:Risks / open questions] `proxmoxer` 2.3.0 is still the current release and the typed facade remains a reasonable call. PyPI confirms 2.3.0 was released March 4, 2026: <https://pypi.org/project/proxmoxer/>.

## Project Suggestions

### Missing milestones

- Add `M0.5 Packaging + Runtime Install`: container image build, Baseline install script, plugin package persistence, SBOM/license scan, dependency audit command, artifact/secret storage bootstrap, and upgrade-safe plugin lock handling. It should sit after M0 and before M2/M11/M12.

- Add `M2.5 Baseline Ops Smoke`: prove `scripts/baseline-install.sh`, Quadlets, Postgres, NATS, worker drain, health checks, and self-signed TLS bootstrap before TLS/Scale depend on them.

- Add `M5a/M5b Networking Split`: `M5a` for provider networks, NIC attach, reachability, and SSH gating; `M5b` for managed routers, FIPs, SG realization, drift repair, and any Proxmox SDN object creation.

### Splits / merges

- Split M11 into TLS backend/bootstrap and TLS admin GUI/E2E.
- Split M13 into HA data tier, observability/alerting, backup/restore/upgrade drills, and release hardening.
- Split M7 into cloud-init/provisioning hooks first, then script sandbox/Ansible/openQA runners.

### Scope adjustments

- Move OAuth/SAML audit acceptance out of M1.
- Decide whether `Lease` belongs to M2 or M6.
- Move host-key phone-home and SSH service-key cloud-init hooks into M7 acceptance.
- Move provider inventory/capacity snapshot models into M3.
- Scope M12 to implemented pools or make it depend on M7/M9.

### Tooling / process additions

- Replace `uv pip audit` with `pip-audit`.
- Require self-hosted systemd/Podman CI or a documented direct-process E2E mode.
- Add SBOM generation, license policy checks, and plugin wheel provenance checks to M0/M0.5.
- Add CSP/Trusted Types/sanitization regression tests before M8, not only in M10.
- Require runbook smoke tests as CI jobs for Baseline and Scale.

### Spikes worth running before specific milestones

- Before M3: real PVE 8.x and 9.2 clone/snapshot/network/console smoke.
- Before M5: Proxmox SDN 9.2 dynamic load balancer and SDN fabric behavior.
- Before M7: nsjail profile escape tests and artifact redaction tests.
- Before M8: TipTap Markdown beta + custom `racklabRef` round-trip.
- Before M9: Daphne vs uvicorn binary WebSocket load test and redaction chunk-boundary fuzzing.
- Before M12: Nomad Podman driver bind-mount, ACL/TLS/secrets, and autoscaler PromQL proof.

### 2026 ecosystem changes that affect the roadmap

- Tiptap is 3.x, not 6.x, and Markdown remains beta.
- Proxmox VE current line is 9.2 as of May 21, 2026.
- Nomad CE has IBM-era 2.0 version/support model and remains BSL.
- xterm.js packaging has moved to `@xterm/*`.
- `uv pip audit` is not available; use `pip-audit`.
- Django 5.2 LTS remains the right PRD-aligned pin.

### Things to flag as v2 / explicit non-goals

- Full managed EVPN/SDN object creation unless M5 is split.
- SSH CA/per-user SSH certificates.
- DNS-01/wildcard certs.
- Scale-to-zero worker pools.
- Multi-region HA/DR.
- Docs real-time collaboration, comments, tables, mentions.
- Script marketplace and cross-deployment orchestration.
- Mobile-first redesign beyond Bootstrap responsiveness.

## Verdict

The roadmap is **not ready to commit as written**. It is directionally strong, but it needs a dependency-graph pass, a packaging/installer milestone, and scope splits for TLS/UI, networking, scripting, and operational maturity before it becomes executable.

# RackLab End-to-End Review

Codex CLI (high reasoning effort) ran an end-to-end review of the repo on 2026-05-24 and produced the findings below. Preserved verbatim. Triage and fixes landed in the commits immediately following this file (`docs: save codex e2e review and apply quick-win fixes` and the five subsequent commits).

## TL;DR

- The overall architecture is coherent, but the late-session specs have not been propagated back into the PRD consistently.
- Biggest blocker: the SSH plugin assumes `console-worker` can reach isolated tenant networks, but no network attachment/routing model makes that true.
- Plugin-shipped Django migrations, artifact retention, and worker/job state need real lifecycle designs before implementation.
- The frontend slate contains at least one bad 2026 choice: `blueimp jQuery File Upload` is archived.
- Django 5.2 LTS is still a sound baseline, but PRD §17 is missing late additions like Channels and Pydantic.

## P0 — Will Break The Implementation

- [docs/prd/23-ssh-plugin.md:Back-End / Bastion Topology; docs/architecture/diagrams.md:System component overview] `racklab-console-ssh` promises SSH into `private-isolated` and `private-nat` VMs through `console-worker`, but no spec defines how that container joins or routes to tenant L2/L3 networks. Proxmox console proxying does not prove SSH reachability to guest IPs. Fix by choosing one concrete topology: per-network bastion, per-network namespace attachment, routed management NICs, provider-side guest-agent tunnel, or explicit "SSH only on reachable offerings."
- [docs/prd/23-ssh-plugin.md:Credential Model] The per-user-key path is listed as a v1 auth path, but private-key delivery to the proxy is deferred to v1.1. Public-key upload alone cannot authenticate a server-side `asyncssh` client. Fix by either removing per-user-key from v1 or designing browser agent forwarding / WebAuthn-backed signing / short-lived SSH CA certs now.

## P1 — Needs Fix Before Implementation Begins

- [docs/prd/01-executive-summary.md; docs/prd/04-full-target-requirements.md; docs/prd/11-quotas-scheduling-placement.md; docs/prd/16-container-operations.md; docs/superpowers/specs/2026-05-24-podman-orchestration.md] Deployment authority conflicts. PRD still says Docker/Podman Compose is first-class/primary for scaling, while the newer orchestration spec makes Quadlet authoritative for Baseline and Nomad authoritative for Scale. Fix the PRD and research notes so Compose is "dev/example" or explicitly supported.
- [docs/prd/17-engineering-quality-typing-ci.md:Baseline Stack] Late architectural dependencies are missing: Django Channels and Pydantic are required by the architecture/SSH/Proxmox specs but absent from the baseline stack. Also spell `mypy --strict` if that is a hard gate. Django 5.2 LTS itself remains valid; official notes list it as LTS and support Python 3.10-3.14 as of 5.2.8: <https://docs.djangoproject.com/en/6.0/releases/5.2/>
- [docs/prd/13-plugin-system.md:Discovery And Contracts; docs/prd/22-docs-plugin.md:Plugin Contract Compliance; docs/prd/23-ssh-plugin.md:Plugin Contract Compliance] Plugin-shipped migrations need a lifecycle. Django migrations run for installed apps in `INSTALLED_APPS`; dynamic enablement with models implies settings changes, migration graph loading, deployment restart/drain, rollback, and dependency ordering. Django's migration/admin docs assume Django "knows about" installed apps: <https://docs.djangoproject.com/en/6.0/ref/django-admin/>
- [docs/prd/23-ssh-plugin.md:Back-End / Credential Model] SSH host-key verification is absent. `asyncssh` supports known-host validation and disabling it is explicit; RackLab needs TOFU/pinning, cloud-init host-key capture, SSHFP/DNSSEC, or signed host certs. Source: <https://asyncssh.readthedocs.io/en/stable/>
- [docs/prd/15-ui-ux.md:Internationalization] "ICU-style pluralization" is not provided by Django gettext as written. Django uses gettext/ngettext plural forms in `.po`, not full ICU MessageFormat. Fix by either saying gettext plural forms or adding an ICU/Fluent/messageformat layer. Django plural docs: <https://docs.djangoproject.com/en/4.2/topics/i18n/translation/>
- [docs/prd/15-ui-ux.md:Recommended Frontend Libraries] `blueimp jQuery File Upload` should not be blessed. The GitHub repo was archived on May 25, 2023 and is read-only; upload handling is a high-risk surface. Replace with a maintained uploader or native browser upload + server-side chunk/resume support. Source: <https://github.com/blueimp/jQuery-File-Upload>
- [docs/superpowers/specs/2026-05-24-podman-orchestration.md:NATS-driven autoscaling] Warm-up "via PromQL on `started_at` from `WorkerRuntime.list_replicas`" is not implementable unless RackLab exports that warmed-replica count as a Prometheus metric. Nomad Autoscaler Prometheus checks query the APM and expect a metric result. Source: <https://developer.hashicorp.com/nomad/tools/autoscaling/policy>
- [docs/superpowers/specs/2026-05-24-podman-orchestration.md:Poison-job protection] It says every NATS message maps to a `provider_task` row, but the system has script, console, notification, scheduler, and docs jobs too. Fix by making `Job` the universal queue ledger and `ProviderTask` a provider-specific child.
- [docs/prd/19-data-model.md; docs/prd/14-audit-logging-observability.md; docs/prd/22-docs-plugin.md; docs/prd/23-ssh-plugin.md] Artifact storage is used everywhere, but the data model has only `ScriptArtifact` and `LogArtifact`. Add a generic `Artifact` / `ArtifactRef` model with owner scope, retention, content type, sha256, storage backend key, legal/privacy flags, and RBAC.
- [docs/prd/23-ssh-plugin.md:Session Recording] Recording asciinema streams captures typed secrets. "Consent prompt" is not enough for password prompts, API tokens, or sudo workflows. Add redaction limits, per-catalog recording policy, opt-out rules, and default-off for password-passthrough sessions.

## P2 — Nice To Fix

- [docs/superpowers/specs/2026-05-24-server-side-tls-acme.md:Hot-reload vs restart boundary] `router.tls.certFile` is not a Traefik router option. User-provided certs are loaded under dynamic `tls.certificates` / TLS stores; routers select TLS/cert resolvers/options. Traefik TLS docs: <https://doc.traefik.io/traefik/v3.0/https/tls/>
- [docs/prd/15-ui-ux.md:Recommended Frontend Libraries; docs/prd/18-security.md:Markdown And UI] "marked + DOMPurify secure-by-default" needs sharper wording. Marked explicitly does not sanitize output; require server-side sanitization/cache, DOMPurify at render, CSP/Trusted Types where possible. Source: <https://marked.js.org/>
- [docs/prd/07-api-openapi-sse.md:SSE; docs/prd/research/07-api-openapi-sse-research.md:Design Impact] Research calls out `Last-Event-ID` replay; PRD omits it. Add replay semantics for deployment timelines and logs so reconnects do not silently skip events.
- [docs/prd/22-docs-plugin.md:Cross-link audit] Auditing every polling-based reference resolution will create high-volume audit noise. Make ref-resolution telemetry sampled/aggregated, and audit only denied access, unusual access, or author-visible link creation.
- [docs/prd/22-docs-plugin.md:Editor] TipTap + Markdown is now more viable than older research implied, but the PRD should explicitly depend on TipTap's Markdown extension/manager and define how custom `racklabRef` serializes. Source: <https://tiptap.dev/docs/editor/markdown>
- [docs/prd/06-auth-rbac-sharing-tokens.md:Authentication] `django-allauth` for SAML is plausible, but enterprise SAML needs a separate operational checklist: metadata rotation, signing/encryption certs, clock skew, logout behavior, and group claim mapping.

## P3 — Notes For The Record

- [docs/superpowers/specs/2026-05-24-proxmox-client-discipline.md] The `proxmoxer` facade choice is still defensible. Keep the pin explicit and keep the escape hatch; PyPI currently shows `proxmoxer` 2.3.0 with Python 3.14 classifiers: <https://pypi.org/project/proxmoxer/>
- [docs/superpowers/specs/2026-05-24-server-side-tls-acme.md] The Traefik multi-instance ACME warning is correct. Traefik documents that ACME storage cannot be shared across instances for concurrency reasons: <https://doc.traefik.io/traefik/v3.0/https/acme/>
- [.github/workflows/docs-ci.yml] Docs CI is fine for markdown/link hygiene, but it does not render Mermaid or validate internal design consistency. That is acceptable for now, but not a substitute for architecture tests once code exists.

## Cross-Cutting Concerns

- Late decisions are captured in specs but not normalized back into PRD/research. Compose vs Quadlet/Nomad and Channels/Pydantic are the clearest examples.
- "Plugin failure isolation" is stated as a goal, but Django app import, migrations, URL registration, static assets, permissions, and settings schema failures need separate isolation rules.
- Security-sensitive browser surfaces recur across docs, SSH, markdown, SSE, and file uploads; they need one shared policy for CSP, sanitization, artifact RBAC, and audit volume.
- Queue/job terminology is split between `Job`, `ProviderTask`, NATS messages, and audit rows. Implementation needs one canonical state machine.

## Things That Aged In The Last 12 Months I Want Flagged

- Proxmox VE 9.0 shipped on Debian 13 with a newer kernel; provider capability tests should cover PVE 8.x and 9.x, especially SDN/networking behavior. Source: <https://proxmox.com/en/news/press-releases/proxmox-virtual-environment-9-0>
- Nomad is now under IBM-era packaging/support changes, including Nomad 2.x versioning notes in official docs. The BSL acceptance should be re-reviewed before production. Source: <https://docs.hashicorp.com/nomad/docs/ce-license-support>
- TipTap's Markdown support has matured; RackLab should use the official Markdown path rather than custom ad hoc Markdown/HTML round-tripping. Source: <https://tiptap.dev/docs/editor/markdown>
- Traefik OCSP stapling is explicitly static config in current docs, matching the spec's restart boundary. Source: <https://doc.traefik.io/traefik/v3.5/reference/install-configuration/tls/ocsp/>

## Open Questions For The Next Design Session

- What exact network topology makes SSH to isolated VMs possible?
- Are plugin model migrations install-time only, or can plugins be enabled after deployment with a controlled restart/migrate flow?
- Is the universal job ledger `Job`, with `ProviderTask` as a child, or is another model intended?
- Which maintained upload library replaces blueimp?
- Should v1 implement RackLab-as-SSH-CA instead of storing deployment private keys?

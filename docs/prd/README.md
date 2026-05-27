# RackLab PRD

RackLab is a full target product specification for a Laravel-based replacement for RLES. It is designed as a self-service lab platform for students, instructors, and administrators, with Proxmox as the first provider backend and a plugin system for future backends.

This PRD is split into focused files so each section can be reviewed independently.

## Sections

1. [Executive Summary](01-executive-summary.md)
2. [Goals And Non-Goals](02-goals-non-goals.md)
3. [Users And Personas](03-users-personas.md)
4. [Full Target Requirements](04-full-target-requirements.md)
5. [Architecture](05-architecture.md)
6. [Auth, RBAC, Sharing, And Tokens](06-auth-rbac-sharing-tokens.md)
7. [Public API, OpenAPI, And Real-Time Push](07-api-openapi-sse.md)
8. [Catalog, Stacks, And Deployments](08-catalog-stacks-deployments.md)
9. [Neutron-Inspired Networking](09-networking.md)
10. [Scripting, Automation, And Sandboxing](10-scripting-automation-sandboxing.md)
11. [Quotas, Scheduling, And Placement](11-quotas-scheduling-placement.md)
12. [Proxmox Provider](12-proxmox-provider.md)
13. [Plugin System](13-plugin-system.md)
14. [Audit, Logging, And Observability](14-audit-logging-observability.md)
15. [UI And UX](15-ui-ux.md)
16. [Container Operations](16-container-operations.md)
17. [Engineering Quality, Typing, And CI](17-engineering-quality-typing-ci.md)
18. [Security](18-security.md)
19. [Data Model Outline](19-data-model.md)
20. [Open Questions And Risks](20-open-questions-risks.md)
21. [Sources](21-sources.md)
22. [Docs Plugin](22-docs-plugin.md)
23. [SSH Plugin](23-ssh-plugin.md)

## Scope

This PRD describes the full target product, not an MVP. Implementation planning can later phase the work, but the requirements here describe the intended end state.

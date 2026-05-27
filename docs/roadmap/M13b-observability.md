# M13b — Observability + Alerting

**Status:** Not started.
**Estimated effort:** 1–2 weeks.
**Depends on:** M12, M13a (alerts on the HA tier require the HA tier to exist).
**Unblocks:** M13c (operational backups are observable via these metrics + alerts).

## Goal

Every signal in PRD §14 is scrapeable from Prometheus. In-product observability uses **Pulse** (v1.7.3) for real-time metrics dashboards, **spatie/laravel-health** (v1.39.3) for health-check endpoints, and **sentry/sentry-laravel** (v4.25.1) for error tracking and performance monitoring. Tracing covers the deployment lifecycle end-to-end via OpenTelemetry. Grafana dashboards for system health / providers / workers / deployments / audit volume / TLS / plugins ship as JSON in the repo. Alerting rules cover the load-bearing SLOs. An optional OpenTelemetry exporter (deferred from M13b scope if the operator does not enable it, but the exporter implementation lands here when enabled) ships as a configurable Quadlet.

## In scope

- PRD §14 Observability section.
- Prometheus exporters for every signal listed in PRD §14.
- OpenTelemetry tracing end-to-end with correlation-ID propagation across HTTP → Laravel controller → service-layer → Horizon dispatch → worker pickup → provider call → response.
- Grafana dashboards shipped as JSON.
- Alert rules.

## Dependencies

- M12 — `prometheus-redis-exporter` + Horizon-status exporter + Prometheus + Nomad Autoscaler infrastructure already in place.
- M13a — HA tier exists; alerts on it are meaningful.

## Deliverables

- **Pulse** integration: in-product metrics dashboard mounted at `/pulse` (admin-only via Filament or standalone Pulse dashboard); covers queue throughput, cache hit rate, job failure rates, and slow requests. Custom `PulseRecorder` entries for deployment latency, provider health, and quota pressure.
- **spatie/laravel-health** integration: `/healthz` (liveness) and `/readyz` (readiness) health endpoints consumed by load balancers and Nomad health checks (replacing `/up` from M2.5 with the split semantics introduced in M2.5); registered checks cover Postgres connectivity, Redis connectivity, Reverb reachability, Horizon supervisor status, disk space, CPU load, and artifact storage availability.
- **sentry/sentry-laravel** integration: error tracking with tenant context tag, performance monitoring (transaction sampling configurable via `racklab.toml`), breadcrumbs for Horizon job dispatch + provider API calls.
- Prometheus metric coverage per PRD §14: request latency, error rates, queue depth, worker health, worker concurrency, provider health, deployment latency, deployment failure rates, script failure rates, quota pressure, Redis health, PostgreSQL health, artifact storage health. RackLab's `web` tier exposes a `/metrics` endpoint with all the application metrics; the infrastructure tier (Postgres, Redis, Patroni) uses standard exporters.
- OpenTelemetry SDK wired across web + workers + the Proxmox client; spans cover HTTP request → service-layer calls → Horizon dispatch (with span context propagated in the job payload) → worker pickup → provider call.
- Correlation IDs propagate per PRD §14 across HTTP request, DB job, Horizon job, worker execution, provider API task, and UI-visible event.
- Grafana dashboards (shipped as JSON in `deploy/grafana/dashboards/`):
  - System health overview.
  - Provider health (per provider).
  - Worker pool detail (per pool).
  - Deployment lifecycle (latency p50/p95/p99, failure rates, state distribution).
  - Audit volume + per-actor / per-event-kind breakdown.
  - TLS cert status.
  - Plugin health + enable/disable activity.
- Alert rules (shipped as Prometheus alert YAML):
  - SLO violations: deployment-latency p99 > threshold for 5m.
  - Provider down (any provider plugin's health check reports unhealthy for >2m).
  - Cert-near-expiry-without-renewal (any cert <14d to expiry with no successful renewal).
  - Queue-depth-sustained (worker pool's pending count above its `max_replicas`-derived ceiling for >5m).
  - Poison-job-detected (Horizon `failed` queue depth exceeds threshold and per-job `attempts` count on the `Job` row exceeds `poison_threshold` for a single job).
  - Worker-pool-unhealthy (any worker pool's replica count below `min_replicas` for >5m).
  - HA Postgres failover started / completed.
  - Redis node lost / recovered.

## Acceptance criteria

- [ ] Every PRD §14 signal is scrapeable and surfaces in the shipped Grafana dashboards.
- [ ] An OpenTelemetry trace of a single deployment request from browser click to deployment-row update is observable end-to-end with correlation IDs intact.
- [ ] The shipped alert rules fire correctly under deliberate fault injection (kill provider plugin → provider-down fires within 2m; queue-depth spike → queue-depth-sustained fires within 5m).
- [ ] The deployment-lifecycle Grafana dashboard answers "is the system healthy right now?" at a glance for an on-call admin.
- [ ] The Grafana dashboards lint cleanly via `grafonnet` or a similar dashboard-as-code tool — they're maintained alongside the code.

## Test layers

- **Tiny / unit**: metric-emitter helpers (label sanitization, value bounds); correlation-ID propagation through middleware.
- **Contract**: the OTel SDK integration at the Laravel middleware boundary + the Horizon-dispatch span propagation; alert-rule unit tests using `promtool`'s test syntax.
- **Integration**: end-to-end trace verification against testcontainers + Jaeger; alert firing under fault injection.
- **E2E**: a deployment from the browser produces a trace that includes every span across web + worker + provider; the dashboards render with real data.

## Risks / open questions

- **Cardinality explosion**: alert rules with too many labels create costly time series. Document the cardinality policy + the labels Prometheus can drop at relabel time.
- **OpenTelemetry collector**: ship one as a Quadlet or assume the operator provides one? Recommend: ship as a Quadlet by default; document the assume-operator-provides path.
- **Grafana dashboard maintenance**: dashboards rot. The "ship the dashboard with the code that emits the metric" discipline from PRD §17 needs CI enforcement — link metric-emitter functions to dashboard files in the dashboard JSON's metadata; CI flags emitter changes without dashboard updates.

## Out of scope (deferred)

- Logging aggregation (Loki, ELK) — out of scope. RackLab emits structured JSON logs per PRD §14; operators ship them with their own log-aggregation tool.
- APM beyond Prometheus + OpenTelemetry — out of scope.
- Plugin-supplied alert rules — v1 ships core alert rules only; plugin authors can contribute via their own packages post-v1.
- Cost-monitoring dashboards — explicit PRD non-goal.

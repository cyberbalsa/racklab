# Audit, Logging, And Observability Research

## Security Logging

OWASP recommends application logging for security and operational use cases, including authentication, authorization failures, input validation, session failures, policy violations, and audit trails. It also stresses excluding sensitive data and protecting logs from tampering or unauthorized access.

Sources:

- OWASP Logging Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html
- OWASP Logging Vocabulary Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Vocabulary_Cheat_Sheet.html
- OWASP logging and monitoring checklist: https://devguide.owasp.org/en/04-design/02-web-app-checklist/09-logging-monitoring/

## Trace/Log Correlation

OpenTelemetry defines vendor-neutral telemetry for traces, metrics, and logs. Its logging model includes trace and span correlation, which supports RackLab's requirement for correlation ids across HTTP requests, NATS messages, workers, provider tasks, and SSE events.

Sources:

- OpenTelemetry docs: https://opentelemetry.io/docs/
- OpenTelemetry logs: https://opentelemetry.io/docs/specs/otel/logs/

## Provider Audit

Proxmox operations are asynchronous and permission-scoped. RackLab should persist provider task identifiers, target node, VMID, action, result, and elapsed time rather than relying on Proxmox logs.

Source:

- Proxmox user management and API permissions: https://pve.proxmox.com/pve-docs/chapter-pveum.html

## Design Impact

- Audit events should be structured data in PostgreSQL.
- Verbose artifacts should live outside main transactional rows but be indexed by metadata.
- Every job and event needs correlation id propagation.
- Secret redaction should be tested.
- Admin audit search/export is a product feature, not just a logging sink.

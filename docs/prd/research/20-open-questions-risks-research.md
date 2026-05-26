# Open Questions And Risks Research

## Network Risk

Neutron's distinction between provider and self-service networks supports the PRD's concern that direct provider network attachment is higher risk than private/NAT networks. Proxmox SDN can support separated networks, but real site safety depends on how bridges, VLANs, VNets, and external networks are configured.

Sources:

- Neutron overview: https://docs.openstack.org/neutron/latest/admin/intro-os-networking.html
- Proxmox SDN: https://pve.proxmox.com/pve-docs/chapter-pvesdn.html

## Script Risk

openQA-style console scripts are safer to validate than arbitrary code, but advanced scripts still create host and data risk. nsjail and bubblewrap help, yet the security boundary is only as good as the profile, kernel settings, container runtime policy, and worker isolation.

Sources:

- openQA test API: https://open.qa/api/testapi
- nsjail: https://github.com/google/nsjail
- bubblewrap: https://github.com/containers/bubblewrap

## Token Risk

JWTs are compact and useful but revocation, key rotation, audience validation, and delegated permission limits are critical. RFC 8725 should guide default token validation policy.

Sources:

- RFC 7519 JWT: https://datatracker.ietf.org/doc/rfc7519/
- RFC 8725 JWT BCP: https://www.ietf.org/rfc/rfc8725.html

## State Consistency Risk

NATS JetStream supports durable queues and acknowledgments, but at-least-once behavior means workers must be idempotent and database state must remain authoritative. This is the main reason for persisted job records, idempotency keys, and reconciliation workers.

Sources:

- NATS JetStream: https://docs.nats.io/nats-concepts/jetstream
- NATS consumers: https://docs.nats.io/nats-concepts/jetstream/consumers

## Audit Risk

OWASP warns that logs need enough context to support security and operational use cases, but logs also need protection from tampering and sensitive-data exposure. RackLab's audit design must include retention, redaction, and access controls from the start.

Source:

- OWASP Logging Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html

## Design Impact

- Open questions should remain visible until implementation planning resolves them.
- Risk-heavy areas need prototype validation: Proxmox SDN mapping, console automation, sandbox profiles, token grants, and quota reservations.
- The first implementation plan should include test harnesses for provider fake, network fake, NATS fake, and script sandbox validation.

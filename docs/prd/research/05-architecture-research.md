# Architecture Research

## Django-Centered Control Plane

Django is a strong fit for RackLab because the platform is policy-heavy and data-heavy: users, roles, projects, catalog items, deployments, quotas, networks, jobs, scripts, approvals, tokens, and audit records. Django's auth and permission framework can be extended, and Django apps provide a natural module boundary.

Sources:

- Django auth customization: https://docs.djangoproject.com/en/5.2/topics/auth/customizing/
- Django auth reference: https://docs.djangoproject.com/en/5.2/ref/contrib/auth/

## PostgreSQL As System Of Record

Django's ORM and migrations pair well with PostgreSQL for the control plane. RackLab should use PostgreSQL for authoritative state, constraints, audit rows, token grants, quota reservations, and workflow state machines. NATS should not be the source of truth.

Source:

- Django databases: https://docs.djangoproject.com/en/5.2/ref/databases/

## NATS JetStream Work Backbone

JetStream adds persistence, replay, acknowledgments, retention policies, pull consumers, and clustering. The docs explicitly support shared pull consumers for horizontal scalability, which maps to multiple provider workers or script workers consuming a durable queue.

Sources:

- JetStream concepts: https://docs.nats.io/nats-concepts/jetstream
- JetStream consumers: https://docs.nats.io/nats-concepts/jetstream/consumers
- JetStream work queue retention: https://docs.nats.io/nats-concepts/jetstream/streams

## Worker Segmentation

Separating worker pools follows least privilege and scaling needs:

- Provider workers need provider credentials.
- Script workers need sandbox tooling and should not have provider credentials.
- Console workers need console broker access.
- Reconciler workers need provider read access and state-machine authority.
- Notification workers need mail/webhook credentials.

## Design Impact

- Every async job should have a database job record before publishing a NATS message.
- Every worker message should carry a correlation id and idempotency key.
- Workers should acknowledge NATS messages only after durable state has been updated.
- Script workers should be deployable on separate hosts with stricter runtime policy.

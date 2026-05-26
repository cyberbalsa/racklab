# Container Operations Research

## Docker Compose

Docker Compose models applications as services, each backed by containers. Compose service definitions include health checks, secrets, configs, resource settings, volumes, networks, and scale/replica-related attributes. This fits RackLab's service split across web and worker types.

Sources:

- Docker Compose services: https://docs.docker.com/reference/compose-file/services/
- Docker Compose deploy specification: https://docs.docker.com/reference/compose-file/deploy/

## Podman Compose

Podman provides `podman compose` as a wrapper around external Compose providers such as Docker Compose or podman-compose. This supports RackLab's requirement to keep Compose files compatible with both Docker and Podman where practical.

Source:

- Podman Compose: https://docs.podman.io/en/stable/markdown/podman-compose.1.html

## Podman Quadlet

Podman Quadlet can declaratively manage containers, pods, volumes, networks, and images using systemd unit-style files. This is a good later operational option for sites that prefer systemd-managed Podman services over Compose.

Sources:

- Podman Quadlet: https://docs.podman.io/en/latest/markdown/podman-quadlet.1.html
- Podman systemd unit docs: https://docs.podman.io/en/latest/markdown/podman-systemd.unit.5.html

## NATS In Containers

NATS docs show JetStream can be enabled in Docker with `-js` and persisted with a mounted volume and store directory. RackLab Compose profiles should persist JetStream data explicitly.

Source:

- NATS JetStream Docker: https://docs.nats.io/running-a-nats-service/nats_docker/jetstream_docker

## PostgreSQL In Containers

The PostgreSQL official image supports initialization scripts and environment-based setup. RackLab should document production caveats, backups, volumes, and upgrades rather than treating the database container as disposable.

Source:

- Docker PostgreSQL guide: https://docs.docker.com/guides/postgresql/advanced-configuration-and-initialization/

## Design Impact

- One image can provide multiple entrypoints, but services should be split by role.
- Compose examples should avoid fixed `container_name` for scalable worker services.
- NATS and PostgreSQL volumes must be persistent.
- Script workers may need special container security settings and host placement.
- Podman Quadlet can be documented as an alternate production deployment pattern.

# Public API, OpenAPI, And SSE Research

## REST API And Permissions

Django REST Framework runs permission checks before view code and supports object-level permission checks through `check_object_permissions`. RackLab APIs should centralize permission checks so UI and API behavior stay aligned.

Sources:

- DRF permissions: https://www.django-rest-framework.org/api-guide/permissions/
- DRF throttling: https://www.django-rest-framework.org/api-guide/throttling/

## OpenAPI

DRF's documentation guidance points to third-party OpenAPI packages. drf-spectacular is designed for OpenAPI 3 schema generation, Swagger UI, ReDoc, and client generation. This fits the requirement that API tokens and automation workflows be documented.

Sources:

- DRF API documentation: https://www.django-rest-framework.org/topics/documenting-your-api/
- drf-spectacular: https://drf-spectacular.readthedocs.io/

## JWT Tokens

JWT is standardized in RFC 7519 and defines registered claims such as issuer, subject, audience, expiration, not-before, issued-at, and JWT id. RFC 8725 adds best-current-practice guidance. RackLab should use signed JWTs but keep server-side token grant metadata for revocation, scoping, audit, and permission narrowing.

Sources:

- RFC 7519 JWT: https://datatracker.ietf.org/doc/rfc7519/
- RFC 8725 JWT BCP: https://www.ietf.org/rfc/rfc8725.html
- Simple JWT blacklist docs: https://django-rest-framework-simplejwt.readthedocs.io/en/stable/blacklist_app.html

## SSE

SSE uses the browser `EventSource` API and the `text/event-stream` MIME type. It is one-way from server to client and includes event ids that can support reconnect behavior. That fits RackLab live status updates, where the browser does not need a bidirectional socket for most progress views.

Sources:

- MDN SSE guide: https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events
- WHATWG server-sent events: https://html.spec.whatwg.org/dev/server-sent-events.html
- django-eventstream: https://github.com/fanout/django-eventstream

## Design Impact

- API and AJAX/web views should share service functions.
- Token claims should be small, scoped, and backed by server-side grant records.
- SSE streams must be permission-filtered and backed by persisted events.
- RackLab should support `Last-Event-ID` style replay for deployment event timelines where practical.

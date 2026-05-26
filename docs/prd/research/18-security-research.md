# Security Research

## Django Security

Django provides protections and guidance for XSS, CSRF, SQL injection, clickjacking, host header validation, HTTPS settings, and secure cookies. RackLab's browser UI should use Django's built-in protections and keep permission checks on every AJAX fragment endpoint and SSE stream.

Source:

- Django security: https://docs.djangoproject.com/en/5.2/topics/security/

## JWT Security

JWT should follow RFC 7519 and RFC 8725 best practices. RackLab should validate issuer, audience, expiry, not-before, issued-at, and token id, and should avoid accepting weak algorithms or ambiguous token types.

Sources:

- RFC 7519 JWT: https://datatracker.ietf.org/doc/rfc7519/
- RFC 8725 JWT BCP: https://www.ietf.org/rfc/rfc8725.html

## Markdown Sanitization

RackLab displays user/instructor markdown near consoles. Python-Markdown can parse markdown, while Bleach provides allowed-list-based HTML sanitization. Sanitization is required because console instructions and lab notes may be user-authored.

Sources:

- Python-Markdown extensions: https://python-markdown.github.io/extensions/
- Bleach: https://bleach.readthedocs.io/

## Script Security

Student-authored scripts require OS-level isolation, RBAC, approval, resource limits, and audit. nsjail and bubblewrap provide Linux sandbox building blocks, but RackLab must own hardened profiles and test them.

Sources:

- nsjail: https://github.com/google/nsjail
- bubblewrap: https://github.com/containers/bubblewrap

## Logging Security

OWASP warns that logs can contain sensitive data and must be protected from tampering, unauthorized access, and over-collection. RackLab must redact secrets and protect artifact storage.

Source:

- OWASP Logging Cheat Sheet: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html

## Design Impact

- Every external action should pass through RBAC, quota, policy, and audit.
- User markdown must be sanitized after rendering or rendered through a safe pipeline.
- Token grants must be revocable and scoped.
- Console tokens should be short-lived and single-purpose.
- Script worker containers should not receive provider credentials.

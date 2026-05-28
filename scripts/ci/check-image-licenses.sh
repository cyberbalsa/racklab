#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
  printf 'Usage: %s <syft-sbom.json> [allowlist.json]\n' "$0" >&2
  exit 2
fi

sbom="$1"
allowlist="${2:-.github/license-policy.allowlist.json}"

if [ ! -f "$sbom" ]; then
  printf 'SBOM file not found: %s\n' "$sbom" >&2
  exit 2
fi

if [ ! -f "$allowlist" ]; then
  printf 'License allowlist file not found: %s\n' "$allowlist" >&2
  exit 2
fi

jq -e '
  type == "array"
  and all(.[]; (
    (.artifact | type == "string")
    and (.license | type == "string")
    and ((.reason // "") | type == "string")
    and ((.reason // "") | length >= 20)
  ))
' "$allowlist" >/dev/null

forbidden="$(
  jq -r --slurpfile allowlist "$allowlist" '
    def license_text:
      if type == "object" then
        (.value // .spdxExpression // .name // "")
      else
        .
      end;

    def forbidden_license:
      ascii_upcase as $upper
      | ($upper | test("(^|[^L])GPL-3\\.0"))
        or ($upper | contains("AGPL-3.0"))
        or ($upper | contains("BUSL-1.1"))
        or ($upper | contains("BSL-1.1"));

    def allowlisted($artifact; $license):
      any($allowlist[0][]?;
        .artifact == $artifact
        and ((.license | ascii_upcase) == ($license | ascii_upcase))
      );

    .artifacts[]? as $artifact
    | ($artifact.name // "") as $artifact_name
    | $artifact.licenses[]?
    | license_text
    | select(type == "string" and length > 0) as $license
    | select($license | forbidden_license)
    | select(allowlisted($artifact_name; $license) | not)
    | "\($artifact_name)\t\($license)"
  ' "$sbom"
)"

if [ -n "$forbidden" ]; then
  printf 'Forbidden runtime image license(s):\n%s\n' "$forbidden" >&2
  exit 1
fi

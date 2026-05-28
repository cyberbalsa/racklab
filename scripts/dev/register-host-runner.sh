#!/usr/bin/env bash
# Register the workstation host as a labelled self-hosted GHA runner
# for RackLab. Token MUST come from stdin or --token-file (NOT a flag --
# flags leak into shell history / process listings).
set -euo pipefail

REPO_URL="https://github.com/cyberbalsa/racklab"
RUNNER_VERSION="2.319.1"
RUNNER_RELEASE_BASE="https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}"
RUNNER_HOME="${RACKLAB_RUNNER_HOME:-$HOME/.racklab/actions-runner}"
LABELS="self-hosted,linux,podman,cgroup-delegated"

usage() {
  cat <<EOF
Usage: $0 [--token-file=PATH] [--reconfigure] [--noop]

Registers this host as a self-hosted GitHub Actions runner for $REPO_URL
with labels: $LABELS

The registration token must come from stdin (default) or --token-file.
Passing --token= on the command line is NOT supported -- it would leak
the token into shell history and ps output.

Options:
  --token-file=PATH    Read the registration token from PATH.
  --reconfigure        Replace an existing runner config.
  --noop               Print what would happen, do not register.
EOF
}

TOKEN_FILE=""
RECONFIGURE=0
NOOP=0

for arg in "$@"; do
  case "$arg" in
    --token-file=*) TOKEN_FILE="${arg#--token-file=}" ;;
    --reconfigure) RECONFIGURE=1 ;;
    --noop) NOOP=1 ;;
    --token=*) echo "Error: --token= leaks into shell history; use --token-file or stdin." >&2; exit 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 2 ;;
  esac
done

if [[ "$NOOP" -ne 1 ]]; then
  if [[ -n "$TOKEN_FILE" ]]; then
    TOKEN="$(cat "$TOKEN_FILE")"
  else
    read -r -s -p "Registration token (input hidden): " TOKEN
    echo
  fi
  [[ -z "$TOKEN" ]] && { echo "Empty token; refusing to proceed." >&2; exit 2; }
fi

echo "Registering self-hosted runner:"
echo "  REPO_URL=$REPO_URL"
echo "  RUNNER_HOME=$RUNNER_HOME"
echo "  LABELS=$LABELS"

if [[ "$NOOP" -eq 1 ]]; then
  echo "(noop)"
  exit 1
fi

if [[ -d "$RUNNER_HOME" && "$RECONFIGURE" -ne 1 ]]; then
  echo "Error: $RUNNER_HOME already exists. Re-run with --reconfigure to replace." >&2
  exit 3
fi

mkdir -p "$RUNNER_HOME"
cd "$RUNNER_HOME"

if [[ ! -x ./config.sh ]]; then
  ARCHIVE_BASE="actions-runner-linux-x64-${RUNNER_VERSION}"
  ARCHIVE="${ARCHIVE_BASE}.tar.gz"
  CHECKSUM="${ARCHIVE_BASE}.tar.gz.sha256"
  curl -fsSL -o "$ARCHIVE" "${RUNNER_RELEASE_BASE}/${ARCHIVE}"
  curl -fsSL -o "$CHECKSUM" "${RUNNER_RELEASE_BASE}/${CHECKSUM}"
  echo "$(cat "$CHECKSUM")  ${ARCHIVE}" | sha256sum -c -
  tar xzf "$ARCHIVE"
  rm "$ARCHIVE" "$CHECKSUM"
fi

./config.sh \
  --url "$REPO_URL" \
  --token "$TOKEN" \
  --labels "$LABELS" \
  --unattended \
  --replace

echo
echo "Runner registered. Enable systemd-user service:"
echo "  systemctl --user daemon-reload && systemctl --user enable --now racklab-self-hosted-runner.service"

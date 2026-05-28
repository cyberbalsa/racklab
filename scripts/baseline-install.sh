#!/usr/bin/env bash
set -euo pipefail

SCRIPT_VERSION="0.1.0"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
QUADLET_SOURCE_DIR="$REPO_ROOT/deploy/quadlets"

PROFILE="baseline"
DOMAIN=""
LISTEN_ADDR="0.0.0.0"
LISTEN_PORT="443"
DATA_DIR="/var/lib/racklab"
CONFIG_DIR="/etc/racklab"
UNIT_DIR="/etc/containers/systemd"
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
ADMIN_PASSWORD_STDIN="false"
ADMIN_TOKEN_FILE=""
TENANT_SLUG="default"
TENANT_NAME="Default Tenant"
DB_MODE="internal"
DB_URL=""
REDIS_MODE="internal"
REDIS_URL=""
TLS_MODE="self-signed"
TLS_CERT=""
TLS_KEY=""
IMAGE_REGISTRY="ghcr.io/cyberbalsa/racklab"
IMAGE_TAG="main"
UPGRADE="false"
UNINSTALL="false"
KEEP_DATA="false"
DRY_RUN="false"
NON_INTERACTIVE="false"
SKIP_SYSTEMD_ENABLE="false"
ACCEPT_LICENSE="false"
LOG_FORMAT="text"
LOG_FILE=""
QUIET="false"
VERBOSE="false"

usage() {
    cat <<'USAGE'
Usage: scripts/baseline-install.sh [options]

Baseline install options:
  -y, --non-interactive        Skip prompts and fail fast for missing required flags.
      --config-file=PATH       Read simple TOML/YAML-style key/value defaults.
      --domain=FQDN            Hostname used for APP_URL and bootstrap TLS SAN.
      --listen-addr=ADDR       Bind address for published ports. Default: 0.0.0.0.
      --listen-port=PORT       HTTPS host port. Default: 443.
      --data-dir=PATH          Persistent state root. Default: /var/lib/racklab.
      --unit-dir=PATH          Quadlet destination. Default: /etc/containers/systemd.
      --config-dir=PATH        RackLab config destination. Default: /etc/racklab.
      --admin-email=EMAIL      First admin email.
      --admin-password-stdin   Read first admin password from stdin.
      --admin-token-file=PATH  Copy a one-day bootstrap API token to this host path.
      --tenant-slug=SLUG       Default tenant slug. Default: default.
      --tenant-name=NAME       Default tenant name. Default: Default Tenant.
      --db-mode=internal|external
      --db-url=URL             Required when --db-mode=external.
      --redis-mode=internal|external
      --redis-url=URL          Required when --redis-mode=external.
      --tls-mode=self-signed|provided|skip
      --tls-cert=PATH          Required when --tls-mode=provided.
      --tls-key=PATH           Required when --tls-mode=provided.
      --image-registry=REG     Default: ghcr.io/cyberbalsa/racklab.
      --image-tag=TAG          Default: main.
      --profile=baseline       Only baseline is accepted by this installer.
      --upgrade                Render fresh config/units and restart the runtime target.
      --uninstall              Remove Quadlets and config; use --keep-data to preserve state.
      --keep-data              Preserve --data-dir during uninstall.
      --dry-run                Print actions without changing disk or systemd state.
      --log-format=text|json   Output shape. Default: text.
      --log-file=PATH          Tee output to a log file.
      --quiet                  Reduce progress output.
      --verbose                Print extra progress output.
      --skip-systemd-enable    Render files but skip systemctl enable/start.
      --accept-license         Required with --non-interactive.
      --version                Print installer version.
      --help                   Print this help.
USAGE
}

log() {
    local message="$1"

    if [[ "$QUIET" == "true" && "$message" != ERROR:* ]]; then
        return
    fi

    if [[ "$LOG_FORMAT" == "json" ]]; then
        printf '{"message": "%s"}\n' "$(printf '%s' "$message" | sed 's/\\/\\\\/g; s/"/\\"/g')"
    else
        printf '%s\n' "$message"
    fi
}

die() {
    log "ERROR: $1" >&2
    exit 1
}

normalize_key() {
    printf '%s' "$1" | tr '-' '_'
}

assign_config_value() {
    local key
    key="$(normalize_key "$1")"
    local value="$2"

    case "$key" in
        domain) DOMAIN="$value" ;;
        listen_addr) LISTEN_ADDR="$value" ;;
        listen_port) LISTEN_PORT="$value" ;;
        data_dir) DATA_DIR="$value" ;;
        unit_dir) UNIT_DIR="$value" ;;
        config_dir) CONFIG_DIR="$value" ;;
        admin_email) ADMIN_EMAIL="$value" ;;
        admin_token_file) ADMIN_TOKEN_FILE="$value" ;;
        tenant_slug) TENANT_SLUG="$value" ;;
        tenant_name) TENANT_NAME="$value" ;;
        db_mode) DB_MODE="$value" ;;
        db_url) DB_URL="$value" ;;
        redis_mode) REDIS_MODE="$value" ;;
        redis_url) REDIS_URL="$value" ;;
        tls_mode) TLS_MODE="$value" ;;
        tls_cert) TLS_CERT="$value" ;;
        tls_key) TLS_KEY="$value" ;;
        image_registry) IMAGE_REGISTRY="$value" ;;
        image_tag) IMAGE_TAG="$value" ;;
        profile) PROFILE="$value" ;;
        log_format) LOG_FORMAT="$value" ;;
        log_file) LOG_FILE="$value" ;;
    esac
}

load_config_file() {
    local path="$1"

    [[ -f "$path" ]] || die "Config file [$path] does not exist."

    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%%#*}"
        line="${line%%;*}"
        [[ "$line" =~ ^[[:space:]]*$ ]] && continue

        if [[ "$line" =~ ^[[:space:]]*([A-Za-z0-9_-]+)[[:space:]]*[:=][[:space:]]*(.*)[[:space:]]*$ ]]; then
            local key="${BASH_REMATCH[1]}"
            local value="${BASH_REMATCH[2]}"
            value="${value%\"}"
            value="${value#\"}"
            value="${value%\'}"
            value="${value#\'}"
            assign_config_value "$key" "$value"
        fi
    done < "$path"
}

config_file_from_args() {
    local previous=""

    for arg in "$@"; do
        if [[ "$previous" == "--config-file" ]]; then
            printf '%s' "$arg"
            return
        fi

        case "$arg" in
            --config-file=*)
                printf '%s' "${arg#*=}"
                return
                ;;
        esac

        previous="$arg"
    done
}

parse_args() {
    local config_file
    config_file="$(config_file_from_args "$@")"

    if [[ -n "$config_file" ]]; then
        load_config_file "$config_file"
    fi

    while (($#)); do
        case "$1" in
            -y|--non-interactive) NON_INTERACTIVE="true"; shift ;;
            --config-file) shift 2 ;;
            --config-file=*) shift ;;
            --domain) DOMAIN="${2:-}"; shift 2 ;;
            --domain=*) DOMAIN="${1#*=}"; shift ;;
            --listen-addr) LISTEN_ADDR="${2:-}"; shift 2 ;;
            --listen-addr=*) LISTEN_ADDR="${1#*=}"; shift ;;
            --listen-port) LISTEN_PORT="${2:-}"; shift 2 ;;
            --listen-port=*) LISTEN_PORT="${1#*=}"; shift ;;
            --data-dir) DATA_DIR="${2:-}"; shift 2 ;;
            --data-dir=*) DATA_DIR="${1#*=}"; shift ;;
            --unit-dir) UNIT_DIR="${2:-}"; shift 2 ;;
            --unit-dir=*) UNIT_DIR="${1#*=}"; shift ;;
            --config-dir) CONFIG_DIR="${2:-}"; shift 2 ;;
            --config-dir=*) CONFIG_DIR="${1#*=}"; shift ;;
            --admin-email) ADMIN_EMAIL="${2:-}"; shift 2 ;;
            --admin-email=*) ADMIN_EMAIL="${1#*=}"; shift ;;
            --admin-password-stdin) ADMIN_PASSWORD_STDIN="true"; shift ;;
            --admin-token-file) ADMIN_TOKEN_FILE="${2:-}"; shift 2 ;;
            --admin-token-file=*) ADMIN_TOKEN_FILE="${1#*=}"; shift ;;
            --tenant-slug) TENANT_SLUG="${2:-}"; shift 2 ;;
            --tenant-slug=*) TENANT_SLUG="${1#*=}"; shift ;;
            --tenant-name) TENANT_NAME="${2:-}"; shift 2 ;;
            --tenant-name=*) TENANT_NAME="${1#*=}"; shift ;;
            --db-mode) DB_MODE="${2:-}"; shift 2 ;;
            --db-mode=*) DB_MODE="${1#*=}"; shift ;;
            --db-url) DB_URL="${2:-}"; shift 2 ;;
            --db-url=*) DB_URL="${1#*=}"; shift ;;
            --redis-mode) REDIS_MODE="${2:-}"; shift 2 ;;
            --redis-mode=*) REDIS_MODE="${1#*=}"; shift ;;
            --redis-url) REDIS_URL="${2:-}"; shift 2 ;;
            --redis-url=*) REDIS_URL="${1#*=}"; shift ;;
            --tls-mode) TLS_MODE="${2:-}"; shift 2 ;;
            --tls-mode=*) TLS_MODE="${1#*=}"; shift ;;
            --tls-cert) TLS_CERT="${2:-}"; shift 2 ;;
            --tls-cert=*) TLS_CERT="${1#*=}"; shift ;;
            --tls-key) TLS_KEY="${2:-}"; shift 2 ;;
            --tls-key=*) TLS_KEY="${1#*=}"; shift ;;
            --image-registry) IMAGE_REGISTRY="${2:-}"; shift 2 ;;
            --image-registry=*) IMAGE_REGISTRY="${1#*=}"; shift ;;
            --image-tag) IMAGE_TAG="${2:-}"; shift 2 ;;
            --image-tag=*) IMAGE_TAG="${1#*=}"; shift ;;
            --profile) PROFILE="${2:-}"; shift 2 ;;
            --profile=*) PROFILE="${1#*=}"; shift ;;
            --upgrade) UPGRADE="true"; shift ;;
            --uninstall) UNINSTALL="true"; shift ;;
            --keep-data) KEEP_DATA="true"; shift ;;
            --dry-run) DRY_RUN="true"; shift ;;
            --log-format) LOG_FORMAT="${2:-}"; shift 2 ;;
            --log-format=*) LOG_FORMAT="${1#*=}"; shift ;;
            --log-file) LOG_FILE="${2:-}"; shift 2 ;;
            --log-file=*) LOG_FILE="${1#*=}"; shift ;;
            --quiet) QUIET="true"; shift ;;
            --verbose) VERBOSE="true"; shift ;;
            --skip-systemd-enable) SKIP_SYSTEMD_ENABLE="true"; shift ;;
            --accept-license) ACCEPT_LICENSE="true"; shift ;;
            --version) printf 'racklab-baseline-install %s\n' "$SCRIPT_VERSION"; exit 0 ;;
            --help) usage; exit 0 ;;
            *) die "Unknown option [$1]. Run --help for usage." ;;
        esac
    done
}

existing_env_value() {
    local key="$1"
    local file="$CONFIG_DIR/racklab.env"

    if [[ -f "$file" ]]; then
        sed -n "s/^${key}=//p" "$file" | tail -n 1
    fi
}

random_base64() {
    openssl rand -base64 "${1:-32}" | tr -d '\n'
}

app_url() {
    if [[ "$LISTEN_PORT" == "443" ]]; then
        printf 'https://%s' "$DOMAIN"
    else
        printf 'https://%s:%s' "$DOMAIN" "$LISTEN_PORT"
    fi
}

sed_escape() {
    printf '%s' "$1" | sed 's/[\/&]/\\&/g'
}

toml_escape() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

write_file() {
    local path="$1"

    if [[ "$DRY_RUN" == "true" ]]; then
        cat >/dev/null
        log "DRY RUN: write $path"
        return
    fi

    mkdir -p "$(dirname "$path")"
    umask 077
    cat > "$path"
}

run() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: run $*"
        return
    fi

    "$@"
}

validate() {
    [[ "$PROFILE" == "baseline" ]] || die "Only --profile=baseline is supported by this installer."
    [[ "$LOG_FORMAT" == "text" || "$LOG_FORMAT" == "json" ]] || die "--log-format must be text or json."
    [[ "$DB_MODE" == "internal" || "$DB_MODE" == "external" ]] || die "--db-mode must be internal or external."
    [[ "$REDIS_MODE" == "internal" || "$REDIS_MODE" == "external" ]] || die "--redis-mode must be internal or external."
    [[ "$TLS_MODE" == "self-signed" || "$TLS_MODE" == "provided" || "$TLS_MODE" == "skip" ]] || die "--tls-mode must be self-signed, provided, or skip."

    local missing=()

    if [[ "$UNINSTALL" != "true" ]]; then
        [[ -n "$DOMAIN" ]] || missing+=("--domain")
        [[ "$DB_MODE" != "external" || -n "$DB_URL" ]] || missing+=("--db-url")
        [[ "$REDIS_MODE" != "external" || -n "$REDIS_URL" ]] || missing+=("--redis-url")
        [[ "$TLS_MODE" != "provided" || -n "$TLS_CERT" ]] || missing+=("--tls-cert")
        [[ "$TLS_MODE" != "provided" || -n "$TLS_KEY" ]] || missing+=("--tls-key")

        if [[ "$NON_INTERACTIVE" == "true" && "$DRY_RUN" != "true" ]]; then
            [[ -n "$ADMIN_EMAIL" ]] || missing+=("--admin-email")
            [[ "$ADMIN_PASSWORD_STDIN" == "true" ]] || missing+=("--admin-password-stdin")
        fi
    fi

    if [[ "$NON_INTERACTIVE" == "true" && "$ACCEPT_LICENSE" != "true" ]]; then
        missing+=("--accept-license")
    fi

    if ((${#missing[@]})); then
        die "Missing required flag(s): ${missing[*]}"
    fi
}

preflight() {
    if [[ "$DRY_RUN" == "true" || "$UNINSTALL" == "true" || "$SKIP_SYSTEMD_ENABLE" == "true" ]]; then
        return
    fi

    command -v podman >/dev/null 2>&1 || die "podman is required for Baseline installs."
    command -v systemctl >/dev/null 2>&1 || die "systemctl is required for Baseline installs."
    command -v openssl >/dev/null 2>&1 || die "openssl is required for bootstrap TLS."
    [[ -d /run/systemd/system ]] || die "systemd is not running on this host."

    local cgroups
    cgroups="$(podman info --format '{{.Host.CgroupsVersion}}' 2>/dev/null || true)"
    [[ "$cgroups" == "v2" ]] || die "Podman Quadlets require cgroup v2; podman info reported [$cgroups]."
}

read_admin_password() {
    if [[ "$ADMIN_PASSWORD_STDIN" != "true" || "$DRY_RUN" == "true" || "$UNINSTALL" == "true" ]]; then
        return
    fi

    ADMIN_PASSWORD="$(cat)"
    [[ -n "${ADMIN_PASSWORD:-}" ]] || die "No password was provided on stdin."
}

prompt_interactive() {
    if [[ "$NON_INTERACTIVE" == "true" || "$UNINSTALL" == "true" || ! -t 0 ]]; then
        return
    fi

    if [[ "$ACCEPT_LICENSE" != "true" ]]; then
        local accepted=""
        read -r -p "RackLab is Apache-2.0 and enforces the documented plugin license policy. Type yes to continue: " accepted
        [[ "$accepted" == "yes" ]] || die "License acknowledgement is required."
        ACCEPT_LICENSE="true"
    fi

    if [[ -z "$DOMAIN" ]]; then
        read -r -p "RackLab domain [racklab.local]: " DOMAIN
        DOMAIN="${DOMAIN:-racklab.local}"
    fi

    if [[ -z "$ADMIN_EMAIL" && "$DRY_RUN" != "true" ]]; then
        read -r -p "First admin email: " ADMIN_EMAIL
    fi

    if [[ "$ADMIN_PASSWORD_STDIN" != "true" && -z "$ADMIN_PASSWORD" && "$DRY_RUN" != "true" ]]; then
        read -r -s -p "First admin password: " ADMIN_PASSWORD
        printf '\n'
    fi
}

render_env() {
    local app_key db_password reverb_app_id reverb_app_key reverb_app_secret
    app_key="$(existing_env_value APP_KEY)"
    db_password="$(existing_env_value POSTGRES_PASSWORD)"
    reverb_app_id="$(existing_env_value REVERB_APP_ID)"
    reverb_app_key="$(existing_env_value REVERB_APP_KEY)"
    reverb_app_secret="$(existing_env_value REVERB_APP_SECRET)"

    if [[ "$DRY_RUN" == "true" ]]; then
        [[ -n "$app_key" ]] || app_key="base64:dry-run-placeholder"
        [[ -n "$db_password" ]] || db_password="dry-run-placeholder"
        [[ -n "$reverb_app_key" ]] || reverb_app_key="dry-run-placeholder"
        [[ -n "$reverb_app_secret" ]] || reverb_app_secret="dry-run-placeholder"
    fi

    [[ -n "$app_key" ]] || app_key="base64:$(random_base64 32)"
    [[ -n "$db_password" ]] || db_password="$(random_base64 24)"
    [[ -n "$reverb_app_id" ]] || reverb_app_id="racklab"
    [[ -n "$reverb_app_key" ]] || reverb_app_key="$(random_base64 24)"
    [[ -n "$reverb_app_secret" ]] || reverb_app_secret="$(random_base64 32)"

    {
        printf 'APP_NAME=RackLab\n'
        printf 'APP_ENV=production\n'
        printf 'APP_KEY=%s\n' "$app_key"
        printf 'APP_DEBUG=false\n'
        printf 'APP_URL=%s\n' "$(app_url)"
        printf 'LOG_CHANNEL=stderr\n'
        printf 'CACHE_STORE=redis\n'
        printf 'QUEUE_CONNECTION=redis\n'
        printf 'SESSION_DRIVER=database\n'
        printf 'BROADCAST_CONNECTION=reverb\n'
        printf 'RACKLAB_DEFAULT_TENANT_SLUG=%s\n' "$TENANT_SLUG"
        printf 'RACKLAB_CONTAINER_RUNTIME=podman\n'
        printf 'RACKLAB_PODMAN_BINARY=podman\n'
        printf 'RACKLAB_HEALTH_REDIS_REQUIRED=true\n'
        printf 'OCTANE_SERVER=frankenphp\n'
        printf 'OCTANE_HTTPS=true\n'
        printf 'POSTGRES_DB=racklab\n'
        printf 'POSTGRES_USER=racklab\n'
        printf 'POSTGRES_PASSWORD=%s\n' "$db_password"

        if [[ "$DB_MODE" == "internal" ]]; then
            printf 'DB_CONNECTION=pgsql\n'
            printf 'DB_HOST=racklab-postgres\n'
            printf 'DB_PORT=5432\n'
            printf 'DB_DATABASE=racklab\n'
            printf 'DB_USERNAME=racklab\n'
            printf 'DB_PASSWORD=%s\n' "$db_password"
            printf 'DB_SSLMODE=disable\n'
        else
            printf 'DB_URL=%s\n' "$DB_URL"
        fi

        if [[ "$REDIS_MODE" == "internal" ]]; then
            printf 'REDIS_CLIENT=phpredis\n'
            printf 'REDIS_HOST=racklab-redis\n'
            printf 'REDIS_PORT=6379\n'
            printf 'REDIS_DB=0\n'
            printf 'REDIS_CACHE_DB=1\n'
        else
            printf 'REDIS_URL=%s\n' "$REDIS_URL"
        fi

        printf 'REVERB_APP_ID=%s\n' "$reverb_app_id"
        printf 'REVERB_APP_KEY=%s\n' "$reverb_app_key"
        printf 'REVERB_APP_SECRET=%s\n' "$reverb_app_secret"
        printf 'REVERB_SERVER_HOST=0.0.0.0\n'
        printf 'REVERB_SERVER_PORT=8080\n'
        printf 'REVERB_HOST=%s\n' "$DOMAIN"
        printf 'REVERB_PORT=8080\n'
        printf 'REVERB_SCHEME=http\n'
    } | write_file "$CONFIG_DIR/racklab.env"
}

render_toml() {
    {
        printf 'profile = "baseline"\n'
        printf 'domain = "%s"\n' "$(toml_escape "$DOMAIN")"
        printf 'listen_addr = "%s"\n' "$(toml_escape "$LISTEN_ADDR")"
        printf 'listen_port = %s\n' "$LISTEN_PORT"
        printf 'data_dir = "%s"\n' "$(toml_escape "$DATA_DIR")"
        printf 'tenant_slug = "%s"\n' "$(toml_escape "$TENANT_SLUG")"
        printf 'tenant_name = "%s"\n' "$(toml_escape "$TENANT_NAME")"
        printf 'image_registry = "%s"\n' "$(toml_escape "$IMAGE_REGISTRY")"
        printf 'image_tag = "%s"\n' "$(toml_escape "$IMAGE_TAG")"
        printf '\n[database]\n'
        printf 'mode = "%s"\n' "$DB_MODE"
        printf '\n[redis]\n'
        printf 'mode = "%s"\n' "$REDIS_MODE"
        printf '\n[tls]\n'
        printf 'mode = "%s"\n' "$TLS_MODE"
        printf 'cert = "%s/tls/bootstrap.crt"\n' "$(toml_escape "$DATA_DIR")"
        printf 'key = "%s/tls/bootstrap.key"\n' "$(toml_escape "$DATA_DIR")"
        printf '\n[plugins]\n'
        printf 'package_store = "%s/plugins/packages"\n' "$(toml_escape "$DATA_DIR")"
        printf 'config_dir = "%s/plugins/config"\n' "$(toml_escape "$DATA_DIR")"
    } | write_file "$CONFIG_DIR/racklab.toml"
}

render_caddyfile() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: write $CONFIG_DIR/Caddyfile"
        return
    fi

    mkdir -p "$CONFIG_DIR"
    sed \
        -e "s|__TLS_CERT__|/var/lib/racklab/tls/bootstrap.crt|g" \
        -e "s|__TLS_KEY__|/var/lib/racklab/tls/bootstrap.key|g" \
        > "$CONFIG_DIR/Caddyfile" <<'CADDY'
{
	{$CADDY_GLOBAL_OPTIONS}

	admin {$CADDY_SERVER_ADMIN_HOST}:{$CADDY_SERVER_ADMIN_PORT}

	frankenphp {
		worker {
			file "{$APP_PUBLIC_PATH}/frankenphp-worker.php"
			{$CADDY_SERVER_WORKER_DIRECTIVE}
			{$CADDY_SERVER_WATCH_DIRECTIVES}
		}
	}
}

{$CADDY_SERVER_SERVER_NAME} {
	tls __TLS_CERT__ __TLS_KEY__

	log {
		level {$CADDY_SERVER_LOG_LEVEL}
		format {$CADDY_SERVER_LOGGER}
	}

	route {
		root * "{$APP_PUBLIC_PATH}"
		encode zstd br gzip
		{$CADDY_SERVER_EXTRA_DIRECTIVES}

		php_server {
			index frankenphp-worker.php
			try_files {path} frankenphp-worker.php
			resolve_root_symlink
		}
	}
}
CADDY
    chmod 0644 "$CONFIG_DIR/Caddyfile"
    log "write $CONFIG_DIR/Caddyfile"
}

render_tls() {
    if [[ "$TLS_MODE" == "skip" ]]; then
        log "WARNING: --tls-mode=skip is development-only; HTTPS will not be production-ready."
        return
    fi

    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: write $DATA_DIR/tls/bootstrap.crt"
        log "DRY RUN: write $DATA_DIR/tls/bootstrap.key"
        return
    fi

    mkdir -p "$DATA_DIR/tls"

    if [[ "$TLS_MODE" == "provided" ]]; then
        cp "$TLS_CERT" "$DATA_DIR/tls/bootstrap.crt"
        cp "$TLS_KEY" "$DATA_DIR/tls/bootstrap.key"
    elif [[ ! -f "$DATA_DIR/tls/bootstrap.crt" || ! -f "$DATA_DIR/tls/bootstrap.key" ]]; then
        openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes \
            -days 365 \
            -keyout "$DATA_DIR/tls/bootstrap.key" \
            -out "$DATA_DIR/tls/bootstrap.crt" \
            -subj "/CN=$DOMAIN" \
            -addext "subjectAltName=DNS:$DOMAIN" >/dev/null 2>&1
    fi

    chmod 0600 "$DATA_DIR/tls/bootstrap.key"
    chmod 0644 "$DATA_DIR/tls/bootstrap.crt"
    log "write $DATA_DIR/tls/bootstrap.crt"
    log "write $DATA_DIR/tls/bootstrap.key"
}

prepare_directories() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: create $DATA_DIR"
        log "DRY RUN: create $CONFIG_DIR"
        log "DRY RUN: create $UNIT_DIR"
        return
    fi

    mkdir -p \
        "$DATA_DIR/postgres" \
        "$DATA_DIR/redis" \
        "$DATA_DIR/storage" \
        "$DATA_DIR/plugins/packages" \
        "$DATA_DIR/plugins/config" \
        "$CONFIG_DIR" \
        "$UNIT_DIR"
}

remove_legacy_quadlets() {
    # Pre-Horizon installs (commits before fd3a576) shipped four per-pool
    # `queue:work` Quadlets. We now run a single Horizon master in two
    # split containers (app + runner). Remove the legacy units so upgrade
    # paths don't leave stale services enabled.
    local legacy_units=(
        racklab-provider-worker@.container
        racklab-script-worker@.container
        racklab-console-worker@.container
        racklab-notification-worker@.container
    )
    local unit instance
    for unit in "${legacy_units[@]}"; do
        if [[ ! -f "$UNIT_DIR/$unit" ]]; then
            continue
        fi
        instance="${unit%.container}@1.service"
        if [[ "$SKIP_SYSTEMD_ENABLE" != "true" && "$DRY_RUN" != "true" ]]; then
            run systemctl disable --now "$instance" 2>/dev/null || true
        fi
        if [[ "$DRY_RUN" == "true" ]]; then
            log "DRY RUN: remove legacy $UNIT_DIR/$unit"
        else
            rm -f "$UNIT_DIR/$unit"
            log "removed legacy $UNIT_DIR/$unit"
        fi
    done
}

copy_quadlets() {
    local config_escaped data_escaped registry_escaped web_publish reverb_publish
    config_escaped="$(sed_escape "$CONFIG_DIR")"
    data_escaped="$(sed_escape "$DATA_DIR")"
    registry_escaped="$(sed_escape "$IMAGE_REGISTRY")"
    web_publish="$(sed_escape "$LISTEN_ADDR:$LISTEN_PORT:8000")"
    reverb_publish="$(sed_escape "$LISTEN_ADDR:8080:8080")"

    remove_legacy_quadlets

    local source destination relative

    for source in "$QUADLET_SOURCE_DIR"/*; do
        [[ -f "$source" ]] || continue
        relative="deploy/quadlets/$(basename "$source")"
        destination="$UNIT_DIR/$(basename "$source")"

        if [[ "$DRY_RUN" == "true" ]]; then
            log "DRY RUN: copy $relative -> $destination"
            continue
        fi

        sed \
            -e "s|^EnvironmentFile=/etc/racklab/racklab.env|EnvironmentFile=$config_escaped/racklab.env|g" \
            -e "s|Volume=/etc/racklab:/etc/racklab:ro,Z|Volume=$config_escaped:/etc/racklab:ro,Z|g" \
            -e "s|Volume=/var/lib/racklab/postgres:|Volume=$data_escaped/postgres:|g" \
            -e "s|Volume=/var/lib/racklab/redis:|Volume=$data_escaped/redis:|g" \
            -e "s|Volume=/var/lib/racklab/storage:|Volume=$data_escaped/storage:|g" \
            -e "s|Volume=/var/lib/racklab/plugins:|Volume=$data_escaped/plugins:|g" \
            -e "s|Volume=/var/lib/racklab/tls:|Volume=$data_escaped/tls:|g" \
            -e "s|ghcr.io/cyberbalsa/racklab|$registry_escaped|g" \
            -e "s|:main|:$IMAGE_TAG|g" \
            -e "s|0.0.0.0:443:8000|$web_publish|g" \
            -e "s|0.0.0.0:8080:8080|$reverb_publish|g" \
            "$source" > "$destination"
        chmod 0644 "$destination"
        log "copy $relative -> $destination"
    done
}

create_user() {
    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: ensure racklab system user"
        return
    fi

    if [[ "$(id -u)" -ne 0 ]]; then
        [[ "$SKIP_SYSTEMD_ENABLE" == "true" ]] && return
        die "Creating the racklab system user requires root."
    fi

    if ! id racklab >/dev/null 2>&1; then
        useradd --system --home-dir "$DATA_DIR" --shell /usr/sbin/nologin racklab
    fi
}

enable_runtime() {
    if [[ "$SKIP_SYSTEMD_ENABLE" == "true" ]]; then
        log "skip systemd enable/start"
        return
    fi

    run systemctl daemon-reload
    run systemctl enable --now racklab-runtime.target
}

post_install() {
    if [[ "$DRY_RUN" == "true" || "$SKIP_SYSTEMD_ENABLE" == "true" || "$UNINSTALL" == "true" ]]; then
        return
    fi

    local attempt
    for attempt in $(seq 1 60); do
        if podman exec racklab-web php artisan --version >/dev/null 2>&1; then
            break
        fi
        sleep 2
    done

    podman exec racklab-web php artisan racklab:migrate

    if [[ -n "$ADMIN_EMAIL" ]]; then
        local args=(php artisan racklab:bootstrap-admin --email="$ADMIN_EMAIL" --password-stdin --tenant-slug="$TENANT_SLUG" --tenant-name="$TENANT_NAME")

        if [[ -n "$ADMIN_TOKEN_FILE" ]]; then
            podman exec racklab-web rm -f /tmp/racklab-bootstrap-admin.token
            args+=(--token-file=/tmp/racklab-bootstrap-admin.token)
        fi

        printf '%s' "${ADMIN_PASSWORD:-}" | podman exec -i racklab-web "${args[@]}"

        if [[ -n "$ADMIN_TOKEN_FILE" ]]; then
            mkdir -p "$(dirname "$ADMIN_TOKEN_FILE")"
            podman cp racklab-web:/tmp/racklab-bootstrap-admin.token "$ADMIN_TOKEN_FILE"
            chmod 0600 "$ADMIN_TOKEN_FILE"
        fi
    fi
}

uninstall_runtime() {
    local names=(
        racklab.network
        racklab-runtime.target
        racklab-plugin-bootstrap.container
        racklab-postgres.container
        racklab-redis.container
        racklab-web.container
        racklab-reverb.container
        racklab-horizon-app.container
        racklab-horizon-runner.container
        racklab-scheduler-reconciler@.container
        # Legacy per-pool Quadlets (pre-Horizon installs); removed here for
        # idempotent uninstall on hosts upgraded from before fd3a576.
        racklab-provider-worker@.container
        racklab-script-worker@.container
        racklab-console-worker@.container
        racklab-notification-worker@.container
    )

    if [[ "$SKIP_SYSTEMD_ENABLE" != "true" ]]; then
        run systemctl disable --now racklab-runtime.target || true
    fi

    local name
    for name in "${names[@]}"; do
        if [[ "$DRY_RUN" == "true" ]]; then
            log "DRY RUN: remove $UNIT_DIR/$name"
        else
            rm -f "$UNIT_DIR/$name"
        fi
    done

    if [[ "$KEEP_DATA" != "true" ]]; then
        if [[ "$DRY_RUN" == "true" ]]; then
            log "DRY RUN: remove $DATA_DIR"
        else
            rm -rf "$DATA_DIR"
        fi
    fi

    if [[ "$DRY_RUN" == "true" ]]; then
        log "DRY RUN: remove $CONFIG_DIR"
    else
        rm -rf "$CONFIG_DIR"
    fi

    if [[ "$SKIP_SYSTEMD_ENABLE" != "true" ]]; then
        run systemctl daemon-reload
    fi
}

install_runtime() {
    prepare_directories
    create_user
    render_tls
    render_env
    render_toml
    render_caddyfile
    copy_quadlets
    enable_runtime
    post_install

    if [[ "$UPGRADE" == "true" ]]; then
        log "RackLab Baseline upgrade rendered and runtime restarted."
    else
        log "RackLab Baseline install rendered."
    fi
}

main() {
    parse_args "$@"

    if [[ -n "$LOG_FILE" ]]; then
        mkdir -p "$(dirname "$LOG_FILE")"
        exec > >(tee -a "$LOG_FILE") 2> >(tee -a "$LOG_FILE" >&2)
    fi

    prompt_interactive
    validate
    read_admin_password
    preflight

    if [[ "$UNINSTALL" == "true" ]]; then
        uninstall_runtime
        return
    fi

    install_runtime
}

main "$@"

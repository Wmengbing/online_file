#!/usr/bin/env bash

# Shared helpers for the production deploy and rollback scripts.
# This file is sourced by deploy.sh and rollback.sh.

log() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

warn() {
    printf '[%s] WARNING: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
}

die() {
    printf '[%s] ERROR: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || die "Missing required command: $1"
}

load_deploy_config() {
    DEPLOY_CONFIG="${ONLINE_FILE_DEPLOY_CONFIG:-/etc/online-file-deploy.conf}"
    [[ -r "$DEPLOY_CONFIG" ]] || die "Deploy config is not readable: $DEPLOY_CONFIG"

    # shellcheck disable=SC1090
    source "$DEPLOY_CONFIG"

    : "${APP_ROOT:?APP_ROOT is required in $DEPLOY_CONFIG}"
    : "${REPO_URL:?REPO_URL is required in $DEPLOY_CONFIG}"

    APP_ROOT="${APP_ROOT%/}"
    case "$APP_ROOT" in
        /*) ;;
        *) die "APP_ROOT must be an absolute path" ;;
    esac
    case "$APP_ROOT" in
        /|/www|/www/wwwroot) die "APP_ROOT is too broad: $APP_ROOT" ;;
    esac

    PHP_BIN="${PHP_BIN:-php}"
    COMPOSER_BIN="${COMPOSER_BIN:-composer}"
    COMPOSER_PHAR="${COMPOSER_PHAR:-}"
    WEB_USER="${WEB_USER:-www}"
    WEB_GROUP="${WEB_GROUP:-www}"
    KEEP_RELEASES="${KEEP_RELEASES:-5}"
    HEALTHCHECK_URL="${HEALTHCHECK_URL:-}"
    HEALTHCHECK_TIMEOUT="${HEALTHCHECK_TIMEOUT:-10}"
    HEALTHCHECK_RETRIES="${HEALTHCHECK_RETRIES:-6}"
    POST_SWITCH_COMMAND="${POST_SWITCH_COMMAND:-}"
    DB_BACKUP_ENABLED="${DB_BACKUP_ENABLED:-1}"
    DB_BACKUP_REQUIRED="${DB_BACKUP_REQUIRED:-1}"
    OPEN_BASEDIR_ENABLED="${OPEN_BASEDIR_ENABLED:-1}"
    MYSQL_DEFAULTS_FILE="${MYSQL_DEFAULTS_FILE:-}"
    MYSQL_LOGIN_PATH="${MYSQL_LOGIN_PATH:-}"
    MYSQL_BIN="${MYSQL_BIN:-mysql}"
    MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"
    KEEP_DB_BACKUPS_DAYS="${KEEP_DB_BACKUPS_DAYS:-14}"
    HEALTHCHECK_HOST="${HEALTHCHECK_HOST:-}"

    [[ "$KEEP_RELEASES" =~ ^[1-9][0-9]*$ ]] || die "KEEP_RELEASES must be a positive integer"
    [[ "$HEALTHCHECK_RETRIES" =~ ^[1-9][0-9]*$ ]] || die "HEALTHCHECK_RETRIES must be a positive integer"
    [[ "$HEALTHCHECK_TIMEOUT" =~ ^[1-9][0-9]*$ ]] || die "HEALTHCHECK_TIMEOUT must be a positive integer"
    [[ "$OPEN_BASEDIR_ENABLED" =~ ^[01]$ ]] || die "OPEN_BASEDIR_ENABLED must be 0 or 1"

    RELEASES_DIR="$APP_ROOT/releases"
    SHARED_DIR="$APP_ROOT/shared"
    REPOSITORY_DIR="$APP_ROOT/repository.git"
    BACKUPS_DIR="$APP_ROOT/backups"
    CURRENT_LINK="$APP_ROOT/current"
    PREVIOUS_LINK="$APP_ROOT/previous"
    DEPLOY_LOG="$APP_ROOT/deployments.log"
}

initialize_layout() {
    if [[ -d "$APP_ROOT/application" || -d "$APP_ROOT/public" ]]; then
        die "APP_ROOT contains a legacy in-place installation. Use a new APP_ROOT and follow DEPLOYMENT.md."
    fi

    mkdir -p "$RELEASES_DIR" "$SHARED_DIR/uploads" "$SHARED_DIR/runtime" "$BACKUPS_DIR"
    mkdir -p "$SHARED_DIR/runtime/cache" "$SHARED_DIR/runtime/temp" "$SHARED_DIR/runtime/log"

    [[ -f "$SHARED_DIR/.env" ]] || die "Missing production environment file: $SHARED_DIR/.env"

    if [[ "$(id -u)" -eq 0 ]] && id "$WEB_USER" >/dev/null 2>&1; then
        chown "$WEB_USER:$WEB_GROUP" "$SHARED_DIR/uploads" "$SHARED_DIR/runtime"
        chown -R "$WEB_USER:$WEB_GROUP" "$SHARED_DIR/runtime/cache" "$SHARED_DIR/runtime/temp"
        chown "root:$WEB_GROUP" "$SHARED_DIR/.env"
        chmod 0640 "$SHARED_DIR/.env"
        chmod 0770 "$SHARED_DIR/uploads" "$SHARED_DIR/runtime"
    else
        warn "Could not adjust owner automatically; ensure $WEB_USER can write shared/uploads and shared/runtime"
    fi
}

acquire_deploy_lock() {
    require_command flock
    exec 9>"$APP_ROOT/.deploy.lock"
    flock -n 9 || die "Another deploy or rollback is already running"
}

current_release() {
    if [[ -L "$CURRENT_LINK" ]]; then
        readlink -f "$CURRENT_LINK"
    fi
}

assert_release_path() {
    local candidate
    candidate="$(readlink -f "$1")"
    [[ -n "$candidate" && -d "$candidate" ]] || die "Release directory does not exist: $1"
    case "$candidate" in
        "$RELEASES_DIR"/*) ;;
        *) die "Path is outside the releases directory: $candidate" ;;
    esac
}

atomic_link() {
    local target="$1"
    local link_path="$2"
    local temp_link="${link_path}.tmp.$$"

    assert_release_path "$target"
    rm -f -- "$temp_link"
    ln -s "$target" "$temp_link"
    mv -Tf "$temp_link" "$link_path"
}

link_shared_paths() {
    local release_dir="$1"
    local path

    for path in .env uploads runtime; do
        [[ ! -e "$release_dir/$path" && ! -L "$release_dir/$path" ]] || die "Release already contains reserved path: $path"
    done

    ln -s "$SHARED_DIR/.env" "$release_dir/.env"
    ln -s "$SHARED_DIR/uploads" "$release_dir/uploads"
    ln -s "$SHARED_DIR/runtime" "$release_dir/runtime"
}

configure_open_basedir() {
    local release_dir="$1"
    local user_ini="$release_dir/public/.user.ini"

    if [[ "$OPEN_BASEDIR_ENABLED" != "1" ]]; then
        rm -f -- "$user_ini"
        warn "PHP open_basedir management is disabled"
        return 0
    fi

    # BaoTa points Nginx at current/public, but PHP resolves that symlink to the
    # real release directory. Allow the immutable release and shared writable
    # data explicitly so framework bootstrapping and shared paths both work.
    printf 'open_basedir=%s/:%s/:/tmp/\n' "$release_dir" "$SHARED_DIR" >"$user_ini"
    chmod 0644 "$user_ini"
    log "Configured PHP open_basedir for $(basename "$release_dir")"
}

clear_application_cache() {
    local cache_dir
    for cache_dir in "$SHARED_DIR/runtime/cache" "$SHARED_DIR/runtime/temp"; do
        case "$cache_dir" in
            "$SHARED_DIR"/runtime/*) ;;
            *) die "Refusing to clear unexpected path: $cache_dir" ;;
        esac
        mkdir -p "$cache_dir"
        find "$cache_dir" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
    done
}

run_post_switch_command() {
    if [[ -n "$POST_SWITCH_COMMAND" ]]; then
        log "Running post-switch command"
        bash -lc "$POST_SWITCH_COMMAND"
    fi
}

health_check() {
    local attempt

    if [[ -z "$HEALTHCHECK_URL" ]]; then
        warn "HEALTHCHECK_URL is empty; HTTP health check skipped"
        return 0
    fi

    require_command curl
    for ((attempt = 1; attempt <= HEALTHCHECK_RETRIES; attempt++)); do
        local -a curl_args=(--fail --silent --show-error --location --max-time "$HEALTHCHECK_TIMEOUT")
        if [[ -n "$HEALTHCHECK_HOST" ]]; then
            curl_args+=(--header "Host: $HEALTHCHECK_HOST")
        fi
        if curl "${curl_args[@]}" "$HEALTHCHECK_URL" >/dev/null; then
            log "Health check passed: $HEALTHCHECK_URL"
            return 0
        fi
        warn "Health check attempt $attempt/$HEALTHCHECK_RETRIES failed"
        sleep 2
    done
    return 1
}

read_env_value() {
    local key="$1"
    local value
    value="$(sed -n -E "s/^[[:space:]]*${key}[[:space:]]*=[[:space:]]*(.*)$/\1/p" "$SHARED_DIR/.env" | tail -n 1 | tr -d '\r')"
    value="${value%%[[:space:]];*}"
    value="${value#\"}"
    value="${value%\"}"
    value="${value#\'}"
    value="${value%\'}"
    printf '%s' "$value"
}

backup_database() {
    local database_name backup_file temp_file

    [[ "$DB_BACKUP_ENABLED" == "1" ]] || {
        warn "Database backup is disabled"
        return 0
    }

    database_name="$(read_env_value DB_NAME)"
    if [[ -z "$database_name" ]]; then
        [[ "$DB_BACKUP_REQUIRED" == "1" ]] && die "DB_NAME was not found in shared/.env"
        warn "DB_NAME was not found; database backup skipped"
        return 0
    fi

    if [[ -z "$MYSQL_DEFAULTS_FILE" && -z "$MYSQL_LOGIN_PATH" ]]; then
        [[ "$DB_BACKUP_REQUIRED" == "1" ]] && die "Configure MYSQL_DEFAULTS_FILE or MYSQL_LOGIN_PATH before deploying"
        warn "MySQL backup credentials are not configured; database backup skipped"
        return 0
    fi

    require_command "$MYSQLDUMP_BIN"
    require_command gzip
    umask 077
    backup_file="$BACKUPS_DIR/${database_name}_$(date '+%Y%m%d_%H%M%S').sql.gz"
    temp_file="${backup_file}.tmp"
    log "Backing up database $database_name"

    if [[ -n "$MYSQL_DEFAULTS_FILE" ]]; then
        [[ -r "$MYSQL_DEFAULTS_FILE" ]] || die "MySQL defaults file is not readable: $MYSQL_DEFAULTS_FILE"
        "$MYSQLDUMP_BIN" --defaults-extra-file="$MYSQL_DEFAULTS_FILE" \
            --single-transaction --quick --routines --triggers "$database_name" | gzip -c >"$temp_file"
    else
        "$MYSQLDUMP_BIN" --login-path="$MYSQL_LOGIN_PATH" \
            --single-transaction --quick --routines --triggers "$database_name" | gzip -c >"$temp_file"
    fi

    mv "$temp_file" "$backup_file"
    log "Database backup created: $backup_file"
    find "$BACKUPS_DIR" -maxdepth 1 -type f -name '*.sql.gz' -mtime "+$KEEP_DB_BACKUPS_DAYS" -delete
}

run_composer_install() {
    local release_dir="$1"
    export COMPOSER_HOME="${COMPOSER_HOME:-$APP_ROOT/.composer}"
    export COMPOSER_ALLOW_SUPERUSER=1
    mkdir -p "$COMPOSER_HOME"

    log "Installing production dependencies"
    if [[ -n "$COMPOSER_PHAR" ]]; then
        "$PHP_BIN" "$COMPOSER_PHAR" install \
            --working-dir="$release_dir" --no-dev --prefer-dist --optimize-autoloader --no-interaction
    else
        "$COMPOSER_BIN" install \
            --working-dir="$release_dir" --no-dev --prefer-dist --optimize-autoloader --no-interaction
    fi
}

lint_php_files() {
    local release_dir="$1"
    local php_file
    log "Checking PHP syntax"
    while IFS= read -r -d '' php_file; do
        "$PHP_BIN" -l "$php_file" >/dev/null
    done < <(find "$release_dir/application" "$release_dir/config" "$release_dir/route" \
        -type f -name '*.php' -print0)
}

cleanup_old_releases() {
    local active release_dir kept=0
    local -a releases=()
    active="$(current_release || true)"
    mapfile -t releases < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | cut -d' ' -f2-)

    for release_dir in "${releases[@]}"; do
        [[ "$release_dir" == "$active" ]] && continue
        kept=$((kept + 1))
        if ((kept > KEEP_RELEASES)); then
            case "$release_dir" in
                "$RELEASES_DIR"/*) rm -rf -- "$release_dir" ;;
                *) die "Refusing to remove unexpected release path: $release_dir" ;;
            esac
        fi
    done
}

record_deployment() {
    local action="$1"
    local release_dir="$2"
    local commit="unknown"
    [[ -f "$release_dir/.release-commit" ]] && commit="$(<"$release_dir/.release-commit")"
    printf '%s\t%s\t%s\t%s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$action" "$(basename "$release_dir")" "$commit" >>"$DEPLOY_LOG"
}

#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-lib.sh
source "$SCRIPT_DIR/deploy-lib.sh"

load_deploy_config
initialize_layout
acquire_deploy_lock

require_command git
require_command tar
require_command "$PHP_BIN"
if [[ -z "$COMPOSER_PHAR" ]]; then
    require_command "$COMPOSER_BIN"
fi

RELEASE_REF="${1:-main}"

if [[ ! -d "$REPOSITORY_DIR" ]]; then
    log "Creating repository mirror"
    git clone --mirror "$REPO_URL" "$REPOSITORY_DIR"
fi

log "Fetching repository updates"
git --git-dir="$REPOSITORY_DIR" remote set-url origin "$REPO_URL"
git --git-dir="$REPOSITORY_DIR" fetch --prune --tags origin

COMMIT="$(git --git-dir="$REPOSITORY_DIR" rev-parse --verify "${RELEASE_REF}^{commit}")" \
    || die "Unknown branch, tag, or commit: $RELEASE_REF"
SHORT_COMMIT="$(git --git-dir="$REPOSITORY_DIR" rev-parse --short=10 "$COMMIT")"
RELEASE_ID="$(date '+%Y%m%d%H%M%S')_${SHORT_COMMIT}"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"

[[ ! -e "$RELEASE_DIR" ]] || die "Release already exists: $RELEASE_DIR"
mkdir "$RELEASE_DIR"

deploy_failed() {
    local status=$?
    if [[ "$status" -ne 0 && -n "${RELEASE_DIR:-}" && -d "${RELEASE_DIR:-}" ]]; then
        touch "$RELEASE_DIR/.failed"
        warn "Release failed and was kept for inspection: $RELEASE_DIR"
    fi
    exit "$status"
}
trap deploy_failed ERR

log "Exporting $RELEASE_REF at $COMMIT"
git --git-dir="$REPOSITORY_DIR" archive "$COMMIT" | tar -x -C "$RELEASE_DIR"
printf '%s\n' "$COMMIT" >"$RELEASE_DIR/.release-commit"
printf '%s\n' "$RELEASE_REF" >"$RELEASE_DIR/.release-ref"

link_shared_paths "$RELEASE_DIR"
configure_open_basedir "$RELEASE_DIR"
run_composer_install "$RELEASE_DIR"
lint_php_files "$RELEASE_DIR"
backup_database
clear_application_cache

OLD_RELEASE="$(current_release || true)"
if [[ -n "$OLD_RELEASE" ]]; then
    atomic_link "$OLD_RELEASE" "$PREVIOUS_LINK"
fi

log "Switching current release to $RELEASE_ID"
atomic_link "$RELEASE_DIR" "$CURRENT_LINK"

if ! run_post_switch_command || ! health_check; then
    warn "New release did not pass post-switch checks"
    if [[ -n "$OLD_RELEASE" && -d "$OLD_RELEASE" ]]; then
        atomic_link "$OLD_RELEASE" "$CURRENT_LINK"
        run_post_switch_command || true
        warn "Automatically restored $(basename "$OLD_RELEASE")"
    else
        rm -f -- "$CURRENT_LINK"
    fi
    touch "$RELEASE_DIR/.failed"
    exit 1
fi

rm -f -- "$RELEASE_DIR/.failed"
record_deployment deploy "$RELEASE_DIR"
cleanup_old_releases
trap - ERR

log "Deployment complete: $RELEASE_ID"
log "Current path: $CURRENT_LINK -> $RELEASE_DIR"

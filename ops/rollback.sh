#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-lib.sh
source "$SCRIPT_DIR/deploy-lib.sh"

load_deploy_config
initialize_layout
acquire_deploy_lock

CURRENT_RELEASE="$(current_release || true)"
[[ -n "$CURRENT_RELEASE" ]] || die "There is no active release to roll back"

if [[ -n "${1:-}" ]]; then
    TARGET_RELEASE="$RELEASES_DIR/$1"
else
    [[ -L "$PREVIOUS_LINK" ]] || die "No previous release is recorded; pass a release directory name explicitly"
    TARGET_RELEASE="$(readlink -f "$PREVIOUS_LINK")"
fi

assert_release_path "$TARGET_RELEASE"
[[ "$TARGET_RELEASE" != "$CURRENT_RELEASE" ]] || die "Target release is already active"
[[ ! -f "$TARGET_RELEASE/.failed" ]] || die "Target release is marked as failed: $(basename "$TARGET_RELEASE")"

clear_application_cache
atomic_link "$CURRENT_RELEASE" "$PREVIOUS_LINK"
log "Rolling back to $(basename "$TARGET_RELEASE")"
atomic_link "$TARGET_RELEASE" "$CURRENT_LINK"

if ! run_post_switch_command || ! health_check; then
    warn "Rollback target failed validation; restoring current release"
    atomic_link "$CURRENT_RELEASE" "$CURRENT_LINK"
    run_post_switch_command || true
    exit 1
fi

record_deployment rollback "$TARGET_RELEASE"
log "Rollback complete: $(basename "$TARGET_RELEASE")"

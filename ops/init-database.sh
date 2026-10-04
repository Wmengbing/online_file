#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-lib.sh
source "$SCRIPT_DIR/deploy-lib.sh"

load_deploy_config
initialize_layout
acquire_deploy_lock
require_command "$MYSQL_BIN"
require_command "$PHP_BIN"

SCHEMA_FILE="$SCRIPT_DIR/schema.sql"
[[ -r "$SCHEMA_FILE" ]] || die "Database schema is not readable: $SCHEMA_FILE"

DATABASE_NAME="$(read_env_value DB_NAME)"
[[ "$DATABASE_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_NAME must contain only letters, digits, and underscores"

MYSQL_AUTH_ARGS=()
if [[ -n "$MYSQL_DEFAULTS_FILE" ]]; then
    [[ -r "$MYSQL_DEFAULTS_FILE" ]] || die "MySQL defaults file is not readable: $MYSQL_DEFAULTS_FILE"
    MYSQL_AUTH_ARGS+=("--defaults-extra-file=$MYSQL_DEFAULTS_FILE")
elif [[ -n "$MYSQL_LOGIN_PATH" ]]; then
    MYSQL_AUTH_ARGS+=("--login-path=$MYSQL_LOGIN_PATH")
else
    die "Configure MYSQL_DEFAULTS_FILE or MYSQL_LOGIN_PATH before initializing the database"
fi

DATABASE_EXISTS="$("$MYSQL_BIN" "${MYSQL_AUTH_ARGS[@]}" --batch --skip-column-names \
    -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$DATABASE_NAME'")"
[[ "$DATABASE_EXISTS" == "1" ]] || die "Database does not exist: $DATABASE_NAME"

TABLE_COUNT="$("$MYSQL_BIN" "${MYSQL_AUTH_ARGS[@]}" --batch --skip-column-names \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DATABASE_NAME'")"

if [[ "$TABLE_COUNT" == "0" ]]; then
    log "Importing clean database schema into $DATABASE_NAME"
    "$MYSQL_BIN" "${MYSQL_AUTH_ARGS[@]}" "$DATABASE_NAME" <"$SCHEMA_FILE"
else
    USERS_TABLE="$("$MYSQL_BIN" "${MYSQL_AUTH_ARGS[@]}" --batch --skip-column-names \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DATABASE_NAME' AND table_name = 'users'")"
    [[ "$USERS_TABLE" == "1" ]] || die "Database is not empty and is not an initialized online_file database"
    USER_COUNT="$("$MYSQL_BIN" "${MYSQL_AUTH_ARGS[@]}" --batch --skip-column-names \
        "$DATABASE_NAME" -e 'SELECT COUNT(*) FROM users')"
    [[ "$USER_COUNT" == "0" ]] || die "Database already contains users; initialization was not run again"
    warn "Schema already exists with no users; continuing administrator creation"
fi

read -r -p 'Initial administrator username [admin]: ' ADMIN_USERNAME
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
[[ ${#ADMIN_USERNAME} -ge 3 && ${#ADMIN_USERNAME} -le 50 ]] || die "Administrator username must be 3-50 characters"

read -r -p 'Administrator email (optional): ' ADMIN_EMAIL

while true; do
    read -r -s -p 'Administrator password (at least 12 characters): ' ADMIN_PASSWORD
    printf '\n'
    [[ ${#ADMIN_PASSWORD} -ge 12 ]] || {
        warn "Password is too short"
        continue
    }
    read -r -s -p 'Confirm administrator password: ' ADMIN_PASSWORD_CONFIRM
    printf '\n'
    [[ "$ADMIN_PASSWORD" == "$ADMIN_PASSWORD_CONFIRM" ]] || {
        warn "Passwords do not match"
        continue
    }
    break
done

PASSWORD_HASH="$(printf '%s' "$ADMIN_PASSWORD" | "$PHP_BIN" -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')"
unset ADMIN_PASSWORD ADMIN_PASSWORD_CONFIRM

to_hex() {
    printf '%s' "$1" | od -An -tx1 | tr -d ' \n'
}

USERNAME_HEX="$(to_hex "$ADMIN_USERNAME")"
EMAIL_HEX="$(to_hex "$ADMIN_EMAIL")"

log "Creating the first administrator"
"$MYSQL_BIN" "${MYSQL_AUTH_ARGS[@]}" "$DATABASE_NAME" <<SQL
START TRANSACTION;
INSERT INTO users (username, password, email, status, storage_quota, storage_used)
VALUES (CONVERT(UNHEX('$USERNAME_HEX') USING utf8mb4), '$PASSWORD_HASH', NULLIF(CONVERT(UNHEX('$EMAIL_HEX') USING utf8mb4), ''), 1, 10737418240, 0);
SET @admin_user_id = LAST_INSERT_ID();
INSERT INTO user_roles (user_id, role_id, is_manager)
SELECT @admin_user_id, id, 1 FROM roles WHERE code = 'super_admin';
INSERT INTO files (user_id, parent_id, name, type, path, status, is_personal)
VALUES (@admin_user_id, 0, CONCAT(CONVERT(UNHEX('$USERNAME_HEX') USING utf8mb4), '的个人文件夹'), 2, '', 1, 1);
COMMIT;
SQL

printf '%s\t%s\t%s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$DATABASE_NAME" "$ADMIN_USERNAME" >"$APP_ROOT/.database-initialized"
chmod 0600 "$APP_ROOT/.database-initialized"
log "Database initialization complete; administrator: $ADMIN_USERNAME"

#!/usr/bin/env bash
# Starts Matomo 5 with this plugin, installs Matomo on first run and
# configures the plugin against the OIDC provider from .env.
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f .env ]; then
    echo "Missing dev/.env, copy .env.example and fill in your provider details." >&2
    exit 1
fi
set -a
. ./.env
set +a

MATOMO_PORT="${MATOMO_PORT:-8080}"
MATOMO_URL="http://localhost:${MATOMO_PORT}"
ADMIN_PASSWORD="${MATOMO_ADMIN_PASSWORD:-matomo-dev-password}"
export MATOMO_URL

docker compose up -d --wait

matomo() {
    docker compose exec -T matomo "$@"
}

console() {
    docker compose exec -T -u www-data matomo ./console "$@"
}

# the first web request already creates config.ini.php, so look for a finished installation
is_installed() {
    matomo sh -c 'grep -q "^\[database\]" config/config.ini.php && ! grep -q "^installation_in_progress" config/config.ini.php' 2>/dev/null
}

matomo ln -sfn /plugin-src /var/www/html/plugins/LoginOIDC

echo "Waiting for Matomo at ${MATOMO_URL} ..."
curl -sf -o /dev/null --retry 60 --retry-all-errors --retry-delay 2 "${MATOMO_URL}/"

if ! is_installed; then
    echo "Installing Matomo ..."
    install_step() {
        local action="$1"
        shift
        local status
        status=$(curl -s -o /dev/null -w '%{http_code}' "$@" "${MATOMO_URL}/index.php?module=Installation&action=${action}")
        if [ "$status" -ge 400 ]; then
            echo "Installation step ${action} failed with HTTP ${status}" >&2
            exit 1
        fi
    }
    install_step databaseSetup -X POST \
        --data-urlencode "host=db" --data-urlencode "username=matomo" --data-urlencode "password=matomo" \
        --data-urlencode "dbname=matomo" --data-urlencode "tables_prefix=matomo_" \
        --data-urlencode "adapter=PDO\\MYSQL" --data-urlencode "type=InnoDB"
    install_step tablesCreation
    install_step setupSuperUser -X POST \
        --data-urlencode "login=admin" --data-urlencode "password=${ADMIN_PASSWORD}" \
        --data-urlencode "password_bis=${ADMIN_PASSWORD}" --data-urlencode "email=admin@example.com" \
        --data-urlencode "subscribe_newsletter_piwikorg=0" --data-urlencode "subscribe_newsletter_professionalservices=0"
    install_step firstWebsiteSetup -X POST \
        --data-urlencode "siteName=dev" --data-urlencode "url=http://example.com" \
        --data-urlencode "timezone=UTC" --data-urlencode "ecommerce=0"
    install_step "trackingCode&site_idSite=1&site_name=dev"
    install_step finished -X POST \
        --data-urlencode "do_not_track=1" --data-urlencode "anonymise_ip=1" --data-urlencode "submit=Continue"

    if ! is_installed; then
        echo "Matomo installation did not complete, open ${MATOMO_URL} to finish it manually." >&2
        exit 1
    fi
fi

console plugin:activate LoginOIDC

# also log to tmp/logs/matomo.log, e.g. failed requests to the provider
console config:set 'log.log_writers=["screen","file"]'

# deliver all emails to Mailpit, the default senders like noreply@localhost:<port> are not valid addresses
console config:set --section=General --key=noreply_email_address --value=noreply@example.com
console config:set --section=General --key=login_password_recovery_replyto_email_address --value=no-reply@example.com
console config:set --section=mail --key=transport --value=smtp
console config:set --section=mail --key=host --value=mailpit
console config:set --section=mail --key=port --value=1025

echo "Configuring LoginOIDC ..."
python3 - <<'PY' | docker compose exec -T db mariadb -umatomo -pmatomo matomo
import os

settings = {
    "authenticationName": os.environ.get("OIDC_BUTTON_NAME") or "OIDC login",
    "buttonColor": os.environ.get("OIDC_BUTTON_COLOR", ""),
    # the plugin reads the endpoints from the provider's discovery document
    "issuerUrl": os.environ["OIDC_ISSUER"],
    "clientId": os.environ["OIDC_CLIENT_ID"],
    "clientSecret": os.environ["OIDC_CLIENT_SECRET"],
    "scope": os.environ.get("OIDC_SCOPE") or "openid email",
    "redirectUriOverride": os.environ["MATOMO_URL"] + "/index.php?module=LoginOIDC&action=callback&provider=oidc",
    "allowSignup": "1",
    "requireVerifiedEmail": "1",
    "allowedSignupDomains": os.environ.get("OIDC_ALLOWED_SIGNUP_DOMAINS", ""),
    "disablePasswordConfirmation": "1",
    "disablePasswordLogin": "1",
    "disableDirectLoginUrl": "1",
    "disableSuperuser": "0",
    "bypassTwoFa": "0",
    "autoLinking": "0",
}

def quote(value):
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"

print("DELETE FROM matomo_plugin_setting WHERE plugin_name = 'LoginOIDC' AND user_login = '';")
rows = ",\n".join(
    f"('LoginOIDC', {quote(name)}, {quote(value)}, 0, '')" for name, value in settings.items()
)
print(f"INSERT INTO matomo_plugin_setting (plugin_name, setting_name, setting_value, json_encoded, user_login) VALUES\n{rows};")
PY

console cache:clear >/dev/null

cat <<EOF

Matomo is running at ${MATOMO_URL}
  Superuser: admin / ${ADMIN_PASSWORD}
  Callback URL (must be allowed for client ${OIDC_CLIENT_ID} at your provider):
    ${MATOMO_URL}/index.php?module=LoginOIDC&action=callback&provider=oidc

Plugin settings: ${MATOMO_URL}/index.php?module=CoreAdminHome&action=generalSettings#/LoginOIDC
Sent emails:     http://localhost:${MAILPIT_PORT:-8025}
Stop:  docker compose -f dev/docker-compose.yml down      (add -v to wipe all data)
EOF

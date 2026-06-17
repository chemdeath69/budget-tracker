#!/usr/bin/env sh
# =============================================================================
# budget-tracker — guided installer for sureserver / SureSupport hosting
# =============================================================================
# Drives the Control Panel V3 API to provision everything the app needs:
#   1. a subdomain + web root
#   2. a MySQL 8 database + user + privileges
#   3. PHP 8.3 (FPM) on the subdomain
#   4. a generated config.php (written to the server, or saved locally)
#   5. the database schema (imported via the API)
#   6. the nightly cron job
#
# It does NOT upload the application code (the panel API has no binary upload)
# and cannot click through Plaid/Google. You do those by hand — see
# docs/install/20-upload-code.md and docs/install/services/*.
#
# Requirements (your laptop): a POSIX shell, `curl`. `openssl` is used to
# generate secret keys if present (otherwise you paste your own).
#
# Usage:
#   ./tools/install.sh              interactive install
#   ./tools/install.sh --dry-run    show every API call without making changes
#   ./tools/install.sh --probe      only verify the API key (GET /account)
#   ./tools/install.sh --finish     re-run only the steps that need code on the
#                                   server first (write config.php + import schema)
#   ./tools/install.sh teardown     delete a subdomain + database (undo / test cleanup)
#   ./tools/install.sh --help
#
# Docs: docs/INSTRUCTIONS.md  ·  docs/install/10-hosting-sureserver.md
# =============================================================================
set -eu

# ---- pretty output ----------------------------------------------------------
if [ -t 1 ]; then B="$(printf '\033[1m')"; R="$(printf '\033[0m')"; G="$(printf '\033[32m')"; Y="$(printf '\033[33m')"; C="$(printf '\033[36m')"; RED="$(printf '\033[31m')"; else B=''; R=''; G=''; Y=''; C=''; RED=''; fi
info() { printf '%s\n' "${C}$*${R}"; }
ok()   { printf '%s\n' "${G}✓ $*${R}"; }
warn() { printf '%s\n' "${Y}! $*${R}" >&2; }
die()  { printf '%s\n' "${RED}✗ $*${R}" >&2; exit 1; }
hr()   { printf '%s\n' "----------------------------------------------------------------------"; }

DRY=0
MODE="install"

for arg in "$@"; do
  case "$arg" in
    --help|-h)
      sed -n '2,40p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    --dry-run) DRY=1 ;;
    --probe)   MODE="probe" ;;
    --finish)  MODE="finish" ;;
    teardown)  MODE="teardown" ;;
    *) die "unknown argument: $arg (try --help)" ;;
  esac
done

command -v curl >/dev/null 2>&1 || die "curl is required but not found."
HAVE_OPENSSL=0; command -v openssl >/dev/null 2>&1 && HAVE_OPENSSL=1

# ---- prompt helpers ---------------------------------------------------------
ask() { # ask VAR "prompt" ["default"]
  _v="$1"; _p="$2"; _d="${3:-}"
  if [ -n "$_d" ]; then printf '%s' "${B}$_p${R} [${_d}]: " >&2; else printf '%s' "${B}$_p${R}: " >&2; fi
  IFS= read -r _ans || true
  [ -z "$_ans" ] && _ans="$_d"
  eval "$_v=\$_ans"
}
ask_secret() { # ask_secret VAR "prompt"
  _v="$1"; _p="$2"
  printf '%s' "${B}$_p${R} (hidden): " >&2
  stty -echo 2>/dev/null || true; IFS= read -r _ans || true; stty echo 2>/dev/null || true
  printf '\n' >&2
  eval "$_v=\$_ans"
}
confirm() { # confirm "prompt" -> returns 0 if yes
  printf '%s' "${B}$1${R} [y/N]: " >&2; IFS= read -r _a || true
  case "$_a" in y|Y|yes|YES) return 0 ;; *) return 1 ;; esac
}

# ---- JSON helpers (no jq required to BUILD requests) ------------------------
jstr() { # JSON-escape a scalar string -> prints "...quoted..."
  printf '%s' "$1" | awk '
    BEGIN{ printf "\"" }
    { s=$0;
      gsub(/\\/,"\\\\",s); gsub(/"/,"\\\"",s);
      gsub(/\t/,"\\t",s); gsub(/\r/,"\\r",s);
      printf "%s", s }
    END{ printf "\"" }'
}
# crude extractor: json_get '"key"' < response  -> first string value for key
json_get() { # json_get KEY  (reads stdin)
  sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" | head -n1
}

# ---- the API call -----------------------------------------------------------
# api METHOD PATH [JSON_BODY]
# Honors $DRY. Sends Content-Type: application/json + x-api-key.
# Sets globals: API_CODE (http status), API_BODY (response body).
api() {
  _m="$1"; _path="$2"; _body="${3:-}"
  _url="${API_BASE}${_path}"
  if [ "$DRY" = "1" ] && [ "$_m" != "GET" ]; then
    info "  [dry-run] $_m $_path"; [ -n "$_body" ] && printf '            body: %s\n' "$_body"
    API_CODE=200; API_BODY='{"dry_run":true}'; return 0
  fi
  _tmp="$(mktemp)"
  if [ -n "$_body" ]; then
    API_CODE="$(curl -sS -o "$_tmp" -w '%{http_code}' -X "$_m" "$_url" \
        -H "x-api-key: ${API_KEY}" -H 'Content-Type: application/json' \
        -H 'Accept: application/json' --data "$_body")"
  else
    API_CODE="$(curl -sS -o "$_tmp" -w '%{http_code}' -X "$_m" "$_url" \
        -H "x-api-key: ${API_KEY}" -H 'Accept: application/json')"
  fi
  API_BODY="$(cat "$_tmp")"; rm -f "$_tmp"
}
api_ok() { # die unless 2xx
  case "$API_CODE" in 2*) return 0 ;; esac
  die "API $1 failed (HTTP $API_CODE): $API_BODY"
}

# ---- connect ----------------------------------------------------------------
banner() {
  hr; info "${B}budget-tracker — sureserver guided installer${R}"
  [ "$DRY" = "1" ] && warn "DRY-RUN: no changes will be made."
  hr
}

connect() {
  ask SERVER "Your server id (e.g. s446, from your welcome email / panel URL)"
  [ -n "${SERVER:-}" ] || die "server id is required"
  ask_secret API_KEY "Control Panel API key (Account Profile -> generate)"
  [ -n "${API_KEY:-}" ] || die "API key is required"
  API_BASE="https://panel.${SERVER}.sureserver.com/api/v1"
  info "Verifying key against ${API_BASE}/account ..."
  DRY_SAVE="$DRY"; DRY=0; api GET /account; DRY="$DRY_SAVE"
  case "$API_CODE" in
    2*) : ;;
    401|403) die "API key rejected (HTTP $API_CODE). Re-generate it in Account Profile." ;;
    *) die "Could not reach the panel API (HTTP $API_CODE): $API_BODY" ;;
  esac
  CPUSER="$(printf '%s' "$API_BODY" | json_get username)"
  [ -n "${CPUSER:-}" ] || ask CPUSER "Your control-panel username (could not auto-detect)"
  ok "Connected as control-panel user: ${B}${CPUSER}${R}"
}

# =============================================================================
# MODE: probe
# =============================================================================
if [ "$MODE" = "probe" ]; then
  banner; connect
  info "Reading PHP versions available ..."
  api GET /php/versions; printf '%s\n' "$API_BODY" | head -c 600; printf '\n'
  ok "Probe complete — your key works."
  exit 0
fi

# =============================================================================
# MODE: teardown  (delete a subdomain + database — undo / test cleanup)
# =============================================================================
if [ "$MODE" = "teardown" ]; then
  banner; connect
  warn "TEARDOWN deletes resources permanently."
  ask SUB "Subdomain label to DELETE (e.g. budget) — blank to skip"
  ask DOMAIN "Domain (e.g. yourdomain.com)"
  ask DBSHORT "Database short name to DELETE (e.g. budget) — blank to skip"
  DBFULL=""; [ -n "${DBSHORT:-}" ] && DBFULL="${CPUSER}_${DBSHORT}"
  echo
  info "Will delete:"; [ -n "${SUB:-}" ] && echo "  - subdomain ${SUB}.${DOMAIN}"; [ -n "${DBFULL:-}" ] && echo "  - database ${DBFULL}"
  confirm "Proceed with deletion?" || die "aborted."
  if [ -n "${SUB:-}" ]; then
    info "Removing cron jobs referencing /www/${SUB}/ ..."
    api GET /crons
    printf '%s' "$API_BODY" | grep -o '"cron":"[^"]*"' | sed 's/^"cron":"//;s/"$//;s/\\\//\//g' | grep "/www/${SUB}/" 2>/dev/null | while IFS= read -r line; do
      [ -z "$line" ] && continue
      api POST /crons/delete "{\"cron\":$(jstr "$line")}" && ok "deleted cron: ${line}" || warn "could not delete cron: ${line}"
    done || true
  fi
  if [ -n "${DBFULL:-}" ]; then
    api POST /databases/8/users/delete "{\"name\":$(jstr "$DBSHORT")}" >/dev/null 2>&1 || true
    api POST /databases/8/delete "{\"database\":$(jstr "$DBFULL")}"; api_ok "databases/delete"; ok "deleted database ${DBFULL}"
  fi
  if [ -n "${SUB:-}" ]; then
    api POST /subdomains/delete "{\"subdomain\":$(jstr "$SUB"),\"domain\":$(jstr "$DOMAIN")}"; api_ok "subdomains/delete"; ok "deleted subdomain ${SUB}.${DOMAIN}"
  fi
  ok "Teardown complete."
  exit 0
fi

# =============================================================================
# Gather inputs (install / finish)
# =============================================================================
banner; connect

ask DOMAIN "Your registered domain (e.g. yourdomain.com)"
ask SUB    "Subdomain label for the app" "budget"
APPHOST="${SUB}.${DOMAIN}"
DOCROOT="/home/${CPUSER}/www/${SUB}"      # absolute path on the server
WWWREL="/www/${SUB}"                      # path as the Files/Databases API expects (relative to home)
info "App will live at ${B}https://${APPHOST}${R}  (web root ${DOCROOT})"

# DB
ask DBSHORT "MySQL database short name" "budget"
ask DBUSER  "MySQL username (kept SHORT, not prefixed)" "budget"
DBFULL="${CPUSER}_${DBSHORT}"             # the host prefixes the DB name, not the user
info "Database name will be ${B}${DBFULL}${R}, user ${B}${DBUSER}${R}"
GEN_DBPASS=""
if [ "$HAVE_OPENSSL" = "1" ] && confirm "Auto-generate a strong DB password?"; then
  GEN_DBPASS="$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)"; DBPASS="$GEN_DBPASS"
  ok "Generated DB password (saved into config.php below): ${B}${DBPASS}${R}"
else
  ask_secret DBPASS "MySQL password for ${DBUSER}"
fi
[ -n "${DBPASS:-}" ] || die "a DB password is required"

PHPVER="83"
CRONHOUR="3"        # daily run hour; the host auto-assigns the minute slot

# Secrets
if [ "$HAVE_OPENSSL" = "1" ]; then
  ENC_KEY="$(openssl rand -base64 32)"; SESS_SECRET="$(openssl rand -hex 32)"
  ok "Generated encryption_key + session_secret."
  warn "BACK UP your encryption_key — losing it means re-linking every bank:"
  printf '    %s\n' "$ENC_KEY"
else
  warn "openssl not found — paste your own secrets."
  ask_secret ENC_KEY "encryption_key (base64 of 32 bytes)"
  ask_secret SESS_SECRET "session_secret (long random hex)"
fi

# Required services
echo; info "${B}Google OAuth${R} (see docs/install/services/google-oauth.md)"
ask GOOGLE_ID  "Google client_id (...apps.googleusercontent.com)"
ask GOOGLE_SECRET "Google client_secret (GOCSPX-...)"
echo; info "${B}Plaid${R} (see docs/install/services/plaid.md)"
ask PLAID_ENV "Plaid env (production|sandbox)" "production"
ask PLAID_ID  "Plaid client_id"
ask_secret PLAID_SECRET "Plaid ${PLAID_ENV} secret"

# allowed emails
echo; ask EMAILS "Allowed sign-in emails (comma-separated)"

# alerts
ask ALERT_TO "Alert/notification recipient email" "${EMAILS%%,*}"
ask ALERT_FROM "Alert 'from' address (on your domain)" "budget@${DOMAIN}"

# optional feeds
echo; info "${B}Optional API keys${R} — press Enter to skip (leaves the feature disabled)."
ask TWELVE "Twelve Data key (security prices)" ""
ask FRED "FRED key (economic data)" ""
ask POLY "Polygon.io key (dividends)" ""
ask RENT "RentCast key (home value — PAID risk)" ""
HOME_ADDR=""; [ -n "${RENT}" ] && ask HOME_ADDR "Home address for RentCast ('Street, City, State, Zip')" ""
ask ANTHRO "Anthropic key (sk-ant-... — OCR + AI assistant, PAID)" ""

# =============================================================================
# Build config.php (locally; uploaded via the Files API or by you)
# =============================================================================
emails_php() { # turn "a@x.com, b@y.com" into PHP array items
  printf '%s' "$1" | tr ',' '\n' | while IFS= read -r e; do
    e="$(printf '%s' "$e" | sed 's/^ *//;s/ *$//')"; [ -z "$e" ] && continue
    printf "        '%s',\n" "$e"
  done
}
build_config() {
  cat <<PHP
<?php
/** Generated by tools/install.sh on $(date -u +%Y-%m-%dT%H:%M:%SZ). DO NOT COMMIT. */
return [
    'db' => [
        'socket'  => '/tmp/mysql8.sock',
        'host'    => '127.0.0.1',
        'port'    => 3308,
        'name'    => '${DBFULL}',
        'user'    => '${DBUSER}',
        'pass'    => '${DBPASS}',
        'charset' => 'utf8mb4',
    ],
    'google' => [
        'client_id'     => '${GOOGLE_ID}',
        'client_secret' => '${GOOGLE_SECRET}',
        'redirect_uri'  => 'https://${APPHOST}/oauth-callback.php',
    ],
    'plaid' => [
        'env'            => '${PLAID_ENV}',
        'client_id'      => '${PLAID_ID}',
        'secret'         => '${PLAID_SECRET}',
        'webhook_url'    => 'https://${APPHOST}/webhook.php',
        'days_requested' => 730,
    ],
    'allowed_emails' => [
$(emails_php "$EMAILS")    ],
    'encryption_key' => '${ENC_KEY}',
    'session_secret' => '${SESS_SECRET}',
    'alerts' => [
        'recipients'         => ['${ALERT_TO}'],
        'large_tx_threshold' => 200.0,
        'from'               => '${ALERT_FROM}',
    ],
    'storage' => [
        'manual_dir' => '${DOCROOT}/storage/manual',
    ],
    'pdftotext' => '/usr/bin/pdftotext',
    'twelvedata' => ['api_key' => '${TWELVE}'],
    'fred'       => ['api_key' => '${FRED}'],
    'polygon'    => ['api_key' => '${POLY}'],
    'rentcast'   => ['api_key' => '${RENT}'],
    'anthropic'  => [
        'api_key'         => '${ANTHRO}',
        'model'           => 'claude-sonnet-4-6',
        'assistant_model' => '',
    ],
    'home' => ['address' => '${HOME_ADDR}'],
];
PHP
}

LOCAL_CONFIG="./config.generated.php"
build_config > "$LOCAL_CONFIG"
ok "Wrote a local copy of your config to ${B}${LOCAL_CONFIG}${R} (git-ignored name; keep it private)."

# =============================================================================
# Confirm plan
# =============================================================================
echo; hr; info "${B}Plan${R}"
cat <<PLAN
  server        panel.${SERVER}.sureserver.com  (user ${CPUSER})
  subdomain     ${APPHOST}        -> ${DOCROOT}
  database      ${DBFULL}  + user ${DBUSER}  (MySQL 8)
  PHP           ${PHPVER} / FPM
  config.php    written to ${WWWREL}/lib/config.php (Files API)
  schema        imported from ${WWWREL}/lib/schema.sql (if present)
  cron          daily 03:00 -> php83.cli ${DOCROOT}/cron/sync.php
PLAN
hr
[ "$DRY" = "1" ] || confirm "Proceed?" || die "aborted by user."

# =============================================================================
# Execute (skip provisioning entirely in --finish mode)
# =============================================================================
if [ "$MODE" != "finish" ]; then
  info "1/6  Creating subdomain ${APPHOST} ..."
  api POST /subdomains/create "{\"subdomain\":$(jstr "$SUB"),\"domain\":$(jstr "$DOMAIN")}"; api_ok "subdomains/create"; ok "subdomain created"

  info "2/6  Creating MySQL 8 database ${DBFULL} ..."
  # NOTE: databases/8/create can return a spurious error while still creating the
  # DB, so don't trust the HTTP code — create, then VERIFY via GET.
  api POST /databases/8/create "{\"name\":$(jstr "$DBSHORT"),\"collation\":\"utf8mb4_general_ci\"}" || true
  api GET /databases/8
  if printf '%s' "$API_BODY" | grep -q "\"${DBFULL}\""; then
    ok "database ${DBFULL} present"
  else
    die "database ${DBFULL} was not created. Response: $API_BODY"
  fi
  info "      Creating user ${DBUSER} + granting privileges ..."
  api POST /databases/8/users/create "{\"name\":$(jstr "$DBUSER"),\"database\":$(jstr "$DBFULL"),\"password\":$(jstr "$DBPASS"),\"password_confirmation\":$(jstr "$DBPASS"),\"remote\":\"0\"}"; api_ok "databases/users/create"; ok "user ${DBUSER} created"
  api POST /databases/8/privileges "{\"user\":$(jstr "$DBUSER"),\"database\":$(jstr "$DBFULL"),\"privileges\":[\"SELECT\",\"INSERT\",\"UPDATE\",\"DELETE\",\"CREATE\",\"ALTER\",\"INDEX\",\"DROP\",\"REFERENCES\",\"LOCK TABLES\",\"CREATE TEMPORARY TABLES\"]}"; api_ok "databases/privileges"; ok "privileges granted"

  info "3/6  Ensuring PHP ${PHPVER} (FPM) on ${APPHOST} ..."
  # Best-effort: new subdomains on this host already default to PHP 8.3/FPM, and the
  # php-settings-save endpoint is finicky — never fail the whole install on it.
  api POST "/php/settings/save/${APPHOST}" "{\"settings\":{\"www\":{\"php_handler\":\"fpm\",\"php_version\":\"${PHPVER}\"}}}" || true
  case "${API_CODE:-0}" in
    2*) ok "PHP set to ${PHPVER}/FPM" ;;
    *)  warn "Could not set PHP via the API — new subdomains usually already default to PHP 8.3/FPM."
        warn "If needed, set PHP ${PHPVER} + FPM for ${APPHOST} in the panel (PHP Settings)." ;;
  esac
fi

# config.php: needs lib/ to exist from the code upload. write-file only OVERWRITES
# an existing file, so create-file first.
info "4/6  Writing config.php to the server ..."
CONFIG_CONTENT="$(build_config)"
api POST /files/create-file "{\"dir\":$(jstr "${WWWREL}/lib"),\"name\":\"config.php\"}" || true
CF_CREATE="${API_CODE:-0}"
api POST /files/write-file "{\"file\":$(jstr "${WWWREL}/lib/config.php"),\"content\":$(jstr "$CONFIG_CONTENT")}" || true
case "${API_CODE:-0}" in
  2*) ok "config.php written to ${WWWREL}/lib/config.php" ;;
  *)  warn "Could not write config.php via the API (create=${CF_CREATE}, write=${API_CODE:-?})."
      warn "This is normal if the code (the lib/ folder) isn't uploaded yet."
      warn "Upload the app (docs/install/20-upload-code.md), then upload ${LOCAL_CONFIG} as lib/config.php, OR re-run: ./tools/install.sh --finish" ;;
esac

# schema import (needs lib/schema.sql on the server). IMPORTANT: the 'collation'
# field here is passed to mysql as --default-character-set, so it must be a CHARSET
# (utf8mb4), NOT a collation (utf8mb4_general_ci). Import runs as an async task.
info "5/6  Importing the database schema ..."
api POST /databases/8/import "{\"database\":$(jstr "$DBFULL"),\"collation\":\"utf8mb4\",\"file_path\":$(jstr "${WWWREL}/lib/schema.sql")}" || true
case "${API_CODE:-0}" in
  2*) TID="$(printf '%s' "$API_BODY" | sed -n 's/.*"task_id":\([0-9]*\).*/\1/p')"
      if [ -n "${TID:-}" ] && [ "$DRY" != "1" ]; then
        info "      import enqueued (task ${TID}); waiting ..."
        i=0; while [ "$i" -lt 10 ]; do
          sleep 2; api GET "/tasks/details?id=${TID}"
          ST="$(printf '%s' "$API_BODY" | sed -n 's/.*"status":"\([a-z]*\)".*/\1/p')"
          case "$ST" in finished) ok "schema imported (task finished)"; break ;;
                        failed) warn "schema import task FAILED: $(printf '%s' "$API_BODY" | sed -n 's/.*"result":"\([^"]*\)".*/\1/p')"; break ;;
          esac; i=$((i+1))
        done
      else ok "schema import enqueued" ; fi ;;
  *)  warn "Schema import failed (HTTP ${API_CODE:-?}) — usually because the code (lib/schema.sql) isn't uploaded yet."
      warn "Upload the code, then re-run: ./tools/install.sh --finish  (or import via phpMyAdmin — docs/install/40-database-schema.md)" ;;
esac

if [ "$MODE" != "finish" ]; then
  info "6/6  Creating the nightly cron job ..."
  # NOTE: this host restricts cron minutes to fixed slots and rejects minute "0"
  # (crons.invalid_time). Use the step form "*/60"; the panel assigns the slot.
  CRON_CMD="/usr/local/bin/php83.cli ${DOCROOT}/cron/sync.php >> ${DOCROOT}/storage/cron.log 2>&1"
  api POST /crons/create "{\"command\":$(jstr "$CRON_CMD"),\"minute\":\"*/60\",\"hour\":$(jstr "$CRONHOUR"),\"day\":\"*\",\"month\":\"*\",\"weekday\":\"*\"}"; api_ok "crons/create"; ok "cron scheduled (daily ~0${CRONHOUR}:00)"
fi

echo; hr; ok "${B}Done.${R}"
cat <<NEXT

Next steps (the installer can't do these for you):
  1. Upload the app code if you haven't:  docs/install/20-upload-code.md
     (then, if config/schema steps above warned, re-run: ./tools/install.sh --finish)
  2. Make sure your Google redirect URI = https://${APPHOST}/oauth-callback.php
     and your Plaid webhook = https://${APPHOST}/webhook.php
  3. Visit https://${APPHOST} , sign in, link a bank:  docs/install/60-verify.md

Your generated config is also saved locally at ${LOCAL_CONFIG} (keep it private; do not commit).
NEXT

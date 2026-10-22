#!/usr/bin/env bash
#
# End-to-end tests of jamfsync in a throw-away GLPI 12 environment (Docker):
# fresh install, takeover of existing computers, entity rule + sync against a JAMF mock,
# retry, missing devices, notification, retention, school change, access/CSRF checks,
# uninstall and the upgrade from 0.5.1.
#
# Requirements: docker, curl, python3. Nothing outside the created containers is touched.
#
#   tests/e2e/run.sh                 # run everything, remove the environment afterwards
#   E2E_KEEP=1 tests/e2e/run.sh      # keep containers for inspection (http://127.0.0.1:$E2E_PORT)
#
# Environment: GLPI_IMAGE (glpi/glpi:12.0.0), DB_IMAGE (mariadb:11.8), E2E_PREFIX
# (jamfsync-e2e), E2E_PORT (8091), E2E_KEEP (0).

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/../.." && pwd)
FIXTURES="$PLUGIN_DIR/tests/e2e/fixtures"
GLPI_IMAGE=${GLPI_IMAGE:-glpi/glpi:12.0.0}
DB_IMAGE=${DB_IMAGE:-mariadb:11.8}
PREFIX=${E2E_PREFIX:-jamfsync-e2e}
PORT=${E2E_PORT:-8091}
KEEP=${E2E_KEEP:-0}
BASE="http://127.0.0.1:$PORT"
MOCK_HOST="$PREFIX-mock"
STATE=$(mktemp -d)
JAR="$STATE/cookies.txt"
VERSION=$(sed -n "s/^define('PLUGIN_JAMFSYNC_VERSION', '\([^']*\)');/\1/p" "$PLUGIN_DIR/setup.php")
FAILURES=0

cleanup() {
    if [ "$KEEP" = "1" ]; then
        echo "Environment kept: $BASE (glpi/glpi), mock state in $STATE"
        return
    fi
    docker rm -f -v "$PREFIX-app" "$PREFIX-db" "$PREFIX-mock" >/dev/null 2>&1 || true
    docker network rm "$PREFIX" >/dev/null 2>&1 || true
    rm -rf "$STATE"
}
trap cleanup EXIT

pass() { printf '  \033[32mok\033[0m   %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAILURES=$((FAILURES + 1)); }
section() { printf '\n== %s\n' "$1"; }
assert_eq() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1 (expected '$3', got '$2')"; fi; }
assert_contains() { if printf '%s' "$2" | grep -qF -- "$3"; then pass "$1"; else fail "$1 (no '$3' in: ${2:0:300})"; fi; }
assert_nonempty() { if [ -n "$2" ]; then pass "$1"; else fail "$1 (empty)"; fi; }

sql() { docker exec "$PREFIX-db" mariadb -N -uglpi -pglpi glpi -e "$1"; }
console() { docker exec -u www-data "$PREFIX-app" php bin/console "$@" --no-interaction; }
json() { python3 -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {'d': d}))" "$1"; }

login() {
    rm -f "$JAR"
    curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/index.php"
    curl -s -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/front/login.php" \
        --data-urlencode "login_name=$1" --data-urlencode "login_password=$2" --data-urlencode auth=local
}
# Browser-like same-origin request with the current session.
http() { curl -s -c "$JAR" -b "$JAR" -H 'Sec-Fetch-Site: same-origin' "$@"; }

wait_for_job() {
    local id=$1 status=''
    for _ in $(seq 1 60); do
        status=$(http "$BASE/plugins/jamfsync/Sync/Jobs/$id" | json "d['job']['status']" 2>/dev/null || true)
        case "$status" in success|error|stalled) break ;; esac
        sleep 1
    done
    echo "$status"
}

start_sync() { # start_sync <full 0|1> [instance id, default all] -> job id
    http -X POST "$BASE/plugins/jamfsync/Sync/Start" --data "instance_id=${2:-0}&full=$1" | json "d['job_id']" 2>/dev/null || echo 0
}

log_lines() { docker exec "$PREFIX-app" sh -c 'cat /var/glpi/logs/php-errors.log 2>/dev/null | wc -l'; }
# New php-errors.log entries mentioning the plugin, except the expected "version changed" notice.
plugin_log_entries() {
    docker exec "$PREFIX-app" sh -c "tail -n +$(($1 + 1)) /var/glpi/logs/php-errors.log 2>/dev/null" \
        | awk '/^\[/{if (b) print b; b=$0; next} {b=b"\n"$0} END{if (b) print b}' \
        | grep -i 'jamfsync' | grep -v 'version changed' || true
}

section "Environment ($GLPI_IMAGE, plugin $VERSION)"
cp "$FIXTURES/devices.json" "$STATE/devices.json"
: > "$STATE/requests.log"
docker network create "$PREFIX" >/dev/null
docker run -d --name "$PREFIX-db" --network "$PREFIX" -e MARIADB_RANDOM_ROOT_PASSWORD=1 \
    -e MARIADB_DATABASE=glpi -e MARIADB_USER=glpi -e MARIADB_PASSWORD=glpi "$DB_IMAGE" >/dev/null
docker run -d --name "$PREFIX-mock" --network "$PREFIX" -u "$(id -u):$(id -g)" --entrypoint php \
    -v "$FIXTURES:/mock:ro" -v "$STATE:/mock-state" "$GLPI_IMAGE" -S 0.0.0.0:8080 /mock/mock-router.php >/dev/null
for _ in $(seq 1 30); do sql 'SELECT 1' >/dev/null 2>&1 && break; sleep 2; done
docker run -d --name "$PREFIX-app" --network "$PREFIX" -p "127.0.0.1:$PORT:80" \
    -e GLPI_DB_HOST="$PREFIX-db" -e GLPI_DB_PORT=3306 -e GLPI_DB_NAME=glpi -e GLPI_DB_USER=glpi -e GLPI_DB_PASSWORD=glpi \
    -v "$PLUGIN_DIR:/var/www/glpi/plugins/jamfsync:ro" \
    -v "$FIXTURES/local_define.php:/var/www/glpi/config/local_define.php:ro" "$GLPI_IMAGE" >/dev/null
for _ in $(seq 1 60); do [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")" = "200" ] && break; sleep 5; done
pass "GLPI is up at $BASE"
# Build the route cache while the plugin is inactive: activation must still expose the routes.
curl -s -o /dev/null -X POST "$BASE/plugins/jamfsync/Sync/Start"

section "Fresh install"
LOG0=$(log_lines)
console plugin:install jamfsync --username=glpi >/dev/null
console plugin:activate jamfsync >/dev/null
assert_eq "schema version" "$(sql "SELECT value FROM glpi_configs WHERE context='plugin:jamfsync' AND name='schema_version'")" "$VERSION"
assert_eq "no DATETIME columns" "$(sql "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='glpi' AND table_name LIKE 'glpi_plugin_jamfsync%' AND data_type='datetime'")" "0"
assert_eq "automatic actions" "$(sql "SELECT GROUP_CONCAT(name ORDER BY name) FROM glpi_crontasks WHERE itemtype='GlpiPlugin\\\\Jamfsync\\\\SyncTask'")" "ProcessSyncQueue,PullFromJamf,PurgeHistory"
assert_eq "right for Super-Admin" "$(sql "SELECT rights FROM glpi_profilerights WHERE name='plugin_jamfsync_config' AND profiles_id=4")" "3"
assert_eq "retention defaults" "$(sql "SELECT GROUP_CONCAT(CONCAT(name,'=',value) ORDER BY name) FROM glpi_configs WHERE context='plugin:jamfsync' AND name LIKE 'retention_%'")" "retention_missing_devicemaps_days=0,retention_runchanges_days=90,retention_syncruns_days=365"
assert_eq "purge action (daily)" "$(sql "SELECT frequency FROM glpi_crontasks WHERE itemtype='GlpiPlugin\\\\Jamfsync\\\\SyncTask' AND name='PurgeHistory'")" "86400"
assert_eq "failure notification" "$(sql "SELECT COUNT(*) FROM glpi_notifications WHERE itemtype='GlpiPlugin\\\\Jamfsync\\\\SyncRun' AND event='sync_failed' AND is_active=1")" "1"
assert_eq "no plugin entries in php-errors.log" "$(plugin_log_entries "$LOG0")" ""

section "Instance with target entity, connection test, sync"
login glpi glpi
http -o /dev/null -X POST "$BASE/front/entity.form.php" --data "add=1&name=E2E+School&entities_id=0"
ENTITY=$(sql "SELECT id FROM glpi_entities WHERE name='E2E School'")
assert_nonempty "test entity created" "$ENTITY"
http -o /dev/null -X POST "$BASE/plugins/jamfsync/front/instance.form.php" --data \
    "add=1&name=E2E+Mock&jamf_base_url=http://$MOCK_HOST:8080/api&jamf_api_username=mock-user&jamf_api_key=mock-key&entities_id=$ENTITY&manage_entity_rule=1&inventory_tag=&name_source=device_name&pull_devices=1&pull_apps=1&is_active=1&verify_tls=1&request_timeout=30&page_size=200"
INSTANCE=$(sql "SELECT id FROM glpi_plugin_jamfsync_instances WHERE name='E2E Mock'")
assert_eq "base URL normalised" "$(sql "SELECT jamf_base_url FROM glpi_plugin_jamfsync_instances WHERE id=$INSTANCE")" "http://$MOCK_HOST:8080"
assert_eq "API key encrypted" "$(sql "SELECT jamf_api_key_is_encrypted FROM glpi_plugin_jamfsync_instances WHERE id=$INSTANCE")" "1"
RULE=$(sql "SELECT rules_id FROM glpi_plugin_jamfsync_instances WHERE id=$INSTANCE")
assert_eq "managed rule is first entity rule" "$(sql "SELECT id FROM glpi_rules WHERE sub_type='RuleImportEntity' ORDER BY ranking LIMIT 1")" "$RULE"
assert_eq "rule criterion" "$(sql "SELECT CONCAT(criteria,'=',pattern) FROM glpi_rulecriterias WHERE rules_id=$RULE")" "tag=jamfsync-$INSTANCE"
assert_eq "rule action" "$(sql "SELECT CONCAT(field,'=',value) FROM glpi_ruleactions WHERE rules_id=$RULE")" "entities_id=$ENTITY"
assert_eq "import rules are the first asset rules" "$(sql "SELECT GROUP_CONCAT(uuid ORDER BY ranking) FROM (SELECT uuid, ranking FROM glpi_rules WHERE sub_type='RuleImportAsset' ORDER BY ranking LIMIT 2) r")" "jamfsync-$INSTANCE-import-link,jamfsync-$INSTANCE-import-new"
http -o /dev/null -X POST "$BASE/plugins/jamfsync/front/instance.form.php" --data "update=1&id=$INSTANCE&manage_entity_rule=0"
assert_eq "rule removed when switched off" "$(sql "SELECT CONCAT(rules_id,'/',(SELECT COUNT(*) FROM glpi_rules WHERE id=$RULE)) FROM glpi_plugin_jamfsync_instances WHERE id=$INSTANCE")" "0/0"
http -o /dev/null -X POST "$BASE/plugins/jamfsync/front/instance.form.php" --data "update=1&id=$INSTANCE&manage_entity_rule=1"
RULE=$(sql "SELECT rules_id FROM glpi_plugin_jamfsync_instances WHERE id=$INSTANCE")
assert_eq "rule recreated when switched on" "$(sql "SELECT COUNT(*) FROM glpi_rulecriterias WHERE rules_id=$RULE AND pattern='jamfsync-$INSTANCE'")" "1"

TEST=$(http -X POST "$BASE/plugins/jamfsync/Instances/TestConnection" --data "id=$INSTANCE&jamf_base_url=http://$MOCK_HOST:8080&jamf_api_username=mock-user&jamf_api_key=&verify_tls=1&request_timeout=30")
assert_eq "connection test with stored key" "$(printf '%s' "$TEST" | json "d['ok']")" "True"
assert_contains "connection test reports device count" "$TEST" "2 devices"
TEST=$(http -X POST "$BASE/plugins/jamfsync/Instances/TestConnection" --data "jamf_base_url=http://$MOCK_HOST:8080&jamf_api_username=mock-user&jamf_api_key=")
assert_contains "connection test without credentials fails" "$TEST" "credentials are incomplete"

# iPads already in GLPI (data import or another plugin): one with the serial of 9001,
# two with the serial of 9002. Automatic entity transfer as recommended for production.
sql "UPDATE glpi_entities SET transfers_id=1 WHERE id=0"
http -o /dev/null -X POST "$BASE/front/computer.form.php" --data "add=1&name=Altgeraet+01&serial=MOCKSER9001&entities_id=0"
http -o /dev/null -X POST "$BASE/front/computer.form.php" --data "add=1&name=Dublette+A&serial=MOCKSER9002&entities_id=0"
http -o /dev/null -X POST "$BASE/front/computer.form.php" --data "add=1&name=Dublette+B&serial=MOCKSER9002&entities_id=0"
PREVIEW=$(http -X POST "$BASE/plugins/jamfsync/Instances/$INSTANCE/TakeoverPreview")
assert_eq "takeover check: synced/adopt/ambiguous/new" "$(printf '%s' "$PREVIEW" | json "'/'.join(str(d['counts'][k]) for k in ('synced', 'adopt', 'ambiguous', 'new'))")" "0/1/1/0"
assert_contains "takeover check lists the existing computer" "$PREVIEW" "Altgeraet 01"
assert_contains "takeover check lists the ambiguous serial" "$PREVIEW" "Dublette B"

JOB=$(start_sync 0)
assert_eq "manual sync" "$(wait_for_job "$JOB")" "success"
MSG=$(sql "SELECT message FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")
assert_contains "takeover noted in run" "$MSG" "1 existing computer was taken over."
assert_contains "ambiguous device noted in run" "$MSG" "1 device was skipped"
assert_eq "existing computer taken over, no duplicate" "$(sql "SELECT CONCAT(COUNT(*),'/',MIN(name)) FROM glpi_computers WHERE serial='MOCKSER9001' AND is_deleted=0")" "1/Test iPad 01"
assert_eq "ambiguous serial left alone" "$(sql "SELECT COUNT(*) FROM glpi_computers WHERE serial='MOCKSER9002'")/$(sql "SELECT COUNT(*) FROM glpi_agents WHERE deviceid='jamfsync-$INSTANCE-9002'")" "2/0"
http -o /dev/null -X POST "$BASE/front/computer.form.php" --data "purge=1&id=$(sql "SELECT MAX(id) FROM glpi_computers WHERE serial='MOCKSER9002'")"
JOB=$(start_sync 0)
assert_eq "sync after merging the duplicates" "$(wait_for_job "$JOB")" "success"
assert_eq "remaining computer taken over" "$(sql "SELECT COUNT(*) FROM glpi_computers WHERE serial='MOCKSER9002' AND is_deleted=0")/$(sql "SELECT COUNT(*) FROM glpi_agents WHERE deviceid='jamfsync-$INSTANCE-9002'")" "1/1"
assert_eq "computers in target entity" "$(sql "SELECT COUNT(*) FROM glpi_computers WHERE serial LIKE 'MOCKSER%' AND entities_id=$ENTITY")" "2"
assert_eq "inventory tag on agents" "$(sql "SELECT DISTINCT tag FROM glpi_agents WHERE deviceid LIKE 'jamfsync-$INSTANCE-%'")" "jamfsync-$INSTANCE"
assert_eq "software imported" "$(sql "SELECT COUNT(*) FROM glpi_items_softwareversions isv JOIN glpi_computers c ON c.id=isv.items_id AND isv.itemtype='Computer' WHERE c.serial LIKE 'MOCKSER%'")" "5"
JOB=$(start_sync 0)
assert_eq "second run" "$(wait_for_job "$JOB")" "success"
assert_eq "second run changes nothing" "$(sql "SELECT changed_count FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")" "0"

section "Devices sharing a name in JAMF"
# A third iPad with the name of the first one: GLPI's default rule "Computer update (by
# name)" would merge it into the first computer; the managed import rules must not.
python3 -c "import json,sys; p=sys.argv[1]; d=json.load(open(p)); n=dict(d[0], id=9003, udid='MOCK-UDID-9003', serialNumber='MOCKSER9003', wifiMac='02aabbcc0003'); json.dump(d+[n], open(p,'w'))" "$STATE/devices.json"
JOB=$(start_sync 0)
assert_eq "sync with a duplicate name" "$(wait_for_job "$JOB")" "success"
assert_eq "only the new device counts as changed" "$(sql "SELECT changed_count FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")" "1"
assert_eq "one computer per serial" "$(sql "SELECT COUNT(DISTINCT id) FROM glpi_computers WHERE serial IN ('MOCKSER9001','MOCKSER9003')")" "2"
assert_eq "serials not overwritten" "$(sql "SELECT GROUP_CONCAT(c.serial ORDER BY m.jamf_device_id) FROM glpi_plugin_jamfsync_devicemaps m JOIN glpi_computers c ON c.id=m.computers_id WHERE m.jamf_device_id IN ('9001','9003')")" "MOCKSER9001,MOCKSER9003"
JOB=$(start_sync 1)
assert_eq "full resync keeps them apart" "$(wait_for_job "$JOB")/$(sql "SELECT COUNT(DISTINCT computers_id) FROM glpi_plugin_jamfsync_devicemaps WHERE jamf_device_id IN ('9001','9003')")" "success/2"
assert_eq "full resync without changes counts none" "$(sql "SELECT CONCAT(processed_count,'/',changed_count) FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")" "3/0"

section "Retry on HTTP 503 / 429"
echo "503 2" > "$STATE/fail"
JOB=$(start_sync 1)
assert_eq "sync after two 503 answers" "$(wait_for_job "$JOB")" "success"
assert_contains "retries logged in run" "$(sql "SELECT message FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")" "2 JAMF requests were retried."
echo "429 1" > "$STATE/fail"
JOB=$(start_sync 0)
assert_eq "sync after a 429 answer" "$(wait_for_job "$JOB")" "success"
echo "401 1" > "$STATE/fail"
JOB=$(start_sync 0)
assert_eq "HTTP 401 is not retried" "$(wait_for_job "$JOB")" "error"
assert_contains "HTTP 401 message" "$(sql "SELECT message FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")" "HTTP 401."

section "Devices no longer returned by JAMF"
python3 -c "import json,sys; p=sys.argv[1]; d=json.load(open(p)); json.dump(d[:1], open(p,'w'))" "$STATE/devices.json"
JOB=$(start_sync 0)
assert_eq "sync with one device less" "$(wait_for_job "$JOB")" "success"
PAGE=$(http "$BASE/plugins/jamfsync/front/monitoring.php")
assert_contains "monitoring lists the missing device" "$PAGE" "MOCKSER9002"
assert_contains "missing devices card" "$PAGE" "Devices no longer returned by JAMF"

section "Notification on failed scheduled sync"
sql "UPDATE glpi_configs SET value='1' WHERE context='core' AND name IN ('use_notifications','notifications_mailing'); UPDATE glpi_configs SET value='admin@example.invalid' WHERE context='core' AND name='admin_email';"
echo "401 2" > "$STATE/fail"
docker exec -u www-data "$PREFIX-app" php front/cron.php --force PullFromJamf >/dev/null
assert_eq "scheduled run failed" "$(sql "SELECT CONCAT(mode,'/',status) FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")" "cron/error"
assert_eq "notification queued" "$(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE itemtype='GlpiPlugin\\\\Jamfsync\\\\SyncRun'")" "1"
docker exec -u www-data "$PREFIX-app" php front/cron.php --force PullFromJamf >/dev/null
assert_eq "repeated failure is not notified again" "$(sql "SELECT COUNT(*) FROM glpi_queuednotifications WHERE itemtype='GlpiPlugin\\\\Jamfsync\\\\SyncRun'")" "1"

section "Retention"
# Data and Privacy asks for the password again (GLPI 12 re-authentication).
http -o /dev/null "$BASE/front/config/dataandprivacyconfig.form.php"
http -o /dev/null -X POST "$BASE/ReAuth/Verify" --data-urlencode "user_input=glpi"
PAGE=$(http "$BASE/front/config/dataandprivacyconfig.form.php")
assert_contains "tab on Data and Privacy" "$PAGE" "PrivacyTab"
REDIRECT=$(http -o /dev/null -w '%{redirect_url}' -X POST "$BASE/plugins/jamfsync/Retention" \
    --data "retention_runchanges_days=30&retention_syncruns_days=200&retention_missing_devicemaps_days=-5")
assert_contains "save redirects back to the tab" "$REDIRECT" "forcetab=GlpiPlugin"
assert_eq "periods saved and validated" "$(sql "SELECT GROUP_CONCAT(value ORDER BY name) FROM glpi_configs WHERE context='plugin:jamfsync' AND name LIKE 'retention_%'")" "0,30,200"
http -o /dev/null -X POST "$BASE/plugins/jamfsync/Retention" --data "retention_runchanges_days=30&retention_syncruns_days=200&retention_missing_devicemaps_days=100"
old() { date -d "-$1 days" '+%Y-%m-%d %H:%M:%S'; }
sql "INSERT INTO glpi_plugin_jamfsync_syncruns (id, jamf_instances_id, instance_name, status, mode, date_creation, started_at) VALUES (90001, $INSTANCE, 'old', 'success', 'cron', '$(old 250)', '$(old 250)'), (90002, $INSTANCE, 'recent', 'success', 'cron', '$(old 10)', '$(old 10)');
     INSERT INTO glpi_plugin_jamfsync_runchanges (syncruns_id, jamf_instances_id, change_type, date_creation) VALUES (90001, $INSTANCE, 'device', '$(old 250)'), (90002, $INSTANCE, 'device', '$(old 40)'), (90002, $INSTANCE, 'device', '$(old 5)');
     INSERT INTO glpi_plugin_jamfsync_syncjobs (id, status, mode, date_creation) VALUES (90001, 'success', 'manual', '$(old 40)'), (90002, 'queued', 'manual', '$(old 40)'), (90003, 'error', 'manual', '$(old 5)');
     INSERT INTO glpi_plugin_jamfsync_devicemaps (jamf_instances_id, jamf_device_id, last_seen_at) VALUES ($INSTANCE, 'gone-long-ago', '$(old 150)');"
docker exec -u www-data "$PREFIX-app" php front/cron.php --force PurgeHistory >/dev/null
assert_eq "old run and its changes purged" "$(sql "SELECT COUNT(*) FROM glpi_plugin_jamfsync_syncruns WHERE id=90001")/$(sql "SELECT COUNT(*) FROM glpi_plugin_jamfsync_runchanges WHERE syncruns_id=90001")" "0/0"
assert_eq "recent run kept, old change details purged" "$(sql "SELECT COUNT(*) FROM glpi_plugin_jamfsync_syncruns WHERE id=90002")/$(sql "SELECT COUNT(*) FROM glpi_plugin_jamfsync_runchanges WHERE syncruns_id=90002")" "1/1"
assert_eq "finished old job purged, queued and recent kept" "$(sql "SELECT GROUP_CONCAT(id ORDER BY id) FROM glpi_plugin_jamfsync_syncjobs WHERE id>=90001")" "90002,90003"
assert_eq "long-missing device mapping purged" "$(sql "SELECT COUNT(*) FROM glpi_plugin_jamfsync_devicemaps WHERE jamf_device_id='gone-long-ago'")" "0"
assert_eq "current device mappings kept" "$(sql "SELECT COUNT(*) FROM glpi_plugin_jamfsync_devicemaps WHERE jamf_instances_id=$INSTANCE")" "3"
assert_contains "periods shown in monitoring" "$(http "$BASE/plugins/jamfsync/front/monitoring.php")" "runs 200 days"
# The synthetic queued job must not be picked up by the worker in later sections.
sql "DELETE FROM glpi_plugin_jamfsync_syncjobs WHERE id>=90001"

section "Device changes school (second instance)"
# The mock now returns only 9001; a second school's instance receives the same iPad.
http -o /dev/null -X POST "$BASE/front/entity.form.php" --data "add=1&name=E2E+School+2&entities_id=0"
ENTITY2=$(sql "SELECT id FROM glpi_entities WHERE name='E2E School 2'")
http -o /dev/null -X POST "$BASE/plugins/jamfsync/front/instance.form.php" --data \
    "add=1&name=E2E+Mock+2&jamf_base_url=http://$MOCK_HOST:8080&jamf_api_username=mock-user&jamf_api_key=mock-key&entities_id=$ENTITY2&manage_entity_rule=1&inventory_tag=&name_source=device_name&pull_devices=1&pull_apps=1&is_active=1&verify_tls=1&request_timeout=30&page_size=200"
INSTANCE2=$(sql "SELECT id FROM glpi_plugin_jamfsync_instances WHERE name='E2E Mock 2'")
COMPUTER=$(sql "SELECT computers_id FROM glpi_plugin_jamfsync_devicemaps WHERE jamf_instances_id=$INSTANCE AND jamf_device_id='9001'")
JOB=$(start_sync 0 "$INSTANCE2")
assert_eq "sync of the new school" "$(wait_for_job "$JOB")" "success"
assert_eq "same computer, no duplicate" "$(sql "SELECT computers_id FROM glpi_plugin_jamfsync_devicemaps WHERE jamf_instances_id=$INSTANCE2 AND jamf_device_id='9001'")/$(sql "SELECT COUNT(*) FROM glpi_computers WHERE serial='MOCKSER9001' AND is_deleted=0")" "$COMPUTER/1"
assert_eq "computer moved to the new school's entity" "$(sql "SELECT entities_id FROM glpi_computers WHERE id=$COMPUTER")" "$ENTITY2"
assert_eq "entity move counts as change" "$(sql "SELECT changed_count FROM glpi_plugin_jamfsync_syncruns ORDER BY id DESC LIMIT 1")" "1"
http -o /dev/null -X POST "$BASE/plugins/jamfsync/front/instance.form.php" --data "purge=1&id=$INSTANCE2"
assert_eq "second instance and its rules removed" "$(sql "SELECT COUNT(*) FROM glpi_plugin_jamfsync_instances WHERE id=$INSTANCE2")/$(sql "SELECT COUNT(*) FROM glpi_rules WHERE uuid LIKE 'jamfsync-$INSTANCE2-%' OR name LIKE 'JAMF Sync: E2E Mock 2%'")" "0/0"

section "Access and CSRF"
assert_eq "cross-site POST rejected" "$(curl -s -o /dev/null -w '%{http_code}' -c "$JAR" -b "$JAR" -H 'Sec-Fetch-Site: cross-site' -X POST "$BASE/plugins/jamfsync/Sync/Start" --data 'instance_id=0')" "403"
login normal normal
assert_eq "user without right: start" "$(http -o /dev/null -w '%{http_code}' -X POST "$BASE/plugins/jamfsync/Sync/Start" --data 'instance_id=0')" "403"
assert_eq "user without right: monitoring" "$(http -o /dev/null -w '%{http_code}' "$BASE/plugins/jamfsync/front/monitoring.php")" "403"
assert_eq "user without config right: retention" "$(http -o /dev/null -w '%{http_code}' -X POST "$BASE/plugins/jamfsync/Retention" --data 'retention_runchanges_days=1')" "403"
assert_eq "user without right: connection test" "$(http -o /dev/null -w '%{http_code}' -X POST "$BASE/plugins/jamfsync/Instances/TestConnection")" "403"
assert_eq "no plugin entries in php-errors.log" "$(plugin_log_entries "$LOG0")" ""

section "Uninstall"
console plugin:uninstall jamfsync >/dev/null
assert_eq "tables removed" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='glpi' AND table_name LIKE 'glpi_plugin_jamfsync%'")" "0"
assert_eq "automatic actions removed" "$(sql "SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype LIKE '%Jamfsync%'")" "0"
assert_eq "profile right removed" "$(sql "SELECT COUNT(*) FROM glpi_profilerights WHERE name='plugin_jamfsync_config'")" "0"
assert_eq "managed rules removed" "$(sql "SELECT COUNT(*) FROM glpi_rules WHERE name LIKE 'JAMF Sync:%' OR uuid LIKE 'jamfsync-%'")" "0"
assert_eq "notification removed" "$(sql "SELECT COUNT(*) FROM glpi_notifications WHERE itemtype LIKE '%Jamfsync%'") $(sql "SELECT COUNT(*) FROM glpi_notificationtemplates WHERE itemtype LIKE '%Jamfsync%'")" "0 0"
assert_eq "configuration removed" "$(sql "SELECT COUNT(*) FROM glpi_configs WHERE context='plugin:jamfsync'")" "0"

section "Upgrade from 0.5.1"
sql "DELETE FROM glpi_plugins WHERE directory='jamfsync'"
docker exec -i "$PREFIX-db" mariadb -uglpi -pglpi glpi < "$FIXTURES/schema-0.5.1.sql"
sed "s/MOCK_HOST/$MOCK_HOST/" "$FIXTURES/seed-0.5.1.sql" | docker exec -i "$PREFIX-db" mariadb -uglpi -pglpi glpi
LOG0=$(log_lines)
console plugin:install jamfsync --username=glpi >/dev/null
console plugin:activate jamfsync >/dev/null
assert_eq "schema version after upgrade" "$(sql "SELECT value FROM glpi_configs WHERE context='plugin:jamfsync' AND name='schema_version'")" "$VERSION"
assert_eq "DATETIME columns converted" "$(sql "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='glpi' AND table_name LIKE 'glpi_plugin_jamfsync%' AND data_type='datetime'")" "0"
assert_eq "run history kept" "$(sql "SELECT CONCAT(started_at,'|',finished_at) FROM glpi_plugin_jamfsync_syncruns")" "2026-10-01 08:00:00|2026-10-01 08:05:00"
assert_eq "cron settings kept (2 h, disabled, CLI)" "$(sql "SELECT CONCAT(frequency,'/',state,'/',mode) FROM glpi_crontasks WHERE itemtype='GlpiPlugin\\\\Jamfsync\\\\SyncTask' AND name='PullFromJamf'")" "7200/0/2"
assert_eq "queue action added" "$(sql "SELECT COUNT(*) FROM glpi_crontasks WHERE itemtype='GlpiPlugin\\\\Jamfsync\\\\SyncTask' AND name='ProcessSyncQueue'")" "1"
assert_eq "old jobs closed" "$(sql "SELECT GROUP_CONCAT(DISTINCT CONCAT(status,'/',error_code)) FROM glpi_plugin_jamfsync_syncjobs")" "stalled/upgrade_aborted"
assert_eq "import rules for existing instance" "$(sql "SELECT COUNT(*) FROM glpi_rules WHERE sub_type='RuleImportAsset' AND uuid LIKE 'jamfsync-1-import-%'")" "2"
assert_eq "entity rule opt-in for existing instance" "$(sql "SELECT CONCAT(manage_entity_rule,'/',rules_id,'/',inventory_tag) FROM glpi_plugin_jamfsync_instances")" "0/0/"
assert_eq "right kept" "$(sql "SELECT rights FROM glpi_profilerights WHERE name='plugin_jamfsync_config' AND profiles_id=4")" "3"
assert_eq "no plugin entries in php-errors.log" "$(plugin_log_entries "$LOG0")" ""

echo
if [ "$FAILURES" -gt 0 ]; then
    echo "E2E: $FAILURES check(s) failed."
    exit 1
fi
echo "E2E: all checks passed."

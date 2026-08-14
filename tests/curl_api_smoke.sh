#!/usr/bin/env bash
set -euo pipefail

PORT="${PORT:-21115}"
BASE_URL="http://127.0.0.1:${PORT}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/rust-book-api-smoke.XXXXXX")"
DB_PATH="${TMP_ROOT}/rust-book-test.sqlite3"
SERVER_PID=""
OLD_DB="${RUSTDESK_API_DATABASE_PATH:-}"

cleanup() {
    if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    if [ -n "$OLD_DB" ]; then
        export RUSTDESK_API_DATABASE_PATH="$OLD_DB"
    else
        unset RUSTDESK_API_DATABASE_PATH || true
    fi
    rm -rf "$TMP_ROOT"
}
trap cleanup EXIT INT TERM

cd "$REPO_ROOT"

command -v php >/dev/null
command -v curl >/dev/null

export RUSTDESK_API_DATABASE_PATH="$DB_PATH"

php scripts/migrate.php >/dev/null
php scripts/migrate.php >/dev/null

PASS_FILE="${TMP_ROOT}/password.txt"
printf '%s\n' 'smoke-password' > "$PASS_FILE"
php scripts/user.php create smokeadmin --admin --display-name='Smoke Admin' --password-file="$PASS_FILE" >/dev/null

php -S "127.0.0.1:${PORT}" -t public public/index.php >"${TMP_ROOT}/server.log" 2>&1 &
SERVER_PID="$!"

for _ in $(seq 1 80); do
    if curl -fsS "${BASE_URL}/health" >/dev/null 2>&1; then
        break
    fi
    sleep 0.25
done

if ! curl -fsS "${BASE_URL}/health" >/dev/null 2>&1; then
    cat "${TMP_ROOT}/server.log" >&2 || true
    echo "Server did not become ready at ${BASE_URL}" >&2
    exit 1
fi

HTTP_STATUS=""
HTTP_BODY=""

request() {
    method="$1"
    path="$2"
    body="${3-__NO_BODY__}"
    token="${4-}"
    response_body="${TMP_ROOT}/response-body.txt"

    args=(-sS -o "$response_body" -w "%{http_code}" -X "$method" -H "Content-Type: application/json")
    if [ -n "$token" ]; then
        args+=(-H "Authorization: Bearer $token")
    fi
    if [ "$body" != "__NO_BODY__" ]; then
        args+=(--data-binary "$body")
    fi
    args+=("${BASE_URL}${path}")

    HTTP_STATUS="$(curl "${args[@]}")"
    HTTP_BODY="$(cat "$response_body")"
}

assert_status() {
    expected="$1"
    label="$2"
    if [ "$HTTP_STATUS" != "$expected" ]; then
        echo "$label expected HTTP $expected, got $HTTP_STATUS" >&2
        echo "$HTTP_BODY" >&2
        exit 1
    fi
}

assert_empty_body() {
    label="$1"
    if [ -n "$HTTP_BODY" ]; then
        echo "$label expected an empty body, got: $HTTP_BODY" >&2
        exit 1
    fi
}

json_token() {
    php -r '$j=json_decode(stream_get_contents(STDIN), true); if (!is_array($j) || !isset($j["access_token"]) || $j["access_token"] === "") { exit(1); } echo $j["access_token"];'
}

validate_empty_book() {
    php -r '$e=json_decode(stream_get_contents(STDIN), true); if (!is_array($e) || !isset($e["data"]) || !is_string($e["data"])) { fwrite(STDERR, "data must be a string\n"); exit(1); } $b=json_decode($e["data"], true); if (!is_array($b) || !isset($b["tags"], $b["peers"], $b["tag_colors"]) || count($b["tags"]) !== 0 || count($b["peers"]) !== 0 || $b["tag_colors"] !== "{}") { fwrite(STDERR, "initial book shape mismatch\n"); exit(1); }'
}

validate_saved_book() {
    php -r '$e=json_decode(stream_get_contents(STDIN), true); if (!is_array($e) || !isset($e["data"]) || !is_string($e["data"])) { fwrite(STDERR, "data must be a string\n"); exit(1); } $b=json_decode($e["data"], true); if (!is_array($b) || count($b["peers"]) !== 3) { fwrite(STDERR, "expected three peers\n"); exit(1); } $ids=array(); foreach ($b["peers"] as $p) { $ids[$p["id"]]=true; } foreach (array("111111111","222222222","333333333") as $id) { if (!isset($ids[$id])) { fwrite(STDERR, "missing peer $id\n"); exit(1); } } if ($b["tag_colors"] !== "{\"Edited\":4286611584,\"Home\":4283215696}") { fwrite(STDERR, "tag colors mismatch\n"); exit(1); }'
}

request GET /api/login-options
assert_status 200 "login-options"
if [ "$HTTP_BODY" != '[""]' ]; then
    echo "login-options body mismatch: $HTTP_BODY" >&2
    exit 1
fi

BAD_LOGIN='{"username":"smokeadmin","password":"wrong","id":"123456789","uuid":"smoke","type":"account","deviceInfo":{"os":"windows","name":"smoke"}}'
request POST /api/login "$BAD_LOGIN"
assert_status 401 "failed login"

request POST /api/currentUser '{}'
assert_status 401 "currentUser without Authorization"

request POST /api/currentUser '{}' "not-a-real-token"
assert_status 401 "currentUser invalid token"

request POST /api/ab/personal
assert_status 404 "ab/personal without Authorization"
assert_empty_body "ab/personal without Authorization"

LOGIN='{"username":"smokeadmin","password":"smoke-password","id":"123456789","uuid":"smoke","type":"account","deviceInfo":{"os":"windows","name":"smoke"}}'
request POST /api/login "$LOGIN"
assert_status 200 "successful login"
TOKEN="$(printf '%s' "$HTTP_BODY" | json_token)"

request POST /api/currentUser '{}' "$TOKEN"
assert_status 200 "currentUser valid token"

request POST /api/ab/personal '{}' "$TOKEN"
assert_status 404 "ab/personal valid token"
assert_empty_body "ab/personal valid token"

request GET /api/ab __NO_BODY__ "$TOKEN"
assert_status 200 "initial GET /api/ab"
printf '%s' "$HTTP_BODY" | validate_empty_book

BOOK='{"tags":["Edited","Home"],"peers":[{"id":"111111111","username":"smokeadmin","hostname":"desktop","platform":"windows","alias":"Desktop","tags":["Edited","Home"],"hash":"AQIDBAU="},{"id":"222222222","username":"smokeadmin","hostname":"laptop","platform":"windows","alias":"Laptop","tags":["Home"],"hash":""},{"id":"333333333","username":"smokeadmin","hostname":"server","platform":"linux","alias":"Server","tags":["Edited"],"hash":"CgsMDQ4="}],"tag_colors":"{\"Edited\":4286611584,\"Home\":4283215696}"}'
SAVE_BODY="$(printf '%s' "$BOOK" | php -r 'echo json_encode(array("data" => stream_get_contents(STDIN)), JSON_UNESCAPED_SLASHES);')"

request POST /api/ab "$SAVE_BODY" "$TOKEN"
assert_status 200 "POST /api/ab save"
assert_empty_body "POST /api/ab save"

request GET /api/ab __NO_BODY__ "$TOKEN"
assert_status 200 "saved GET /api/ab"
printf '%s' "$HTTP_BODY" | validate_saved_book

request POST /api/ab/get '{}' "$TOKEN"
assert_status 200 "POST /api/ab/get"
printf '%s' "$HTTP_BODY" | validate_saved_book

request GET '/api/device-group/accessible?current=1&pageSize=100' __NO_BODY__ "$TOKEN"
assert_status 200 "device group stub"

request GET '/api/users?current=1&pageSize=100&accessible=&status=1' __NO_BODY__ "$TOKEN"
assert_status 200 "users stub"

request GET '/api/peers?current=1&pageSize=100&accessible=&status=1' __NO_BODY__ "$TOKEN"
assert_status 200 "peers stub"

request POST /api/logout '{}' "$TOKEN"
assert_status 200 "logout"

request POST /api/currentUser '{}' "$TOKEN"
assert_status 401 "currentUser after logout"

echo "Rust-Book API curl smoke test passed."

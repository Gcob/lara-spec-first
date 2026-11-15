#!/usr/bin/env bash
#
# Prove that a release branch installs and works in a brand-new Laravel app,
# before anyone tags it. Usage: scripts/smoke-release.sh <branch>
#
# Step 6 of "Before the tag" in docs/contributing/releasing.md. The unit and
# feature suites run inside Testbench, which is not an application: it never
# resolves this package from Composer, never auto-discovers its provider, and
# never publishes its config. A release that passes every test can still fail
# the first `composer require`, and this is the one check that would say so.
#
# What it does, in a temporary directory it deletes on exit:
#
#   1. creates a fresh Laravel app (`composer create-project`; the `laravel`
#      installer is not assumed to exist);
#   2. requires this package as `dev-<branch>`;
#   3. writes a small contract with one operation, `createWidget`, whose JSON
#      body has one required string and one optional integer, and points the
#      published config at it;
#   4. runs `spec:build`, then `spec:make createWidget`;
#   5. serves the app (the PHP server `artisan serve` runs, with OPcache off, so
#      the controller rewritten in a later step is read at once) and sends the
#      operation four requests, all with `Accept: application/json` (a client
#      that does not ask for JSON gets Laravel's redirect rather than a 422,
#      #143):
#        - a good body before the operation is implemented: 501;
#        - the same good body once the controller answers: 2xx;
#        - a body missing the required string: 422;
#        - a body with a string where the integer goes: 422.
#
# The package comes from a Composer `vcs` repository whose URL is THIS git
# checkout, not GitHub and not a `path` repository, for two reasons. The branch
# does not have to be pushed to be tested, and a `path` repository takes the
# version from whatever the checkout has open and symlinks the working tree,
# while this installs the commits on the named branch exactly as a tag would
# ship them. The cost: an uncommitted change is not tested, so commit first.
#
# Every check prints `ok` or `FAIL`, and the script exits 1 if any failed (or 2
# if it could not get as far as checking). Nothing is left behind, so it can be
# run again at once. The `php`, `composer` and `curl` commands must be on the
# PATH, and the machine needs the network for Packagist.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK=""
SERVER_PID=""
FAILED=0

usage() {
    echo "Usage: scripts/smoke-release.sh <branch>" >&2
    exit 2
}

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    if [ -n "$WORK" ] && [ -d "$WORK" ]; then
        rm -rf "$WORK"
    fi
}
trap cleanup EXIT

die() {
    echo "FAIL  $1" >&2
    exit 2
}

# Run one setup step quietly, and show its whole output only if it fails: a
# Composer install is hundreds of lines nobody needs on a green run.
step() {
    local label="$1"
    shift
    printf '  ...   %s\n' "$label"
    local log="$WORK/step.log"
    if ! "$@" >"$log" 2>&1; then
        cat "$log" >&2
        die "$label"
    fi
}

check() {
    local label="$1" expected="$2" actual="$3" good=0
    if [ "$expected" = 2xx ]; then
        [[ "$actual" =~ ^2[0-9][0-9]$ ]] && good=1
    elif [ "$actual" = "$expected" ]; then
        good=1
    fi
    if [ "$good" = 1 ]; then
        printf '  ok    %s (%s)\n' "$label" "$actual"
    else
        printf '  FAIL  %s: expected %s, got %s\n' "$label" "$expected" "$actual"
        FAILED=$((FAILED + 1))
    fi
}

post_widget() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d "$1" "http://127.0.0.1:$PORT/widgets" || true
}

[ $# -eq 1 ] && [ -n "$1" ] || usage
BRANCH="$1"

for command in php composer curl git; do
    command -v "$command" >/dev/null 2>&1 || die "$command is not on the PATH"
done
git -C "$ROOT" rev-parse -q --verify "refs/heads/$BRANCH" >/dev/null ||
    die "no local branch '$BRANCH' in $ROOT"

WORK="$(mktemp -d)"
APP="$WORK/app"

echo "Smoke test of $BRANCH in a fresh Laravel app"

step "create a Laravel app" \
    composer create-project laravel/laravel "$APP" --no-interaction --no-progress

cd "$APP"

step "require gcob/lara-spec-first:dev-$BRANCH" bash -c '
    composer config repositories.smoke vcs "$1" &&
    composer require "gcob/lara-spec-first:dev-$2" --no-interaction --no-progress
' _ "$ROOT" "$BRANCH"

step "publish the config" \
    php artisan vendor:publish --tag=lara-spec-first-config --no-interaction

# The config has two `'path' => 'openapi.yaml',` lines (`spec` and `publish`), so
# the replacement is anchored on the `spec` block and is not a blind substitution.
CONFIG="config/lara-spec-first.php"
SPEC_BLOCK=$'\'spec\' => [\n        \'path\' => \'openapi.yaml\','
SOURCE="$(cat "$CONFIG")"
case "$SOURCE" in
    *"$SPEC_BLOCK"*) ;;
    *) die "the published config no longer has \`'spec' => ['path' => 'openapi.yaml']\`" ;;
esac
SPEC_REPLACEMENT=$'\'spec\' => [\n        \'path\' => \'spec/smoke.yaml\','
printf '%s\n' "${SOURCE/"$SPEC_BLOCK"/$SPEC_REPLACEMENT}" >"$CONFIG"

mkdir spec
cat >spec/smoke.yaml <<'YAML'
openapi: 3.0.3
info:
  title: Smoke test
  version: 1.0.0
paths:
  /widgets:
    post:
      operationId: createWidget
      tags: [Widgets]
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              required: [name]
              properties:
                name:
                  type: string
                quantity:
                  type: integer
      responses:
        '201':
          description: Created
YAML

step "php artisan spec:build" php artisan spec:build --no-interaction
step "php artisan spec:make createWidget" php artisan spec:make createWidget --yes --no-interaction

CONTROLLER="app/Http/Controllers/CreateWidgetController.php"
[ -f "$CONTROLLER" ] || die "spec:make did not write $CONTROLLER"

PORT="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT);')"
# Not `artisan serve`: it starts the PHP server without letting us pass `-d`, and
# OPcache (on by default, `revalidate_freq=2`) would keep serving the scaffold
# controller for a couple of seconds after this script rewrites it, so the 2xx
# check would get the 501 on a slow machine. This is the server `artisan serve`
# runs, with OPcache off.
# Its router script serves `index.php` from the working directory, so it starts
# inside `public`.
cd public
php -d opcache.enable=0 -S "127.0.0.1:$PORT" \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >"$WORK/serve.log" 2>&1 &
SERVER_PID=$!
cd ..

printf '  ...   serve on 127.0.0.1:%s\n' "$PORT"
ready=0
for _ in $(seq 1 60); do
    if curl -s -o /dev/null --max-time 2 "http://127.0.0.1:$PORT/up"; then
        ready=1
        break
    fi
    sleep 0.5
done
if [ "$ready" != 1 ]; then
    cat "$WORK/serve.log" >&2
    die "the server did not answer on port $PORT"
fi

GOOD='{"name":"gadget","quantity":3}'

check "an operation nobody implemented answers" 501 "$(post_widget "$GOOD")"

# Implement the operation: replace the scaffold's one line with an answer.
SCAFFOLD='return parent::routeAction($request);'
SOURCE="$(cat "$CONTROLLER")"
case "$SOURCE" in
    *"$SCAFFOLD"*) ;;
    *) die "$CONTROLLER no longer has the line \`$SCAFFOLD\` to replace" ;;
esac
ANSWER='return response()->json(["name" => $request->input("name")], 201);'
printf '%s\n' "${SOURCE/"$SCAFFOLD"/$ANSWER}" >"$CONTROLLER"

check "a good body answers 2xx" 2xx "$(post_widget "$GOOD")"
check "a body without the required string answers 422" 422 "$(post_widget '{"quantity":3}')"
check "a string where the integer goes answers 422" 422 "$(post_widget '{"name":"gadget","quantity":"many"}')"

if [ "$FAILED" -gt 0 ]; then
    printf '\n%s check(s) FAILED on %s.\n' "$FAILED" "$BRANCH"
    exit 1
fi

printf '\nAll checks passed on %s.\n' "$BRANCH"

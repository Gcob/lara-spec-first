#!/usr/bin/env bash
#
# Run the demo for one minor release against a brand-new Laravel app.
# Usage: demo/run.sh <minor> [<branch>]     (`demo/run.sh 0.2`, `demo/run.sh 0.3 release/0.3.0`)
#
# Step 6 of "Before the tag" in docs/contributing/releasing.md. It replaces the
# smoke test that used to be there, and keeps its four checks as the first
# scenarios: a release that passes every test in this repository can still fail
# the first `composer require`, and this is the check that would say so.
#
# `demo/<minor>/` is a complete demo of that minor: a contract (`openapi.yaml`),
# the code a developer writes on top of what the package generates (`app/` and
# `database/`), the Laravel version it was written for (`laravel-version`), the
# exit code `spec:doctor` gives its contract (`doctor-exit`), and Hurl scenarios.
# What it does, in a temporary directory it deletes on exit:
#
#   1. creates a fresh Laravel app, pinned to the folder's `laravel-version`;
#   2. requires this package: `<minor>.*` from Packagist, or `dev-<branch>`
#      when a branch is given;
#   3. copies the contract to the app's `openapi.yaml`, the default path;
#   4. runs `spec:build`, `spec:make --all --yes`, then `spec:doctor`, and
#      compares the doctor's exit code with `doctor-exit`;
#   5. serves the app, and runs `scenarios/01-before-the-code.hurl`: every
#      operation answers before a line of code exists;
#   6. copies in the folder's code, migrates, and runs the other scenarios in
#      name order.
#
# A released minor runs against its own tag (`0.2` is `0.2.*`, so a patch
# release is picked up by the folder that was frozen with its minor). A minor
# with no `v<minor>.*` tag in this checkout needs a branch.
#
# A branch comes from a Composer `vcs` repository whose URL is THIS git
# checkout, not GitHub and not a `path` repository, for two reasons. The branch
# does not have to be pushed to be tested, and a `path` repository takes the
# version from whatever the checkout has open and symlinks the working tree,
# while this installs the commits on the named branch exactly as a tag would
# ship them. The cost: an uncommitted change is not tested, so commit first.
#
# Exit codes: 0 when the doctor's code matches and every scenario file passes,
# 1 when a check failed (the doctor's or Hurl's output is printed), 2 when it
# could not get as far as checking. Nothing is left behind, so it can be run
# again at once. The `php`, `composer`, `curl`, `git` and `hurl` commands must
# be on the PATH, and the machine needs the network for Packagist.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK=""
SERVER_PID=""
FAILED=0

usage() {
    echo "Usage: demo/run.sh <minor> [<branch>]" >&2
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

# Run one scenario file with Hurl, which prints its own report. A failing file
# is counted and the next one still runs, so one run shows every broken file.
scenario() {
    local file="$1"
    if (cd "$FOLDER" && hurl --test --jobs 1 --variable "host=http://127.0.0.1:$PORT" "$file"); then
        printf '  ok    %s\n' "$file"
    else
        printf '  FAIL  %s\n' "$file"
        FAILED=$((FAILED + 1))
    fi
}

[ $# -ge 1 ] && [ $# -le 2 ] && [ -n "$1" ] || usage
MINOR="$1"
BRANCH="${2:-}"
[ $# -eq 1 ] || [ -n "$BRANCH" ] || usage
[[ "$MINOR" =~ ^[0-9]+\.[0-9]+$ ]] || die "'$MINOR' is not a minor release: expected something like 0.2"

FOLDER="$ROOT/demo/$MINOR"
[ -d "$FOLDER" ] || die "there is no demo/$MINOR in $ROOT"

missing=()
for command in php composer curl git hurl; do
    command -v "$command" >/dev/null 2>&1 || missing+=("$command")
done
if [ "${#missing[@]}" -gt 0 ]; then
    for command in "${missing[@]}"; do
        if [ "$command" = hurl ]; then
            echo "FAIL  hurl is not on the PATH. Install it: https://hurl.dev/docs/installation.html" >&2
            echo "      (\`brew install hurl\` on macOS). CI installs it in .github/workflows/demo.yml." >&2
        else
            echo "FAIL  $command is not on the PATH" >&2
        fi
    done
    exit 2
fi

if [ -n "$BRANCH" ]; then
    git -C "$ROOT" rev-parse -q --verify "refs/heads/$BRANCH" >/dev/null ||
        die "no local branch '$BRANCH' in $ROOT"
elif [ -z "$(git -C "$ROOT" tag --list "v$MINOR.*")" ]; then
    die "$MINOR is not released yet (no v$MINOR.* tag here; \`git fetch --tags\` if it is): pass a branch, demo/run.sh $MINOR <branch>"
fi

for file in laravel-version doctor-exit openapi.yaml; do
    [ -f "$FOLDER/$file" ] || die "demo/$MINOR has no $file"
done
SKELETON="$(tr -d '[:space:]' <"$FOLDER/laravel-version")"
EXPECTED_DOCTOR="$(tr -d '[:space:]' <"$FOLDER/doctor-exit")"

WORK="$(mktemp -d)"
APP="$WORK/app"

echo "Demo $MINOR${BRANCH:+ on $BRANCH}, in a fresh Laravel app"

step "create a Laravel app (laravel/laravel:$SKELETON)" \
    composer create-project "laravel/laravel:$SKELETON" "$APP" --no-interaction --no-progress

cd "$APP"

if [ -n "$BRANCH" ]; then
    step "require gcob/lara-spec-first:dev-$BRANCH" bash -c '
        composer config repositories.demo vcs "$1" &&
        composer require "gcob/lara-spec-first:dev-$2" --no-interaction --no-progress
    ' _ "$ROOT" "$BRANCH"
else
    step "require gcob/lara-spec-first:$MINOR.*" \
        composer require "gcob/lara-spec-first:$MINOR.*" --no-interaction --no-progress
fi

INSTALLED="$(composer show gcob/lara-spec-first --format=json --no-interaction |
    php -r 'echo json_decode(stream_get_contents(STDIN), true)["versions"][0] ?? "unknown";')"
printf '  ...   installed gcob/lara-spec-first %s\n' "$INSTALLED"

cp "$FOLDER/openapi.yaml" openapi.yaml

step "php artisan spec:build" php artisan spec:build --no-interaction
step "php artisan spec:make --all --yes" php artisan spec:make --all --yes --no-interaction

# The doctor runs after the build, because it reports drift until the build has
# run. Its exit code is a check: a release whose doctor starts to report a limit
# or a fault on the demo's contract fails here, and the scenarios still run.
DOCTOR_OUTPUT="$(php artisan spec:doctor --no-interaction 2>&1)" && DOCTOR_CODE=0 || DOCTOR_CODE=$?
if [ "$DOCTOR_CODE" = "$EXPECTED_DOCTOR" ]; then
    printf '  ok    spec:doctor exits %s, as expected\n' "$DOCTOR_CODE"
else
    printf '  FAIL  spec:doctor: expected %s, got %s\n' "$EXPECTED_DOCTOR" "$DOCTOR_CODE"
    printf '%s\n' "$DOCTOR_OUTPUT"
    FAILED=$((FAILED + 1))
fi

PORT="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT);')"
# Not `artisan serve`: it starts the PHP server without letting us pass `-d`, and
# OPcache (on by default, `revalidate_freq=2`) would keep serving the scaffold
# controllers for a couple of seconds after the folder's code replaces them, so
# the first request after the copy would get the 501. This is the server
# `artisan serve` runs, with OPcache off.
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

scenario scenarios/01-before-the-code.hurl

# The code a developer writes: the folder's controllers replace the scaffolds
# `spec:make` wrote (same paths, so the routes already point at them).
cp -R "$FOLDER/app/." app/
cp -R "$FOLDER/database/." database/
step "php artisan migrate" php artisan migrate --force --no-interaction

for file in "$FOLDER"/scenarios/*.hurl; do
    name="scenarios/$(basename "$file")"
    [ "$name" = scenarios/01-before-the-code.hurl ] || scenario "$name"
done

if [ "$FAILED" -gt 0 ]; then
    printf '\n%s check(s) FAILED in demo/%s.\n' "$FAILED" "$MINOR"
    exit 1
fi

printf '\nAll checks passed in demo/%s.\n' "$MINOR"

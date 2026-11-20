#!/usr/bin/env bash
#
# Run the demo for one minor release against a brand-new Laravel app.
# Usage: demo/run.sh <minor> [<branch>] [--keep]
#        (`demo/run.sh 0.2`, `demo/run.sh 0.3 release/0.3.0`, `demo/run.sh 0.2 --keep`)
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
# What it does, in a temporary directory it deletes on exit (or, with `--keep`,
# in `demo/<minor>/.app/`, which stays after the run):
#
#   1. creates a fresh Laravel app, pinned to the folder's `laravel-version`;
#   2. requires this package: `<minor>.*` from Packagist, or the archive of
#      the branch when one is given (`dev-<branch>`), then checks that none of
#      the files a tag leaves out reached `vendor/`;
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
# A branch is installed as a tag's zip would be. `git archive` writes the branch
# tip, honouring the branch's own `.gitattributes`, into a temporary directory,
# and a Composer `path` repository with `symlink` off copies that directory into
# `vendor/`. So files marked `export-ignore` (`tests/`, `docs/`, `.git`) are not
# there, and the branch does not have to be pushed to be tested. The cost: only
# commits are tested, so commit the package's changes first. The folder itself
# (`demo/<minor>/`) is read from the working tree, so its uncommitted files are
# tested.
#
# Exit codes: 0 when the doctor's code matches and every scenario file passes,
# 1 when a check failed (the doctor's or Hurl's output is printed), 2 when it
# could not get as far as checking. Nothing is left behind, so it can be run
# again at once, unless `--keep` is given: then the app stays in
# `demo/<minor>/.app/` (ignored by git), pass or fail, to read what the package
# generated or why a scenario failed. The next `--keep` run replaces it, after
# its arguments, tools and branch are checked, so a run that stops there leaves
# the old one alone. The `php`, `composer`, `curl`, `git`, `tar` and `hurl`
# commands must be on the PATH, and the machine needs the network for Packagist.
# `just demo` runs this file in a container where they all are.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Set by scripts/demo-in-docker.sh, never by CI, to let git read a linked
# worktree from inside the container. They are not named after git's own
# variables because Composer clears those from the environment of every script
# it runs, and `composer demo` runs this file.
if [ -n "${DEMO_GIT_DIR:-}" ]; then
    export GIT_DIR="$DEMO_GIT_DIR" GIT_WORK_TREE="${DEMO_GIT_WORK_TREE:-$ROOT}"
fi

WORK=""
APP=""
SERVER_PID=""
FAILED=0
KEEP=0
REPLACED=0

usage() {
    echo "Usage: demo/run.sh <minor> [<branch>] [--keep]" >&2
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
    # Only an app this run built: an early stop leaves the previous one alone, and
    # must not claim it. A failing `composer create-project` can leave no folder.
    if [ "$REPLACED" = 1 ] && [ -d "$APP" ]; then
        echo "The app is kept in demo/$MINOR/.app/"
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

# A flag is read anywhere, like scripts/release-prep.sh reads `--dry-run`. A git
# branch cannot be named `--keep`, so a flag never hides a branch.
positional=()
for argument in "$@"; do
    case "$argument" in
        --keep) KEEP=1 ;;
        -*) usage ;;
        *) positional+=("$argument") ;;
    esac
done
# `${positional[@]+...}`: macOS's bash 3.2 fails on an empty array under `set -u`.
set -- ${positional[@]+"${positional[@]}"}

[ $# -ge 1 ] && [ $# -le 2 ] && [ -n "$1" ] || usage
MINOR="$1"
BRANCH="${2:-}"
[ $# -eq 1 ] || [ -n "$BRANCH" ] || usage
[[ "$MINOR" =~ ^[0-9]+\.[0-9]+$ ]] || die "'$MINOR' is not a minor release: expected something like 0.2"

FOLDER="$ROOT/demo/$MINOR"
[ -d "$FOLDER" ] || die "there is no demo/$MINOR in $ROOT"

missing=()
for command in php composer curl git tar hurl; do
    command -v "$command" >/dev/null 2>&1 || missing+=("$command")
done
if [ "${#missing[@]}" -gt 0 ]; then
    for command in "${missing[@]}"; do
        if [ "$command" = hurl ]; then
            echo "FAIL  hurl is not on the PATH. Run the demo in Docker, whose image carries it:" >&2
            echo "      \`just demo <minor> [<branch>]\`. To run it natively, install Hurl:" >&2
            echo "      https://hurl.dev/docs/installation.html (\`brew install hurl\` on macOS)." >&2
            echo "      CI installs it in .github/workflows/demo.yml." >&2
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

echo "PHP $(php -r 'echo PHP_VERSION;'), Hurl $(hurl --version | awk 'NR == 1 { print $2 }')"

WORK="$(mktemp -d)"
if [ "$KEEP" = 1 ]; then
    # Only now, after every check that can stop the run, so an early stop leaves
    # the previous app in place. `composer create-project` wants an empty target.
    APP="$FOLDER/.app"
    rm -rf "$APP"
    REPLACED=1
else
    APP="$WORK/app"
fi

echo "Demo $MINOR${BRANCH:+ on $BRANCH}, in a fresh Laravel app"

step "create a Laravel app (laravel/laravel:$SKELETON)" \
    composer create-project "laravel/laravel:$SKELETON" "$APP" --no-interaction --no-progress

cd "$APP"

if [ -n "$BRANCH" ]; then
    # Its own shell, for `pipefail`: a failing `git archive` must not be lost
    # behind `tar`.
    step "unpack the archive of $BRANCH" bash -c '
        set -o pipefail
        mkdir "$3" &&
        git -C "$1" archive --format=tar "refs/heads/$2" | tar -x -C "$3"
    ' _ "$ROOT" "$BRANCH" "$WORK/package"
    REPOSITORY="$(php -r 'echo json_encode([
        "type" => "path",
        "url" => $argv[1],
        "options" => ["symlink" => false, "versions" => ["gcob/lara-spec-first" => "dev-" . $argv[2]]],
    ], JSON_UNESCAPED_SLASHES);' "$WORK/package" "$BRANCH")"
    step "require gcob/lara-spec-first:dev-$BRANCH" bash -c '
        composer config repositories.demo "$1" &&
        composer require "gcob/lara-spec-first:dev-$2" --no-interaction --no-progress
    ' _ "$REPOSITORY" "$BRANCH"
else
    step "require gcob/lara-spec-first:$MINOR.*" \
        composer require "gcob/lara-spec-first:$MINOR.*" --no-interaction --no-progress
fi

INSTALLED="$(composer show gcob/lara-spec-first --format=json --no-interaction |
    php -r 'echo json_decode(stream_get_contents(STDIN), true)["versions"][0] ?? "unknown";')"
printf '  ...   installed gcob/lara-spec-first %s%s\n' "$INSTALLED" \
    "${BRANCH:+ (commit $(git -C "$ROOT" rev-parse --short "refs/heads/$BRANCH"))}"

# This checks how the demo installs a branch (the archive, not a clone), not which
# files `.gitattributes` marks: `vendor/` comes from the same archive it compares.
#
# What a tag's zip leaves out is decided by git, from the branch's own
# `.gitattributes`: the files in the branch's tree that `git archive` does not
# write. None of them may be in `vendor/`. An empty list fails too, because the
# check would prove nothing. Only a branch needs it: a Packagist install is
# already GitHub's zip.
check_vendor_has_what_a_tag_ships() {
    local ref="refs/heads/$BRANCH" installed_at="$APP/vendor/gcob/lara-spec-first"
    local in_tree="$WORK/in-tree.txt" in_archive="$WORK/in-archive.txt" left_out="$WORK/left-out.txt"
    git -C "$ROOT" -c core.quotePath=false ls-tree -r --name-only "$ref" | LC_ALL=C sort >"$in_tree"
    git -C "$ROOT" archive --format=tar "$ref" | tar -t | LC_ALL=C sort >"$in_archive"
    LC_ALL=C comm -23 "$in_tree" "$in_archive" >"$left_out"

    local total=0 leaked=0 sample="" path
    while IFS= read -r path; do
        total=$((total + 1))
        if [ -e "$installed_at/$path" ] || [ -L "$installed_at/$path" ]; then
            leaked=$((leaked + 1))
            [ "$leaked" -gt 5 ] || sample="$sample $path"
        fi
    done <"$left_out"

    if [ "$total" -eq 0 ]; then
        printf '  FAIL  the branch leaves no file out of its archive, so there is nothing to check\n'
        FAILED=$((FAILED + 1))
    elif [ "$leaked" -gt 0 ]; then
        printf '  FAIL  vendor/ holds %s of the %s files a tag leaves out, for example:%s\n' "$leaked" "$total" "$sample"
        FAILED=$((FAILED + 1))
    else
        printf '  ok    vendor/ holds none of the %s files a tag leaves out\n' "$total"
    fi
}

if [ -n "$BRANCH" ]; then
    check_vendor_has_what_a_tag_ships
fi

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
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >"$APP/storage/logs/serve.log" 2>&1 &
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
    cat "$APP/storage/logs/serve.log" >&2
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

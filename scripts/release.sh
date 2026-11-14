#!/usr/bin/env sh
#
# Tag a release: `just release <version>` is a dry run, and
# `just release <version> --publish` is the real thing. It runs on the HOST, like
# format-markdown.sh and check-lowest.sh: it needs gh and a terminal, and
# the PHP container has none of them.
#
# Tagging is the maintainer's by rule: the factory's rules forbid an agent to do
# it. This script makes the real run impossible by accident, which is less than
# impossible for an agent that means to: a process that opens a pseudo-terminal
# and types the version gets through, and so does a plain `git tag` and
# `git push`. What it guards against is a pipe, a missing flag and a red check.
#
#   - Alone it only reads. It prints every check green or red and what it would
#     do, writes nothing, and exits 1 when any check is red.
#   - With --publish it needs the same checks green, then a terminal: it shows
#     who the tag will be signed as, and waits for the version to be typed. With
#     no terminal (a pipe, CI) or a wrong answer it exits non-zero and
#     has written nothing.
#   - Then the annotated tag, the push, and the GitHub release whose body is the
#     version's changelog entry, its relative links turned into links pinned to
#     the tag so they keep showing the docs as they were at that release.
#
# `gh` is never told which repository to use: see scripts/release-lib.sh.
#
# Usage: scripts/release.sh <version> [--publish]

set -u

. "$(dirname "$0")/release-lib.sh"
enter_repository

VERSION=""
PUBLISH=0

for argument in "$@"; do
    case "$argument" in
        --publish) PUBLISH=1 ;;
        -*)
            echo "Unknown option: $argument" >&2
            echo "Usage: scripts/release.sh <version> [--publish]" >&2
            exit 1
            ;;
        *) VERSION="$argument" ;;
    esac
done

if [ -z "$VERSION" ]; then
    echo "Usage: scripts/release.sh <version> [--publish]" >&2
    exit 1
fi

echo "Release v$VERSION: checks"

if is_version "$VERSION"; then
    pass "the version is valid"
else
    fail "the version is valid: \"$VERSION\" is not X.Y.Z"
    echo "Nothing else was checked, and nothing was written."
    exit 1
fi

if state=$(tag_state "$VERSION"); then
    fail "the tag v$VERSION does not exist: it $state"
else
    case "$?" in
        1) pass "the tag v$VERSION does not exist" ;;
        *) fail "the tag v$VERSION does not exist: $state" ;;
    esac
fi

if [ -z "$(git status --porcelain)" ]; then
    pass "the working tree is clean"
else
    fail "the working tree is clean: git status lists changes"
fi

head_sha=$(git rev-parse HEAD 2>/dev/null)
main_sha=$(remote_main_sha)

if [ -n "$main_sha" ] && [ "$head_sha" = "$main_sha" ]; then
    pass "HEAD is origin/main ($(echo "$head_sha" | cut -c1-7))"
else
    fail "HEAD is origin/main: HEAD is $(echo "$head_sha" | cut -c1-7), origin/main is $(echo "${main_sha:-unknown}" | cut -c1-7)"
fi

if ci=$(ci_state "$head_sha"); then
    pass "CI is green on $(echo "$head_sha" | cut -c1-7) ($ci)"
else
    fail "CI is green on $(echo "$head_sha" | cut -c1-7): $ci"
fi

entry=$(awk -v v="$VERSION" '
    BEGIN { heading = "## [" v "] - " }
    found && (/^## / || /^\[[^]]+\]: /) { exit }
    found { print }
    index($0, heading) == 1 { found = 1 }
' CHANGELOG.md 2>/dev/null | sed '/./,$!d')

if [ -n "$(printf '%s' "$entry" | tr -d '[:space:]')" ]; then
    pass "CHANGELOG.md has a \"## [$VERSION] - \" heading with an entry"

    heading=$(awk -v h="## [$VERSION] - " 'index($0, h) == 1 { print substr($0, length(h) + 1); exit }' CHANGELOG.md)
    heading_date=${heading%% *}
    today=$(date +%Y-%m-%d)

    if [ "$heading_date" = "$today" ]; then
        pass "the heading's date is today ($today)"
    else
        fail "the heading's date is today: it says \"$heading_date\" and today is $today. Edit the date in CHANGELOG.md through a pull request and merge it, then run this again"
    fi
else
    fail "CHANGELOG.md has a \"## [$VERSION] - \" heading with an entry: none found"
fi

login=$(gh api user --jq .login 2>/dev/null || true)

if [ "$login" = "Gcob" ]; then
    pass "gh is logged in as Gcob"
else
    fail "gh is logged in as Gcob: it is \"${login:-nobody}\""
fi

slug=$(gh repo view --json nameWithOwner --jq .nameWithOwner 2>/dev/null || true)
origin_repository=$(origin_slug)

if [ -z "$slug" ]; then
    fail "gh reads the repository from origin: it answered nothing"
elif [ "$slug" != "$origin_repository" ]; then
    fail "gh reads the repository from origin: gh says $slug, origin says ${origin_repository:-nothing}"
else
    pass "gh reads the repository from origin ($slug)"
fi

if [ "$(git show "HEAD:CHANGELOG.md" 2>/dev/null | unreleased_lines)" -eq 0 ]; then
    pass "[Unreleased] is empty at HEAD, so nothing merged after the release pull request"
else
    fail "[Unreleased] is empty at HEAD: something merged after the release pull request, so the tag would carry it without an entry. Put it in the $VERSION entry, or release from the commit before it"
fi

if [ "$PUBLISH" -eq 0 ]; then
    echo
    echo "Dry run. With --publish it would:"
    echo "  - create the annotated tag v$VERSION on $(echo "$head_sha" | cut -c1-7)"
    echo "  - push it to origin"
    echo "  - create the GitHub release v$VERSION, its body the $VERSION entry of CHANGELOG.md"
    echo "    with relative links pinned to https://github.com/${slug:-<repository>}/blob/v$VERSION/"
    echo

    if [ "$RED" -gt 0 ]; then
        echo "$RED check(s) red: --publish would refuse. Nothing was written."
        exit 1
    fi

    echo "All checks green. Run: just release $VERSION --publish"
    exit 0
fi

if [ "$RED" -gt 0 ]; then
    echo
    echo "$RED check(s) red: refusing to tag. Nothing was written." >&2
    exit 1
fi

if [ ! -t 0 ]; then
    echo
    echo "Refusing to tag: --publish asks for the version typed at an interactive terminal, and there is none." >&2
    echo "Nothing was written." >&2
    exit 1
fi

tagger=$(git var GIT_COMMITTER_IDENT 2>/dev/null | sed -E 's/ [0-9]+ [+-][0-9]+$//')

if [ -z "$tagger" ]; then
    echo "Refusing to tag: git has no identity to sign the tag with. Nothing was written." >&2
    exit 1
fi

echo
echo "The tag will be created by: $tagger"
printf 'Type the version to tag (%s): ' "$VERSION"

answer=""
read -r answer || true

if [ "$answer" != "$VERSION" ]; then
    echo
    echo "That is not $VERSION. Nothing was written." >&2
    exit 1
fi

base="https://github.com/$slug/blob/v$VERSION"

# Relative links of the entry, pinned to the tag: `./docs/x.md` and `docs/x.md`
# become `<base>/docs/x.md`, and a bare `#anchor` becomes `<base>/CHANGELOG.md#anchor`.
# A link with a scheme (`https:`, `mailto:`) has a colon before any slash and is left alone.
body=$(printf '%s\n' "$entry" | sed -E \
    -e "s,\]\(#([^)[:space:]]*)\),](${base}/CHANGELOG.md#\1),g" \
    -e "s,\]\((\./)?([^#/:)[:space:]][^:)[:space:]]*)\),](${base}/\2),g")

case "$VERSION" in
    *-*) prerelease="--prerelease" ;;
    *) prerelease="" ;;
esac

git tag -a "v$VERSION" -m "v$VERSION" || exit 1

if ! git push origin "refs/tags/v$VERSION"; then
    git tag -d "v$VERSION" >/dev/null
    echo "The push failed, so the local tag was removed again. Nothing was published." >&2
    exit 1
fi

# shellcheck disable=SC2086
gh release create "v$VERSION" --title "v$VERSION" --notes "$body" --verify-tag $prerelease || {
    echo "The tag v$VERSION is pushed but the GitHub release was not created. Create it by hand from the CHANGELOG.md entry." >&2
    exit 1
}

echo
echo "Tagged and published v$VERSION. Packagist picks the tag up through the GitHub hook."

#!/usr/bin/env sh
#
# Prepare a release: `just release-prep <version> <card>`. It runs on the HOST,
# like format-markdown.sh and check-lowest.sh: it needs git and gh.
#
# It does the mechanical half of a release and leaves the judgment to a person:
#
#   - checks the gates: the release card's "Ready when" boxes are all ticked, CI
#     is green on `main`, the tag and the branch do not exist, the tree is clean;
#   - creates `release/<card>/<version>` from the tip of origin's `main`;
#   - renames `## [Unreleased]` to `## [<version>] - <today>`, opens a new empty
#     `[Unreleased]`, and updates the link references at the end of CHANGELOG.md;
#   - commits that, pushes the branch, and opens a DRAFT pull request whose body
#     says it was opened by this recipe and lists what is left to do by hand.
#
# It never tags: that is scripts/release.sh, which asks a person to type the
# version at a terminal.
#
# --dry-run prints every check green or red and writes nothing (not even a
# remote-tracking ref), and exits 1 when any check is red. Without it, a red
# check stops the script before it writes anything.
#
# `gh` is never told which repository to use: see scripts/release-lib.sh.
#
# Usage: scripts/release-prep.sh <version> <card> [--dry-run]

set -u

. "$(dirname "$0")/release-lib.sh"
enter_repository

USAGE="Usage: scripts/release-prep.sh <version> <card> [--dry-run]"
VERSION=""
CARD=""
DRY_RUN=0

for argument in "$@"; do
    case "$argument" in
        --dry-run) DRY_RUN=1 ;;
        -*)
            echo "Unknown option: $argument" >&2
            echo "$USAGE" >&2
            exit 1
            ;;
        *)
            if [ -z "$VERSION" ]; then
                VERSION="$argument"
            else
                CARD="$argument"
            fi
            ;;
    esac
done

if [ -z "$VERSION" ] || [ -z "$CARD" ]; then
    echo "$USAGE" >&2
    exit 1
fi

BRANCH="release/$CARD/$VERSION"
TODAY=$(date +%Y-%m-%d)

echo "Release v$VERSION, branch $BRANCH: checks"

if is_version "$VERSION"; then
    pass "the version is valid"
else
    fail "the version is valid: \"$VERSION\" is not X.Y.Z"
fi

if printf '%s' "$CARD" | grep -Eq '^[0-9]+$'; then
    pass "the card is a number (#$CARD)"
else
    fail "the card is a number: \"$CARD\""
fi

if [ "$RED" -gt 0 ]; then
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

if git rev-parse -q --verify "refs/heads/$BRANCH" >/dev/null 2>&1; then
    fail "the branch $BRANCH does not exist: it exists locally"
elif remote_branch=$(git ls-remote --heads origin "refs/heads/$BRANCH" 2>/dev/null); then
    if [ -n "$remote_branch" ]; then
        fail "the branch $BRANCH does not exist: it exists on origin"
    else
        pass "the branch $BRANCH does not exist"
    fi
else
    fail "the branch $BRANCH does not exist: origin could not be asked"
fi

if [ -z "$(git status --porcelain)" ]; then
    pass "the working tree is clean"
else
    fail "the working tree is clean: git status lists changes"
fi

main_sha=$(remote_main_sha)

if [ -z "$main_sha" ]; then
    fail "origin has a main branch: it answered nothing"
elif [ "$DRY_RUN" -eq 0 ]; then
    if git fetch --quiet origin main; then
        pass "origin/main is fetched ($(echo "$main_sha" | cut -c1-7))"
    else
        fail "origin/main is fetched: git fetch origin main failed"
    fi
elif [ "$(git rev-parse -q --verify refs/remotes/origin/main 2>/dev/null)" = "$main_sha" ]; then
    pass "origin/main is up to date here ($(echo "$main_sha" | cut -c1-7))"
else
    fail "origin/main is up to date here: run git fetch origin main, a dry run fetches nothing"
fi

if [ -n "$main_sha" ]; then
    if ci=$(ci_state "$main_sha"); then
        pass "CI is green on main, $(echo "$main_sha" | cut -c1-7) ($ci)"
    else
        fail "CI is green on main, $(echo "$main_sha" | cut -c1-7): $ci"
    fi
fi

body=$(gh issue view "$CARD" --json body --jq .body 2>/dev/null || true)

# The "Ready when" section: from its heading to the next heading of any level.
ready=$(printf '%s\n' "$body" | awk '
    /^#+[[:space:]]+Ready when/ { inside = 1; next }
    inside && /^#/ { exit }
    inside { print }
')

if [ -z "$body" ]; then
    fail "card #$CARD has every \"Ready when\" box ticked: gh could not read the card"
elif ! printf '%s\n' "$ready" | grep -Eq "${BOX}[ xX]\]"; then
    fail "card #$CARD has every \"Ready when\" box ticked: it has no box to tick"
elif open=$(printf '%s\n' "$ready" | grep -E "${BOX} \]"); then
    fail "card #$CARD has every \"Ready when\" box ticked: $(printf '%s\n' "$open" | wc -l | tr -d ' ') still open"
    printf '%s\n' "$open" | sed 's/^/          /'
else
    pass "card #$CARD has every \"Ready when\" box ticked"
fi

changelog=""

if [ -n "$main_sha" ]; then
    changelog=$(git show "$main_sha:CHANGELOG.md" 2>/dev/null || true)
fi

if [ -z "$changelog" ]; then
    fail "CHANGELOG.md can be read at main"
else
    if printf '%s\n' "$changelog" | grep -q '^## \[Unreleased\]$'; then
        pending=$(printf '%s\n' "$changelog" | unreleased_lines)

        if [ "$pending" -gt 0 ]; then
            pass "CHANGELOG.md has an [Unreleased] entry with content"
        else
            fail "CHANGELOG.md has an [Unreleased] entry with content: it is empty"
        fi
    else
        fail "CHANGELOG.md has an [Unreleased] entry with content: no \"## [Unreleased]\" heading"
    fi

    if printf '%s\n' "$changelog" | awk -v v="$VERSION" 'index($0, "## [" v "] - ") == 1 { found = 1 } END { exit !found }'; then
        fail "CHANGELOG.md has no [$VERSION] heading yet: it has one"
    else
        pass "CHANGELOG.md has no [$VERSION] heading yet"
    fi

    reference=$(printf '%s\n' "$changelog" | grep -E '^\[[Uu]nreleased\]: .*/compare/v.*\.\.\.HEAD$' | head -n 1)

    if [ -n "$reference" ]; then
        pass "CHANGELOG.md has the [unreleased] link reference"
    else
        fail "CHANGELOG.md has the [unreleased] link reference: no \"[unreleased]: …/compare/v…...HEAD\" line"
    fi
fi

if [ "$RED" -gt 0 ]; then
    echo
    echo "$RED check(s) red. Nothing was written."
    exit 1
fi

previous=$(printf '%s' "$reference" | sed -E 's,^.*/compare/v(.*)\.\.\.HEAD$,\1,')
base=$(printf '%s' "$reference" | sed -E 's,^\[[Uu]nreleased\]: (.*)/compare/v.*\.\.\.HEAD$,\1,')

if [ "$DRY_RUN" -eq 1 ]; then
    echo
    echo "Dry run, all checks green. Without --dry-run it would:"
    echo "  - create $BRANCH from origin/main ($(echo "$main_sha" | cut -c1-7))"
    echo "  - rename [Unreleased] to [$VERSION] - $TODAY, open a new empty [Unreleased], update the link references"
    echo "  - commit, push the branch and open a draft pull request"
    echo "Remember that the heading's date ($TODAY) must be the day the tag is cut."
    exit 0
fi

git switch --quiet --create "$BRANCH" --no-track "$main_sha" || exit 1

# From here on this checkout is on the release branch. Say how to leave it when a
# step fails, because nothing below undoes the switch.
stranded() {
    echo "Stopped on $BRANCH, which this checkout is now on. Fix the cause and finish by hand, or go back with" >&2
    echo "  git switch - && git branch -D $BRANCH" >&2
    echo "after discarding the CHANGELOG.md change if there is one." >&2
    exit 1
}

awk -v v="$VERSION" -v d="$TODAY" -v base="$base" '
    /^## \[Unreleased\]$/ && !heading {
        print
        print ""
        print "## [" v "] - " d
        heading = 1
        next
    }
    tolower($0) ~ /^\[unreleased\]: / && !reference {
        print "[unreleased]: " base "/compare/v" v "...HEAD"
        print "[" v "]: " base "/releases/tag/v" v
        reference = 1
        next
    }
    { print }
' CHANGELOG.md >CHANGELOG.md.release-prep && mv CHANGELOG.md.release-prep CHANGELOG.md || stranded

git add CHANGELOG.md || stranded
git commit --quiet -m "docs(release): #$CARD cut $VERSION in the changelog" || stranded
git push --quiet --set-upstream origin "$BRANCH" || stranded

pr_description() {
    cat <<DESCRIPTION
Opened by \`just release-prep $VERSION $CARD\`.

**Done by the recipe:** the \`[Unreleased]\` heading is now \`[$VERSION] - $TODAY\`, with a new empty \`[Unreleased]\` above it, and the link references at the end of \`CHANGELOG.md\` follow.

**Left to do by hand on this branch** (judgment, see [\`docs/contributing/releasing.md\`]($base/blob/main/docs/contributing/releasing.md)):

- [ ] Rewrite the entry against the previous release (v$previous): drop or merge what fixes or contradicts something never released, one heading each for Added, Changed, Fixed and Known limits.
- [ ] Add an "Upgrading from $previous" block at the top of the entry, one item per breaking change, grouped by what the user changes.
- [ ] Update the \`Known limits\` summary line and the release paragraph of \`CHANGELOG.md\`.
- [ ] README ("Where we are" and the phase paragraph), \`AGENTS.md\` and \`docs/project/stack.md\` name the version where they name the shipped one.
- [ ] \`just docs-build\` passes, and \`demo/run.sh <minor> <this branch>\` passes.

**The heading's date must be the day the tag is cut.** It says $TODAY, the day this branch was prepared: if the tag lands another day, fix the heading before the merge.

Tagging is not part of this pull request: after the merge, the maintainer runs \`just release $VERSION --publish\`.
DESCRIPTION
}

description=$(pr_description)

gh pr create --draft --base main --head "$BRANCH" --title "docs(release): #$CARD release $VERSION" --body "$description" || {
    echo "The branch $BRANCH is pushed but the draft pull request was not opened. Open it by hand." >&2
    exit 1
}

echo
echo "Prepared $BRANCH and opened a draft pull request."
echo "Warning: the heading's date ($TODAY) must be the day the tag is cut."
echo "Next: do the judgment steps listed in the pull request, then merge it."

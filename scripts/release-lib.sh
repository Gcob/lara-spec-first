#!/usr/bin/env sh
#
# Shared by scripts/release-prep.sh and scripts/release.sh, which source it. It
# is not a command: nothing here runs on its own.
#
# What lives here is what both scripts must do the same way, because a release
# that is prepared by one rule and tagged by another is the failure this is
# written against: how a check is printed and counted, what a version looks like,
# whether a tag exists, and what "CI is green on this commit" means.
#
# `gh` is never given `--repo`. It reads the repository from the `origin` remote
# of the directory it runs in, so the tests can point `origin` at a local
# repository and put a fake `gh` first on `PATH` without a line of the scripts
# knowing, and no run can reach a repository the checkout does not name.

RED=0

# Print one check and count it when it is red. The label is what a person greps.
pass() {
    printf '  ok    %s\n' "$1"
}

fail() {
    printf '  RED   %s\n' "$1"
    RED=$((RED + 1))
}

# Run from the repository root, wherever the script was started from.
enter_repository() {
    cd "$(dirname "$0")/.." || exit 1
}

is_version() {
    printf '%s' "$1" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'
}

# The tag exists here, or on origin. Prints which one, and fails when origin
# cannot be asked: an unanswered question is not a "no".
tag_state() {
    if git rev-parse -q --verify "refs/tags/v$1" >/dev/null 2>&1; then
        echo "exists locally"
        return 0
    fi

    remote=$(git ls-remote --tags origin "refs/tags/v$1" 2>&1) || {
        echo "origin could not be asked"
        return 2
    }

    if [ -n "$remote" ]; then
        echo "exists on origin"
        return 0
    fi

    return 1
}

# The commit `main` points at on origin, asked of origin itself: nothing is
# fetched, so a dry run writes nothing, not even a remote-tracking ref.
remote_main_sha() {
    git ls-remote origin refs/heads/main 2>/dev/null | cut -f1
}

# What `gh api` prints for one page of a commit's check runs: the number of runs
# there are in all, how many of this page are not finished, and how many of this
# page finished and did not succeed. `skipped` and `neutral` are not failures.
CI_JQ='
    [
        .total_count,
        ([.check_runs[] | select(.status != "completed")] | length),
        ([.check_runs[] | select(.status == "completed"
            and ((.conclusion == "success" or .conclusion == "skipped" or .conclusion == "neutral") | not))] | length)
    ] | map(tostring) | join(" ")'

# Is every check run of this commit finished and successful? `--paginate` makes
# `gh` ask for every page, and print one line per page, so a failure on page two
# is as visible as one on page one. Prints the three numbers it read (all, still
# running, failed) and fails unless there is at least one run and none of the others.
ci_state() {
    pages=$(gh api --paginate "repos/{owner}/{repo}/commits/$1/check-runs?per_page=100" --jq "$CI_JQ" 2>/dev/null) || {
        echo "gh could not read the check runs"
        return 1
    }

    counts=$(printf '%s\n' "$pages" | awk '
        NF != 3 { bad = 1 }
        NR == 1 { total = $1 }
        { pending += $2; failed += $3 }
        END { if (bad) exit 1; print total, pending, failed }
    ') || {
        echo "gh answered something unexpected"
        return 1
    }

    set -- $counts

    if [ "$1" -gt 0 ] && [ "$2" -eq 0 ] && [ "$3" -eq 0 ]; then
        echo "$1 runs, all green"
        return 0
    fi

    echo "$1 runs, $2 still running, $3 failed"
    return 1
}

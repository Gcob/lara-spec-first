---
title: Releasing
audience: Contributors
covers: >
    How a version of this package is cut: what has to be true before a release is prepared, what `just release-prep`
    does on its own and what it leaves to judgment, the checks `just release` runs and what its prompt does and does not
    guard against, what the GitHub release carries, and what the board owes afterwards.
read_before: Preparing a release, tagging one, or changing the `release-prep` or `release` recipes.
tags: [workflow, versions, contributing, ci, conventions]
---

# Releasing

> **In brief**
>
> - A release is a release card, a branch `release/{card}/{version}`, a pull request, and then a tag.
> - `just release-prep <version> <card>` does the mechanical steps and opens a draft pull request. The rest of that pull
>   request is judgment, and a person or an agent does it by hand.
> - `just release <version>` is a dry run. `just release <version> --publish` tags, and only after the version is typed
>   at a terminal, so the real run cannot happen by accident, from a pipe, or with a missing flag.
> - Only the maintainer tags, by rule: the factory's rules forbid it, and the script does not enforce it against an
>   agent that means to.
> - Packagist publishes the version from the tag, and the board is synced afterwards.

A version of this package is cut in three steps by two different hands: the factory prepares everything up to a merged
release pull request, the maintainer tags, and the factory then syncs the board. This file is the single description of
those steps. The recipes it names are in the [`justfile`](https://github.com/Gcob/lara-spec-first/blob/main/justfile),
and the logic behind them is in
[`scripts/release-prep.sh`](https://github.com/Gcob/lara-spec-first/blob/main/scripts/release-prep.sh) and
[`scripts/release.sh`](https://github.com/Gcob/lara-spec-first/blob/main/scripts/release.sh).

## What a release is

**A release is carried by a release card.** The card's `Ready when` lists, as ticked boxes, what has to be merged first
(a lot's cards, its lesson, the follow-ups that belong with it), and `Done when` ends with the version on Packagist.
`release-prep` reads those boxes: an unticked one stops it.

**The branch is `release/{card}/{version}`**, for example `release/126/0.2.0`, following
[the branch names](../../CONTRIBUTING.md#branch-names). Its pull request is the one place the entry is reviewed, and the
pull request is merged before anything is tagged.

**The tag is `v{version}`, annotated, on the tip of `origin/main`, which must be the release pull request's merge
commit.** The version in the changelog heading, the tag and the GitHub release are the same string.

## Before the tag

**These steps are the factory's.** They end with a merged release pull request.

1. **The lot is closed.** Every card of the lot is merged, the lot's lesson is written and its box on the lot card is
   ticked, and the lot card is in `Done`. `release-prep` reads this from the release card's `Ready when`.
2. **`main` is green.** The full matrix and the lowest-dependencies job passed on the commit being released.
3. **`just release-prep <version> <card>` runs.** It checks the gates, then does the mechanical steps, described in
   [the next section](#release-prep-does-the-mechanical-half).
4. **The judgment steps are done by hand on that branch.** They are listed in
   [the section after](#the-judgment-steps-stay-by-hand).
5. **The docs build.** `just docs-build` passes, and it fails on a dead link.
6. **The smoke test passes.** `scripts/smoke-release.sh <branch>` requires the branch in a fresh Laravel app, runs
   `spec:build` and `spec:make`, implements one operation, and expects a 2xx on a good body and a `422` on a bad one (an
   operation nobody implemented answers `501`). It can be rerun, and it is committed on the release branch with the
   release pull request.
7. **The release pull request is reviewed and merged.** `pr-reviewer` reads it first and `blind-reviewer` second, and it
   merges under the factory's guardrails with CI green. The factory then puts the command of
   [the tag step](#the-tag-is-the-maintainers) in the release plan, as an action for the maintainer.

## `release-prep` does the mechanical half

```bash
just release-prep 0.2.0 126            # gates, branch, changelog, draft pull request
just release-prep 0.2.0 126 --dry-run  # only the checks
```

**It refuses before it writes anything.** Every check is printed green or red, and one red check stops it with exit
code 1. The checks are a valid version and a card number, a tag that does not exist, a branch that does not exist, a
clean working tree, CI green on the tip of `origin/main`, every `Ready when` box of the card ticked, and a
`CHANGELOG.md` at `main` with a non-empty `[Unreleased]`, no heading for the version yet, and its link references. A
card with no `Ready when` section, or one with no box in it, is red as well: a card that lists nothing does not say what
blocks the release.

**`release-prep` runs for real without a flag, and `release` is a dry run without one, on purpose.** Preparing a branch
is reversible: close the pull request and delete the branch. A tag is not, and `release` makes you add `--publish`.

**Run it in a worktree, or from a clean `main`.** The real run switches the checkout it runs in to the release branch
and leaves it there, so it refuses a dirty tree, and a hook that refuses the commit leaves the branch behind with
`CHANGELOG.md` edited. In a worktree made for the release, that costs nothing.

**Then it does these things, in this order:**

1. Creates `release/{card}/{version}` from the tip of `origin/main`.
2. Renames `## [Unreleased]` to `## [<version>] - YYYY-MM-DD` with today's date, and opens a new empty `[Unreleased]`
   above it.
3. Updates the link references at the end of `CHANGELOG.md`: `[<version>]` points to `…/releases/tag/v<version>`, and
   `[unreleased]` to `…/compare/v<version>...HEAD`.
4. Commits that, pushes the branch, and opens a **draft** pull request. Its body says it was "Opened by
   `just release-prep <version> <card>`", which is how a reviewer tells a prepared branch from a hand-made one, and it
   lists the judgment steps as boxes.

**The heading's date must be the day the tag is cut.** The script writes the day it ran. A release pull request that
merges a few days later carries a date that is wrong, so the script warns about it twice, in its output and in the pull
request, and `release` refuses to tag until the date is today's (see [the checks](#the-tag-is-the-maintainers)).

**`--dry-run` writes nothing.** It does not even fetch, so it compares what origin says `main` is with what the checkout
remembers, and says to fetch when they differ.

## The judgment steps stay by hand

**A script cannot decide what a changelog entry should say**, so these steps are the release pull request's work:

1. **Rewrite the entry against the previous release.** An entry that fixes or contradicts something that was never
   released is dropped or merged, and "before" means the previous release, not `main` a week ago. The entry has one
   heading each for Added, Changed, Fixed and Known limits.
2. **Add an "Upgrading from" block at the top of the entry.** One item per breaking change, from a list rebuilt against
   the previous tag, grouped by what the user changes (a name clash, the body and `query`, references, media types),
   each saying what to change in the application.
3. **Follow up in `CHANGELOG.md` itself.** The release paragraph near the top and the `Known limits` summary line change
   with the entry.
4. **Name the version where the docs name the shipped one.** The README's "Where we are" and phase paragraph,
   `AGENTS.md` and [`docs/project/stack.md`](../project/stack.md).

## The tag is the maintainer's

```bash
just release 0.2.0             # dry run: every check, and what it would do
just release 0.2.0 --publish   # the real run
```

**Alone, `release` only reads.** It prints every check green or red and what it would do, writes nothing, and exits 1
when any check is red. An exit code of 0 means the real run would go through.

**The checks are the same in both modes:**

1. The version is valid (`X.Y.Z`, or `X.Y.Z-rc.1` for a pre-release, which the GitHub release marks as one).
2. The tag `v<version>` does not exist, here or on origin.
3. The working tree is clean.
4. `HEAD` is the tip of `origin/main`, as origin reports it.
5. CI is green on that commit.
6. `CHANGELOG.md` has a `## [<version>] - ` heading with an entry under it, and the heading's date is today. A release
   pull request that merged days ago fails here: edit the date through a pull request, merge it, and run again.
7. `gh` is logged in as `Gcob`.
8. `gh` reads the repository `origin` is configured with: the `owner/repo` it names must equal the one parsed from
   `origin`'s address, and the release links are pinned with it.
9. `[Unreleased]` is empty at `HEAD`. The tag goes on the tip of `origin/main`, so nothing may merge between the release
   pull request and the tag. A change that merged after it would be tagged with no entry, and this check is red until it
   is written into the version's entry.

**`--publish` then asks a person.** With every check green, it shows who the tag will be created by (the git identity of
the checkout) and waits for the version to be typed at a terminal. The identity is shown rather than checked because a
public script cannot know the maintainer's private address, and a person reading it can.

**The prompt stops a mistake, not an intention.** Without a terminal (a pipe, CI, a script that forgot the flag), or
with a wrong answer, `--publish` exits non-zero and has written nothing. That makes the real run impossible by accident.
It does not stop a process that opens a pseudo-terminal and types the version, which it knows because it is in the
command, nor a plain `git tag` and `git push`, nor `gh release create`. **Tagging is the maintainer's by rule, not by
construction**: the factory's rules forbid it, and [the agent guide](../../AGENTS.md) says never to run
`just release --publish`.

**With the right answer it does three things:**

1. Creates the annotated tag `v<version>` on `HEAD`.
2. Pushes it to `origin`. If the push fails, the local tag is removed again.
3. Creates the GitHub release, whose body is the version's changelog entry with its relative links turned into links
   pinned to the tag, `https://github.com/<owner>/<repo>/blob/v<version>/…`. An entry links to guides as they were when
   it was written, and a release page that follows `main` would show them as they are today.

**The scripts never give `gh` a `--repo`.** `gh` reads the repository from `origin`, and that is what keeps the tests
off GitHub: they point `origin` at a local bare repository and put a fake `gh` first on `PATH`, which records what it
was asked and answers from files. A test asserts that the fake saw the `release create` call and nothing else that
writes.

## After the tag

1. **Packagist picks the tag up** through the GitHub hook. Check the version on
   [`packagist.org/packages/gcob/lara-spec-first`](https://packagist.org/packages/gcob/lara-spec-first).
2. **The release's milestone closes, and every card in `Ready to publish` whose pull request merged before the tag moves
   to `Done`.** That is the board rule, and it is the factory's: a card ships in the first release tagged after the pull
   request that serves it merged, whatever its milestone, so a card planned for a later release, `1.0` included, that
   merged early shipped in this one. The milestone of `1.0.0` is `1.0`. `just status` then stops showing that release
   and shows the next one, and flags any card it left behind.

## What this document does not cover

**It does not say what goes into a version number.** That is
[semantic versioning and the changelog's own rules](../../CHANGELOG.md), and what a `1.0` will freeze is named in
[the roadmap](../../README.md#before-10-freeze-what-a-major-would-cost).

**It does not cover a CI workflow that releases.** None exists on purpose: the tag is typed at a terminal, so no
automation can create it. **It does not cover rolling a release back.** A pushed tag is not moved, and a bad version is
followed by a patch.

---
title: Contributing
audience: Contributors
covers: >
  Development environment (Docker and native), pre-pull-request checks, commit
  and pull request conventions, code of conduct, and the scope boundaries of
  the package.
read_before: Setting up the project locally or opening a pull request.
tags: [contributing, workflow, docker, testing, conventions, scope, onboarding]
---

# Contributing to lara-spec-first

Thanks for taking an interest! This document explains how to set up the project, what we expect from a
contribution, and how changes get merged.

## Project status

`lara-spec-first` is in **early bootstrap** — we are working through
[Phase 1 of the Roadmap](./docs/ROADMAP.md). The public API is not stable yet, and the package does not
do anything useful for a consumer so far. The tooling below, however, is in place and works today.

Right now, the most valuable contribution is **design feedback**. Opening an issue to challenge an
architectural decision is worth more to us today than a pull request.

## Ways to contribute

* **Discuss the design.** Especially the Spec-First routing model and the Generated vs. Extended
  controller pattern. Early input shapes the API while it is still cheap to change.
* **Report bugs.** Include your PHP version, Laravel version, and a minimal OpenAPI spec that reproduces
  the problem. A failing spec is worth a thousand words.
* **Improve documentation.** Unclear docs are bugs.
* **Submit code.** See below.

For anything non-trivial, **open an issue before writing code.** It saves you from building something we
have to turn down for reasons that were not visible from the outside.

## Development environment

**Docker is provided, not required.** You do not need PHP or Composer installed to work on this package.

### With Docker (recommended, zero local setup)

```bash
git clone git@github.com:Gcob/lara-spec-first.git
cd lara-spec-first

docker compose run --rm php composer install
docker compose run --rm php composer test
```

### With Docker and `just` (shortest)

If you have [`just`](https://github.com/casey/just), the `justfile` wraps the commands above:

```bash
just install
just test
just check
just            # list every recipe
```

**Read the `justfile` before running a recipe from a branch you did not write.** It executes shell
commands, and so do `composer.json` scripts, the `Dockerfile` and `compose.yaml` — the file carries a
security note explaining what to look for.

### Natively (if you already have PHP and Composer)

```bash
composer install
composer test
```

All three paths are first-class. Docker and `just` are thin wrappers that invoke the **exact same
Composer scripts** — neither carries logic of its own. Our CI runs the suite *without* Docker, across a
matrix of PHP and Laravel versions, so the native path is guaranteed to keep working.

## Before you open a pull request

Run the full check suite:

```bash
just check                                    # or:
docker compose run --rm php composer check
```

It covers:

| Check       | Command                | What it does                                                  |
|-------------|------------------------|---------------------------------------------------------------|
| Code style  | `composer format:check`| Reports Pint (Laravel preset) issues without writing files     |
| Static      | `composer analyse`     | Runs PHPStan via Larastan at level 8                           |
| Tests       | `composer test`        | Runs the Pest suite against a Laravel app booted by `testbench`|

Use `composer format` to apply the formatting rather than only report on them.

A few expectations:

* **New behaviour needs a test.** Bug fixes should include a test that fails before your change.
* **Match the surrounding code.** Naming, structure, and comment density should be indistinguishable from
  what is already there.
* **Keep pull requests focused.** One concern per PR. Unrelated cleanups, however welcome in principle,
  make a change harder to review and to revert.

## Commits and pull requests

We use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages:

```
feat: register routes from operationId
fix: resolve $ref in multi-file specs
docs: clarify the mocking fallback
test: cover 3.1 nullable type arrays
chore: bump testbench to 10.x
```

The prefix is not decoration — it drives changelog generation and signals whether a change is a patch, a
minor, or a breaking release.

For your pull request:

1. Fork the repo and branch from `main` (`feat/my-feature`).
2. Make your change, with tests.
3. Open the PR against `main`, describing **what** changed and **why**. Link the related issue.
4. A maintainer will review. Expect questions — they are about the code, never about you.

## Scope: what belongs in this package

`lara-spec-first` is **Spec-First**: the OpenAPI contract is the source of truth, and PHP follows from it.

Contributions that fit naturally:

* Anything that makes the spec more authoritative over the running application.
* Anything that eases migration for existing Laravel apps adopting the pattern route by route.
* Better OpenAPI coverage (`$ref` resolution, `oneOf`/`anyOf`, 3.1 features).
* Migration tooling that helps a Code-First app become Spec-First. Generating a spec from existing PHP is
  explicitly **in scope** — but as a *one-time on-ramp* (see [Phase 3](./docs/ROADMAP.md)), not as an
  ongoing workflow. Tools like `Scramble` already extract specs well; we want to build on them and on
  making that cutover verifiable, not to reimplement them.

Contributions that likely do **not** fit:

* Keeping PHP as the permanent source of truth — anything that regenerates the spec from code on every
  build, or treats the two as needing to stay in sync in both directions. That is Code-First, and it is
  the problem this package exists to solve. The spec leads; the code follows.

## Code of conduct

Be decent to each other. Assume good faith, critique the code and not the person, and remember that most
people here are volunteering their evenings. Behaviour that makes others unwelcome is not tolerated,
regardless of the technical merit attached to it.

## License

By contributing, you agree that your contributions will be licensed under the
[MIT License](./LICENSE) that covers this project.

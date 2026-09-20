---
title: Changelog
audience: Users and contributors
covers: >
    What changed in each released version of this package, and the two conventions that decide how an entry is written:
    Keep a Changelog for the shape, and semantic versioning for what a version number is allowed to promise.
read_before: Cutting a release, or working out what a version changed before upgrading to it.
tags: [versions, conventions, planning]
---

# Changelog

> **In brief**
>
> - Every released version has an entry here, newest first, patches included and
>   [nothing ever archived](#why-this-is-one-file).
> - The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) plus one section of our own, `Known limits`,
>   and the numbering is [semantic versioning](https://semver.org/spec/v2.0.0.html).
> - A `0.x` makes no compatibility promise: what a `1.0` will freeze is
>   [named in the roadmap](./README.md#before-10-freeze-what-a-major-would-cost) rather than left to be discovered here.
> - `Unreleased` holds what is merged and not tagged. Cutting a tag renames that heading and opens a new one.

## [Unreleased]

### Changed

- **`parameters` (`header`, `cookie`) moved from `Deferred` to `Ignored`** in the
  [support matrix](./docs/guide/openapi-support.md#parameters-bodies-responses). The package will not validate a header
  or a cookie parameter, so the row states a position rather than a gap, and a contract declaring one will exit non-zero
  until the consumer acknowledges it. Every move on the exit-code axis is major in either direction, which is why a docs
  change is recorded here. The doctor still counts them beside `query` under one `Deferred` label until a parameter
  becomes a rule, so nothing about today's output changed.
- **`Media type encoding` and `contentMediaType` moved up to `Partial`**, both read for what makes a
  `multipart/form-data` part a file. Moving up the ladder cannot break a contract that worked before, so this half is
  minor.

## [0.1.0] - 2026-09-11

The first release, and what [Phase 1](./README.md#phase-1-the-foundation) set out to prove: an OpenAPI contract becomes
routes and controllers, and nothing at runtime ever opens a specification.

### Added

- **Contract-driven routing.** The service provider registers the routes `spec:build` emitted, by loading one generated
  PHP file. It reads no specification, at boot or ever.
- **`spec:build`**, which resolves the contract and emits the routes plus one controller per operation. It plans every
  file in memory before writing any of them, so a document it refuses leaves the working tree exactly as it was.
- **`spec:make`**, the only command that creates a class you own, in three forms: one named operation, a whole `--tag`,
  or `--all`. It never overwrites a file that exists.
- **`spec:doctor`**, which reports what this package will and will not honor in your contract, plus the routing table
  that results. Read-only, with `--json` for CI and tooling.
- **The two-class seam.** Generated controllers are abstract and yours extend them, so a contract change becomes a
  static analysis error rather than a runtime surprise. An operation nobody has implemented answers `501` and names the
  command that implements it.
- **Remote reference vendoring.** `spec:build --update-refs` fetches an allowed reference once and commits the copy.
  Every other command reads the working tree and never the network.
- **OpenAPI 3.0 and 3.1**, read through one version strategy per minor and pinned by a conformance suite that writes the
  same contract in both and requires them to normalize identically.
- **Lifecycle and security reporting.** `deprecated: true` requires an `x-sunset`, and an operation declaring `security`
  is reported rather than enforced, in those words, because the route is registered with no authorization check behind
  it.

### Known limits

Not a Keep a Changelog section. It is here because a release that ships a gap on purpose owes it a line, and none of the
six standard sections says "this works, up to here".

- **`security` is reported, not enforced.** A contract declaring it exits `2` on every doctor run until enforcement
  lands. See [security.md](./docs/guide/security.md).
- **Four configuration blocks are inert**, and each names the phase that makes it live. Changing one has no effect
  today.
- **No response DTOs, no generated validation, no pagination or rate limiting.** All of it is
  [Phase 2](./README.md#phase-2-the-generated-pipeline).

## Why this is one file

**Every release lands here, patch included, and nothing is ever moved out.** Both halves of that get asked, so both are
answered here rather than re-argued each time.

**A patch gets an entry because a patch is the release nobody reads before taking.** Under `0.x` a breaking change lands
in a minor, which makes `0.1.1` the upgrade a consumer applies without thinking about it. That is exactly when it
matters that there is something to read if there is something to know. The entry usually costs one line under `Fixed`,
and a change with nothing to tell a consumer does not get tagged at all, so the empty entry never comes up.

**Laravel and Symfony split theirs, and the reason is not the version number.** They maintain several release lines at
once and backport fixes across them, so each branch carries its own history and earns its own file. This project
maintains one line: Phase 1, then `0.x`, then Phase 2, then `1.0`. Splitting that would produce several files describing
one straight line, and a link to a version's notes would break the day that version moved.

**So the criterion is more than one maintained line, not more than one minor.** The day a fix is backported into `0.1.x`
while `0.2.0` is already out, per-branch files start paying for themselves. Until then they cost a reader the one thing
this file is for, which is not having to work out where to look.

**And the split would be by major.** `CHANGELOG.md` would keep the current major and earlier ones would move under
`docs/`. A major is already the boundary where a consumer changes worlds; a minor is a boundary for nobody. Size on its
own is not a reason to do it: ten releases a year at twenty lines each is two hundred lines a year, and anyone who wants
one version on its own already has its [GitHub release page](https://github.com/Gcob/lara-spec-first/releases).

[unreleased]: https://github.com/Gcob/lara-spec-first/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Gcob/lara-spec-first/releases/tag/v0.1.0

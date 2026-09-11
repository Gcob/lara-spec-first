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
> - Every released version has an entry here, newest first.
> - The format is [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) plus one section of our own, `Known limits`,
>   and the numbering is [semantic versioning](https://semver.org/spec/v2.0.0.html).
> - A `0.x` makes no compatibility promise: what a `1.0` will freeze is
>   [named in the roadmap](./docs/project/roadmap.md#before-10-freeze-what-a-major-would-cost) rather than left to be
>   discovered here.
> - `Unreleased` holds what is merged and not tagged. Cutting a tag renames that heading and opens a new one.

## Unreleased

The first release, and what [Phase 1](./docs/project/roadmap.md#phase-1-the-foundation) set out to prove: an OpenAPI
contract becomes routes and controllers, and nothing at runtime ever opens a specification.

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
  [Phase 2](./docs/project/roadmap.md#phase-2-the-generated-pipeline).

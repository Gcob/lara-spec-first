---
title: Remote References
audience: Users
covers: >
    How a `$ref` pointing at a URL is handled: the domain allowlist and why it
    is empty by default, why a fetched document is treated as a dependency
    rather than a cache entry, why the vendored copies are committed and no lock
    file exists, and where the analogy with a dependency manager stops.
read_before: >
    Touching reference resolution, the allowlist, or anything that makes the
    package fetch over the network.
tags: [ openapi, dependencies, decisions, scope, compatibility ]
---

# Remote References

Every other input to the build sits in the repository. A `$ref` pointing at a URL does not, and this
document owns what the package does about that difference.

> **Mostly intent, marked per section.** Items marked `Open` are undecided.
>
> **Shipped:** the setting, and the strict half of what it means. `lara-spec-first.remote_references.allowed_hosts`
> exists and defaults to empty; every remote reference is refused before the parser can fetch it,
> which is exactly what an empty allowlist means. Naming a host **throws** rather than quietly doing
> nothing, because the fetching and vendoring behind it is not built — a setting that is read and
> ignored tells whoever set it that it took effect.

**Decision: remote `$ref` targets are resolved only from an explicitly allowlisted set of domains,
configured by the consuming application.**

An OpenAPI document that can pull `$ref: https://example.com/schemas/user.yaml` turns a config file
into a network client running with the application's credentials and network position. Two problems,
not one:

* **Security.** An untrusted or compromised spec reaches whatever the application server can reach —
  internal services, cloud metadata endpoints. The spec file is usually reviewed like documentation,
  not like code that makes outbound requests.
* **Availability.** A remote host that is slow or down becomes a boot failure for an application that
  has nothing to do with it.

An allowlist is the right shape because it is flexible where teams genuinely need it — an internal
schema registry, a shared contract repository — and closed everywhere else. Rules:

* **Empty by default.** No allowlist means no remote references. Local files keep working; a project
  that never uses remote `$ref` never sees this feature.
### The setting

| | |
|---|---|
| File | `config/lara-spec-first.php`, published with `--tag=lara-spec-first-config` |
| Key | `remote_references.allowed_hosts` |
| Default | `[]` — no host, therefore no remote reference |

**The package's defaults are merged *deeply* underneath whatever an application
published**, by [`ConfigurationMerger`](https://github.com/Gcob/lara-spec-first/blob/main/src/Configuration/ConfigurationMerger.php) rather than by
Laravel's helper. Laravel's own `mergeConfigFrom()` merges one level, which is right
for a flat file and wrong for a nested one: an application that publishes this
file and edits a single nested value replaces the whole sub-array, so every key
added to that section in a later release arrives missing — a published config
that rots a little with each upgrade, silently. Lists are the exception and are
replaced wholesale, because merging `allowed_hosts` element by element would
make a host impossible to remove, which is the opposite of what a setting named
after trust should do.

When there is more than one setting to describe, this belongs in a document of
its own rather than under whichever feature happened to need the first one.

Rules:

* **A setting that is not backed yet refuses instead of lying.** Naming a host today throws
  `NotImplementedYetException`, which names the setting, what it will do, and the roadmap item that
  removes the exception. An empty allowlist and an unimplemented one would otherwise be
  indistinguishable, and the developer who configured it would conclude the package is broken.
* **A blocked reference is an error, never a skip.** A silently unresolved `$ref` is an unhonored
  contract, which rule 2 forbids.
* **The exception names the offending reference, the document position, and the config key to
  change.** "Unresolvable reference" is a support ticket; the full triple is a fix.
* **Matching is on the host, exactly.** No wildcard subdomains, no partial matches — `evil-example.com`
  must never satisfy an entry for `example.com`.

**Status: decided in principle, unimplemented.** The config key name and the exception class are
public API surface and are not yet chosen.

## A remote reference is a dependency, not a cache entry

Repeated network calls for the same reference are waste, so something has to hold the fetched
document. The word for that something is **not cache**, and the word is the design.

A cache is expendable by definition. You may clear it at any time, it may expire on its own, and
nothing about your application changes when it does — that is the contract of the word. None of that
is true here. A remote `$ref` supplies part of your API contract: drop it and your application can no
longer describe, route, or validate what it serves. **A remote reference is a dependency**, in the
full sense the word carries in this ecosystem, and it should be handled the way dependencies are
handled: a vendored copy under version control, and an explicit act to change it.

Two things follow immediately, and each kills a config option that looked reasonable:

* **No duration.** A TTL means the contract can change at a moment nobody chose. Some Tuesday at
  14:03 an entry expires, the upstream document has moved on, and the application serves a different
  contract than it did a minute earlier — no deploy, no commit, no review. **A contract changes when
  someone ships a change, not when a timer fires.** A source of truth that varies with wall-clock time
  is not a source of truth. No dependency manager resolves your dependencies again because an hour
  passed, and neither does this.
* **No cache store.** Routes are registered while the framework boots, so whatever the registration
  reads has to be available before the container is warm. Depending on Redis to know which routes
  exist is a boot-time network dependency in the request path, for data that never changes between
  deploys — and a shared store lets two servers in the same release disagree about the contract, which
  is precisely the failure a spec exists to prevent.

### No lock file: git is the lock

The dependency analogy suggests a lock file. It should not be taken, and working out why sharpens the
whole design.

A lock file exists to pin something mutable to something exact — a version range to a resolved
version, a resolved version to a content hash. **Here there is no version to pin**: a `$ref` is a URL,
and the only thing that could be recorded is a hash of bytes we are already about to store on disk. So
the lock would restate, less usefully, what the vendored copy already is.

Less usefully, because of the review argument. A lock file diff says *a hash changed*. A vendored
document diff says *this response gained a required field*. For an API contract, the second is the
entire value, and only committed copies produce it. Git already content-addresses every file, so the
integrity check the lock was there to provide is a property of the repository, not something to
reimplement.

**Decision: the vendored copies are committed, and there is no lock file.** Two consequences to design
around:

* **The local path must encode where the document came from**, since nothing else records provenance.
  A layout mirroring host and path — one directory per host, the URL's path beneath it — is
  self-documenting, greppable, and reviewable. Fetching from the same URL twice must land in the same
  place, or the whole scheme leaks.
* **Detecting local tampering requires a refetch.** Without a recorded hash, a hand-edited vendored
  copy is caught by code review rather than by the tool. That is an honest trade, not an oversight:
  the edit does show up in a diff, and re-fetching is what the update path does anyway.

**Open.** URLs with query strings, very long paths, and case-insensitive filesystems all complicate a
path-mirroring layout. Solvable, unsolved.

### Borrowing the dependency-manager shape

The parts of the pattern worth taking, and only these:

| Piece                            | What it does here                                                                                                                                                                                                                                                                                                                                                |
|----------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| **Vendored copies, committed**   | The fetched documents, on disk, in version control. Once they exist, boot resolves everything locally and **the runtime never touches the network** — not on a miss, not on the first request after a restart, never, because there is no lookup to miss. Their diffs are how a change to your API contract shows up in a pull request instead of in production. |
| **Frozen by default**            | The build never reaches the network on its own. A fresh clone builds offline; a missing vendored copy is an error naming the flag to run, never an implicit fetch.                                                                                                                                                                                               |
| **Fetching is one explicit act** | Adding a reference and refreshing one are both deliberate, flagged operations, because both can change your contract. See [the build](./code-generation.md#remote-references-during-a-build-frozen-by-default).                                                                                                                                                  |
| **Integrity by repository**      | Upstream changed under you? The refetch produces a diff, in a commit, in a review. A remote `$ref` is third-party content that shapes your public API surface, and treating it as untrusted input is the lesson every package ecosystem learned the expensive way — git gives us that property without a mechanism of our own.                                   |

The [allowlist](#the-setting) still governs every fetch, but its threat
model shrinks to almost nothing: outbound requests now happen only inside an explicit, human- or
CI-triggered operation, never in a request.

### Where the analogy stops

We are not building a dependency manager, and the borrowed vocabulary must not drag in the rest of it:

* **No version constraints, no resolution, no solver.** A `$ref` is a URL, not a package with a
  version range. There is nothing to negotiate and no conflicts to resolve.
* **No registry, and nothing to publish.**
* **No lock file** — [git already is one](#no-lock-file-git-is-the-lock).
* **One divergence, deliberate: the vendored copies are committed.** Composer can leave `vendor/` out
  of version control because Packagist guarantees a published version is immutable. Nothing guarantees
  that about `https://example.com/schemas/user.yaml` — it can change or vanish tomorrow. Committing
  the copies is what makes an old release still deployable, and it is why no lock file is needed.

**Open.** The names of the vendored directory and of the refetch flag are public API surface under
[rule 4](./openapi-support.md#the-four-rules) and are not chosen. Also open: whether a fetched
document that itself contains remote references is followed — transitive fetching, with the allowlist
applying at every hop — or refused at depth one.

### Vendoring is one part of the build

Vendoring makes the *inputs* local. Turning those inputs into routes, controllers and validation is a
separate job, and both belong to the same command — see
[`code-generation.md`](./code-generation.md). Do not conflate the two: vendoring alone already
guarantees no network at boot, whatever the build does afterwards.

What is settled here regardless: **the doctor reads, it never writes** — it is
[read-only by contract](./doctor.md#the-contract) — and its report names which sources it read, because a doctor
that silently checks something other than what runs is worse than no doctor.

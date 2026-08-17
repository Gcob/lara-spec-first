---
title: The Contract Artifact
audience: Contributors
covers: >
    The normalized representation of the contract that the build produces: why
    comparisons are made between artifacts rather than between specification
    documents or generated code, the four rules that keep it usable, and what it
    must record that normalization would otherwise erase.
read_before: >
    Touching the artifact's shape or serialization, or anything that compares
    two versions of a contract.
tags: [ openapi, compatibility, decisions, code-generation, versions ]
---

# The Contract Artifact

Every comparison this package makes between two versions of a contract goes through one file. This
document owns what that file is and why it exists.

> **Not implemented yet.** Phase 1 of the [Roadmap](../project/roadmap.md). Items marked `Open` are undecided.

**Decision: the build produces a normalized representation of the contract — resolved, version-neutral,
containing only what the package honors — and comparisons are made between artifacts, never between
specification documents.**

Three candidates were on the table, and the reasons the other two lose are worth keeping:

**Specification against specification** is the obvious one and the trap. Two documents can describe
the identical contract and differ everywhere: extracting a schema into `components` restructures the
file without changing a promise; reordering keys changes nothing at all. And the decisive case —
**migrating a spec from 3.0 to 3.1 rewrites `nullable` into `type: [x, "null"]` and turns
`exclusiveMinimum` from a boolean into a number.** A document-level diff would report that as a
breaking change to every affected operation, and the build would fail an entire API for a migration
that changed nothing. Worse, teaching the diff to understand both spellings drags version handling
back out of the [strategy](../guide/openapi-support.md#handling-30-and-31-the-version-strategy) and into a
second place, which is the arrangement that decision exists to prevent.

**Generated code against the new specification** loses for a different reason: it is asymmetric and
lossy. You would be reconstructing a contract from PHP that was never meant to carry all of it — a
response field becoming optional, an enum losing a member, a description of a status code never reach
a signature. It also couples breaking-change detection to naming conventions, so changing how
controllers are named would look like every operation changed, and it stops working entirely the
moment a consumer [gitignores the generated tree](../guide/code-generation.md#which-generated-code-is-committed).

**Artifact against artifact** avoids both, and the reason it works is that the artifact is *already*
the boundary this documentation defines elsewhere: it is the output of the version strategy, the point
past which nothing knows
[which OpenAPI version was loaded](../guide/openapi-support.md#what-is-shared-and-what-is-version-specific).
A 3.0 document and its 3.1 translation normalize to the same artifact, so the migration produces an
empty diff — which is the correct answer.

It is also **four things we had already decided we needed, in one file**:

* The baseline for breaking-change detection.
* The *effective* contract, reviewable in a pull request — one file with every `$ref` inlined, rather
  than the fragments it was assembled from.
* What `spec:doctor` compares against. The doctor reads **both**: it derives a prospective artifact
  from the specification and checks it against the committed one, which is the only way drift and
  staleness can be detected at all. What it never does is judge the contract from the specification
  alone, so the doctor and the build cannot disagree about what the contract says.
* What the spec-driven contexts load — the mock server, contract testing — while the production
  request path still [never sees a specification](../guide/code-generation.md#the-runtime-never-sees-the-spec).

Five rules make it work:

* **Compare before writing.** The build computes the prospective artifact in memory, diffs it against
  the committed one, and only then writes. Writing first destroys the baseline, which is an easy
  implementation bug with no symptom until the day it matters.
* **What the package honors must be in the artifact.** Normalization is lossy by design, and the loss
  is exactly the blind spot: anything left out can never be protected from a breaking change. So the
  artifact grows whenever the [support matrix](../guide/openapi-support.md#the-support-matrix) does — same
  change, same commit.
* **Keyed by identity, canonically ordered — with document order recorded as data.** Path plus method
  [identifies an operation](../guide/code-generation.md#identity-is-the-path-and-the-method-not-the-name), and
  a stable serialization order is what keeps a diff small enough to read. But this package has decided
  that the specification's own order
  [decides which route wins](../guide/openapi-support.md#route-order-the-spec-files-order-is-the-route-order),
  so that order is **semantic**, and normalizing it away would let somebody move `/users/me` below
  `/users/{id}` — changing which route answers a request — and produce an empty artifact diff. The
  registration index is therefore a field in the artifact, not a property of how the file happens to be
  written. Serialization order and routing order are two different things and only one of them is
  cosmetic.
* **Versioned, and opaque.** The artifact carries its own format version so a package upgrade can
  detect an old one and regenerate rather than misread it. It is committed for review, not published
  for consumption: it is not an interchange format, and it is not the file to hand another team. Give
  them the specification.
* **Committed, and never gitignored.** It carries the same exception as the
  [vendored references](../guide/remote-references.md#no-lock-file-git-is-the-lock): `.gitignore` is the
  mechanism everywhere else, but ignoring this file removes the baseline that breaking-change
  detection depends on. `spec:doctor` checks it in the same breath as the vendored directory.

**Open.** Its name, its serialization, and its location — with the constraint that it must sit outside
any directory a consumer would plausibly ignore wholesale. Whether a stale artifact — one whose format
version predates the installed package — is regenerated silently or reported first.

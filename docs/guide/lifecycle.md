---
title: Operation Lifecycle
audience: Users
covers: >
    The `x-audience`, `x-lifecycle` and `x-sunset` extensions this package defines: how strong a promise each operation
    carries, why an operation is unstable until someone says otherwise, what `stable` costs, the sunset rules the doctor
    enforces, and the RFC 8594 headers the generated code emits.
read_before: >
    Touching the lifecycle extensions, the sunset rules, or breaking-change enforcement.
tags: [openapi, compatibility, versions, decisions, scope]
---

# Operation Lifecycle

OpenAPI can say an operation is deprecated. It cannot say how strong a promise the operation carries before that, nor
when it disappears — which is the only part a consumer can plan around. This document owns the extensions that close the
gap, and the enforcement that gives them teeth.

> **Partly shipped.** The three extension keys are read with their defaults resolved, and
> [the doctor rules over them](#the-doctor-rules-that-follow) run: a deprecation with no `x-sunset`, a date that has
> passed, a date nothing can read, an unrecognized `x-lifecycle` value (refused at read time, so it is reported under
> the doctor's Document validity section rather than its Lifecycle one), the `beta` listing, and the protection report.
> **Not built yet:** [breaking-change enforcement](#unstable-by-default-and-what-stable-costs-us) — so
> `x-lifecycle: stable` is a declaration the doctor reports on, not yet a rule that fails a build — and the
> [RFC 8594 headers](#the-runtime-payoff) the generated code will emit. Both are in the
> [Roadmap](../project/roadmap.md). Items marked `Open` are undecided.

OpenAPI can say an operation is `deprecated`. It cannot say what comes before deprecation, and it cannot say _when the
endpoint disappears_ — which is the only part a consumer can actually plan around. **Decision: the package defines three
extension keys to close that gap.**

| Key           | Where     | Value                                                                                   |
| ------------- | --------- | --------------------------------------------------------------------------------------- |
| `x-audience`  | Operation | `public` or `internal`. Absent means `public`.                                          |
| `x-lifecycle` | Operation | `beta` or `stable`. Its default [depends on the audience](#two-keys-one-discriminator). |
| `x-sunset`    | Operation | The date the endpoint stops being served.                                               |

## Two keys, one discriminator

How strong a promise an operation carries, and who it is promised to, are two different questions. Two axes, so two
keys, with the audience acting as the discriminator that sets the other's default:

| `x-audience`       | Default `x-lifecycle` | Reasoning                                                                                                                      |
| ------------------ | --------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `public` (default) | `beta`                | Somebody outside this codebase may depend on it. The stage is a claim you have to make.                                        |
| `internal`         | none                  | Same application on both ends. Requiring a lifecycle stage on every internal route is ceremony for a promise nobody asked for. |

Three constraints make this safe rather than merely convenient:

**`x-audience` itself defaults to `public`.** This is not a coin flip — it is the same principle as defaulting to
`beta`. Omission must never be the cheaper path to less protection, because omission is what happens when a spec is
imported, generated, or written in a hurry. Declaring an endpoint internal is an act; being treated as public is what
happens by default.

**A missing lifecycle is the absence of a claim, not a prohibition.** An internal endpoint can still declare
`x-lifecycle: stable`, and it can still be `deprecated` with a full
[`x-sunset` treatment](#the-doctor-rules-that-follow) — internal consumers deserve a removal date as much as anyone.
They simply do not need a promise on every route to get one.

**Demoting `public` to `internal` is reported.** This is the hole the composite otherwise opens: once a breaking change
to a `stable` operation fails the build, flipping its audience to `internal` makes the failure disappear. That may be
entirely legitimate — an endpoint really can stop being public — but it is _revoking a promise_, and a promise cannot be
revoked silently in a package built on contracts. The report names it, in the same spirit as labelling a
[non-representative run](./doctor.md#flags). Whether it merely reports or requires the same `info.version` bump a break
would is **open**.

One consequence worth having: the doctor's protection report counts **public** operations only. A monolith with two
hundred internal routes should not have its _0 of 47 public operations are stable_ finding drowned by endpoints that
were never promised to anyone.

## `deprecated` is native, and stays out of `x-lifecycle`

OpenAPI already has `deprecated: true` on an operation. Putting `deprecated` in `x-lifecycle` as well would create a
second place to state one fact — the failure this whole package exists to prevent — so it is not in the value set at
all. **`x-lifecycle` says how strong the promise is. `deprecated` says the operation is going away. They are
independent, and both can be true.**

Removing it costs nothing and buys two things. There is no agreement rule to write, because there is nothing to disagree
with. And an operation can be `stable` _and_ `deprecated`, which is not a contradiction but the normal, well-behaved
case: a promise being honored right up to its stated removal date is exactly what a good deprecation looks like.

`x-lifecycle` is then a binary, and what it adds to OpenAPI is one word the specification has no way to express: whether
an operation is promised at all.

## The doctor rules that follow

| Rule                                                               | Why                                                                                                                                                                                                                                                                                                                                                                            |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `deprecated: true` requires `x-sunset`                             | Your idea, and the strongest rule here. A deprecation with no end date is a wish. Requiring the date turns "we should remove this someday" into a commitment with a review attached.                                                                                                                                                                                           |
| `x-sunset` in the past is a finding                                | You are serving an endpoint you promised to remove. Nothing else in the system will ever notice.                                                                                                                                                                                                                                                                               |
| `x-sunset` approaching is a warning                                | With a configurable horizon — `lifecycle.sunset_horizon_days`, 90 days by default — so it lands in CI while there is still time to act. It decides what is _mentioned_, never what fails: an approaching date is reported beside the coverage report rather than as a finding, because a horizon nobody tuned must not turn a pipeline red on a day nobody committed anything. |
| An unrecognized `x-lifecycle` value is a finding                   | Extensions are untyped by nature: `x-lifecycle: stabel` is silent everywhere else in the toolchain. Refused where the document is read, so the doctor reports it under [Document validity](./doctor.md#what-it-checks) with every other refusal of that class rather than a second time here.                                                                                  |
| `beta` operations are listed                                       | The unstable surface of an API, on one screen, is worth printing even when nothing is wrong.                                                                                                                                                                                                                                                                                   |
| A `public` + `stable` operation without `operationId` is a finding | Promoting an operation to `stable` is the moment its generated class name stops being disposable. See [naming](./code-generation.md#when-operationid-is-absent-derive-from-method-and-path).                                                                                                                                                                                   |

## Unstable by default, and what `stable` costs us

**Decision: a public operation with no `x-lifecycle` is `beta`.** You cannot claim a stability guarantee by omission —
claiming one is an act. This is the right default for the same reason the [allowlist](./remote-references.md) is empty
by default: the permissive state is the one you should have to opt out of, not into.

| Value                        | Means                                  | What the build does                    |
| ---------------------------- | -------------------------------------- | -------------------------------------- |
| `beta` (default when public) | Not yet promised to anyone.            | Permissive. Change it freely.          |
| `stable`                     | A production consumer depends on this. | **A breaking change fails the build.** |
| none (default when internal) | No claim made, and none expected.      | Permissive by intent, not by neglect.  |

Orthogonal to all of them, `deprecated: true` [remains native](#deprecated-is-native-and-stays-out-of-x-lifecycle) and
can accompany any value.

`stable` is worth promoting to the moment one production consumer exists — unless that consumer knowingly signed up for
instability, which is what [`x-audience: internal`](#two-keys-one-discriminator) records.

Four consequences, because a rule that fails a build has to be right:

**1. Failing on a breaking change requires a baseline, and the baseline is the specification itself — its previously
committed version, read from git.** Not the generated code, which was never meant to carry the whole contract, and not a
separate file the build writes: the specification is the only artifact this package keeps, so there is nothing else to
compare against. `git show` against the merge base is the mechanism, in the same spirit as
[git being the lock file](./remote-references.md#no-lock-file-git-is-the-lock) for vendored references — which means the
comparison needs history to exist, and [the doctor](./doctor.md#what-it-checks) is where a shallow clone or an untracked
specification gets caught, before it is mistaken for "nothing changed".

**2. "Breaking" is directional, and the direction inverts between request and response.** This is where implementations
get it wrong, so it has to be a written table rather than a judgement call: adding a required _request_ field breaks
clients; adding a _response_ field usually does not. Removing a response field breaks them; removing an optional request
field usually does not. Widening an enum breaks response consumers and helps request senders; narrowing it does the
opposite. That table is itself public API under [rule 4](./openapi-support.md#the-four-rules) — a change to what counts
as breaking changes whose build fails — and it is large enough to deserve its own phase rather than being smuggled into
the first release.

**3. The escape hatch already exists in the document: `info.version`.** A build that only says _you broke a stable
operation_ is an obstacle. A build that says **this change requires `info.version` to go from `2.4.1` to `3.0.0`, and
will pass once it does** has turned enforcement into instruction. It needs no config, no flag and no acknowledgement
entry — the contract carries its own version, and deliberately breaking one becomes indistinguishable from publishing a
major, which is exactly what it should be. Breaking on purpose stays possible; breaking by accident stops being.

**4. The doctor must report how much of the API is actually protected.** A specification imported from elsewhere has no
`x-lifecycle` anywhere, so every public operation defaults to `beta` and the strongest rule in this document is silently
off for the whole API. _47 public operations, 0 stable_ is a finding under
[rule 2](./openapi-support.md#the-four-rules): protection that is off must never look like protection that passed.

## The runtime payoff

This is what makes these keys worth defining rather than documenting a convention: **the generated code can act on
them.** RFC 8594 standardises a `Sunset` HTTP header carrying exactly this date, and the IETF has a companion
`Deprecation` header in draft. A contract that declares a sunset can therefore produce an endpoint that announces it on
every response, to every client, without anyone writing that code.

Declared once in the spec, enforced in CI by the doctor, and advertised over HTTP by the generated controller — that is
the whole thesis of this package applied to a single field.

**Open.** The date format (`x-sunset` should almost certainly be RFC 3339, converted to the HTTP-date the header
requires); whether emitting the headers is on by default; the warning horizon; and the collision risk of a name as
generic as `x-lifecycle`, which another tool may already define differently. A vendor prefix would remove the ambiguity
at the cost of every consumer typing it.

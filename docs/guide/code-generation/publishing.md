---
title: Publishing the Specification
audience: Users
covers: >
    Why the specification the build reads is an internal document, the sanitized copy emitted for publication and the
    disk that switches it on, why the strip list denies by default rather than allowing by default, why an internal
    operation is removed outright rather than merely stripped of the key that marked it, where the public copy lands and
    why being gitignored is correct for it, and why the types a frontend consumes come from `openapi-typescript` rather
    than from this build.
read_before: >
    Touching what the build emits for publication, or deciding what an outside consumer of the API is allowed to see.
tags: [code-generation, openapi, security, scope, decisions]
---

# Publishing the Specification

> **TL;DR**
>
> - The document the build reads is internal: `x-model` and `x-controller` describe the inside of the application, so
>   publishing it hands out your database shape and your namespace layout.
> - A sanitized copy is emitted only when a project names a disk to put it on, and the keep list denies by default:
>   every extension not named is dropped.
> - An operation marked `x-audience: internal` is removed from the public copy entirely, along with the schemas, path
>   items and tags nothing references once it is gone.
> - The types a frontend consumes come from `openapi-typescript` run over that public copy, never from this build.
> - **Not built yet:** all of it. Nothing publishes anything today.

The build reads one document and the public reads another, and this file owns the distance between them: what is removed
on the way out, what switches the removal on, and what this package deliberately does not emit at all.

> **None of this is behaviour yet.** No sanitized copy is produced today, and no configuration key exists to ask for
> one. What is written here is the design the emitter will follow when [Phase 2](../../project/roadmap.md) reaches it.
> Items marked `Open` are undecided.

## The specification the build reads is private

**Decision: the specification this package consumes is an internal document, and nothing assumes it is safe to
publish.** That is not caution for its own sake: the extensions that make the build useful are precisely the ones that
describe the inside of the application.

| Extension                                                                          | What publishing it hands out                                                       |
| ---------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| [`x-model`](../controllers.md#how-the-semantic-is-detected)                        | Your Eloquent class names, so the shape of your database and its relationships.    |
| [`x-controller`](../controllers.md#the-specification-decides-what-is-customizable) | Your application's namespace layout, and which endpoints carry hand-written logic. |

Neither means anything to a consumer of the API, and both help somebody map an application they are attacking. A
document written for the build is simply not the same document as one written for the public, and treating them as one
file is how internal detail gets published by accident.

**Decision: `spec:build` can emit a sanitized copy for publication, and does so only when a project names a disk to put
it on.** Not by default, in the same spirit as the [remote-reference allowlist](../remote-references.md) and the factory
scan: a feature nobody asked for should not start writing files.

**The strip list denies by default rather than allowing by default.** Configuration says which extensions to _keep_, not
which to remove, and every other `x-` extension is dropped. The reverse would fail the day a project adds an extension
of its own and forgets to list it, which is exactly when the failure costs the most and is least likely to be noticed.
This is the same posture the allowlist takes for hosts, applied to information disclosure.

The default keep list is the extensions that tell a consumer something they can act on:
[`x-lifecycle` and `x-sunset`](../lifecycle.md) exist so a client can plan around how strong a promise is and when it
ends, so stripping them would remove the one part of this package's own vocabulary the public document should carry.

**`x-audience` is deliberately not on that list**, even though it is a consumer-facing extension elsewhere. Once
[internal operations are removed outright](#internal-operations-are-excluded-not-merely-stripped), every operation left
in the published copy is `public` — so the key would publish a constant, and a constant tells a reader nothing. It is
the exclusion that carries the information, not the annotation that survived it.

Two properties hold it together:

- **The public copy is output, never input.** The build reads the private document and nothing else, so there is never a
  question of which one is authoritative. It is generated, so it belongs to the build, carries a header saying so, and
  is never hand-edited — the same rule as
  [every other generated file](./index.md#three-kinds-of-file-and-only-two-are-the-builds).
- **The doctor reports what is being removed.** A strip list is a security boundary, and a security boundary nobody can
  see is one nobody maintains. Printing the resolved keep list, what it dropped, and how many operations were excluded
  turns "did we publish our model names" into a one-command answer rather than an audit.

### Internal operations are excluded, not merely stripped

Stripping a key and dropping an operation are different acts, and the weaker one is not enough. Removing
`x-audience: internal` from an operation still publishes the operation, which invites exactly the outside consumer the
extension existed to say there wasn't one.

**Decision: the public copy carries `public` operations only. An operation marked
[`x-audience: internal`](../lifecycle.md#two-keys-one-discriminator) is removed from it entirely.**

`public` being the default means an operation that says nothing gets published, and that is deliberate rather than
convenient: it is the direction [`lifecycle.md`](../lifecycle.md#two-keys-one-discriminator) already set for this key,
where declaring an endpoint internal is an act and being treated as public is what happens by omission. One key, one
default, and two documents that agree about it.

**Removing the operation is not enough on its own, and this is the part an implementation will get wrong.** An excluded
operation leaves things behind that still describe the inside of the application:

- **Schemas nothing references any more.** Drop `POST /internal/audits` while `components/schemas/AuditPayload` stays
  and the internal data shape is published anyway, so the exclusion accomplished nothing. **Components left unreferenced
  once internal operations are gone must be pruned**, and the pruning has to be transitive: a removed schema can orphan
  the schemas it referenced in turn.
- **Path Items with nothing left inside.** Every operation on a path being internal leaves an empty object rather than
  no path, which names an endpoint while claiming it has no methods.
- **Tags used only by internal operations**, left dangling at the document root.

**Two edges worth naming rather than discovering.** A document whose every operation is internal produces a public copy
with no operations at all — valid at 3.1, invalid at 3.0 where `paths` is
[required](../openapi-support.md#the-differences-the-strategy-must-absorb), and in either case far more likely a
misconfiguration than an intent, so it is a finding rather than a file. And exclusion gives
[breaking-change detection](../lifecycle.md#unstable-by-default-and-what-stable-costs-us) a second reason to care about
this key: flipping an operation to `internal` removes it from the published document, which is the most breaking change
there is for whoever was already calling it. `lifecycle.md` already says that flip must be reported rather than pass
quietly.

### Where the public copy goes

**Decision: configuration names a filesystem disk and a path within it. The disk is the switch: it is `null` out of the
box, and nothing is published until a project sets it.** A disk rather than a bare path, because that is Laravel's own
abstraction for "where files go" — the same setting then publishes to local storage, to S3, or to whatever a project
already has configured, without this package knowing the difference. The path has a shipped value because a path with
nothing to put it on is not a decision anybody has to make.

**The disk to reach for is `public`, and it is not the one Laravel would pick for you.** Laravel's own default disk is
`local`, rooted at `storage/app/private` and deliberately unreachable over HTTP — a file published there exists and
answers 404. Serving the document means the `public` disk, rooted at `storage/app/public`, and it means
`php artisan storage:link` has been run. Naming both the disk and its prerequisite is cheaper than letting somebody
discover the 404.

**Landing under `storage/` is right here for exactly the reason it was wrong elsewhere.** A stock Laravel application
ships a `storage/app/.gitignore` containing `*`. For a file that must be committed that is a trap, which is why the
contract baseline is [read from git](../lifecycle.md#unstable-by-default-and-what-stable-costs-us) rather than kept
there. The public copy is the opposite case: it is derived, the build reproduces it exactly, and committing it would
mean reviewing a generated diff on every contract change. Being gitignored is the correct outcome for it.

Two consequences to state rather than let anyone hit:

- **A remote disk means the build writes over the network.** That is not what
  [frozen by default](./index.md#remote-references-during-a-build-frozen-by-default) forbids — that rule protects the
  build's _inputs_, since an input fetched silently can change the contract, and an output written somewhere cannot. But
  the asymmetry is worth naming so nobody reads it as an oversight, and a project may reasonably decide publishing
  belongs to its deploy step rather than to `spec:build`.
- **A published copy can go stale.** Edit the specification, forget to build, and the document being served describes a
  contract the application no longer honors — publicly, which is worse than the internal version of the same mistake. It
  is the same failure the [drift check](../doctor.md#what-it-checks) already exists for, and the published copy belongs
  in its scope.

**Open:** the config key names, whether the sanitized copy is emitted in the document's own format or normalized to
JSON, and whether an operation's `summary` and `description` need a keep-or-strip decision of their own — internal notes
end up in those fields far more often than anyone intends.

### The frontend gets its types from `openapi-typescript`, not from this build

The component consuming an operation reads the same schema the build already reads, and gets `any`. It is a real gap and
it is the obvious next emitter to reach for. **Decision: this package does not emit it. A project that wants typed
requests and responses in the browser runs [`openapi-typescript`](https://openapi-ts.dev) over its own document, and the
[public copy](#where-the-public-copy-goes) is exactly that tool's input once it exists.**

This is a Laravel package, and its output is PHP. Emitting TypeScript would put Node in the path of `spec:build`, which
means a build failing on a deploy machine for a reason that has nothing to do with the contract — the development image
is `php:8.3-cli-alpine` with no Node in it, which is already why
[Markdown formatting is the documented exception](../../contributing/documentation.md#formatting) that runs outside the
container. It would also mean a schema-to-type mapping to write and maintain for a language this project does not build
in, next to a good tool that already has one.

**The gap that leaves is worth naming rather than glossing over.** Two tools read one document, so nothing compares what
the TypeScript says with what the generated PHP says, and the two can describe one operation differently. Running
`openapi-typescript` against the document this package reads is what keeps that distance at zero, which is the whole
reason to point at the contract rather than at a hand-written client.

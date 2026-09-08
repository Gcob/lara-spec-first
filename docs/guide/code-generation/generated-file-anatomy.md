---
title: Anatomy of a Generated File
audience: Users
covers: >
    What the build writes into a file besides the code: the JSON pointer it came from and why that path is named from
    the project root, the docblock norm every emitter owes a reader who did not write the file, why no generated output
    records a time, and the comment that sits above a reference to generated code saying what to do when that class goes
    missing. Also how a generated class gets its name, why an operation's identity is its path and method rather than
    that name, why rename and orphan detection was built and then removed, and what the build derives when `operationId`
    is absent.
read_before: >
    Writing or changing anything that emits a file, or reading a generated file and wondering what its header is
    claiming.
tags: [code-generation, openapi, decisions, laravel]
---

# Anatomy of a Generated File

> **TL;DR**
>
> - Every generated file carries the JSON pointer it came from, named from the project root rather than absolutely,
>   emitted in every mode.
> - The docblock is a requirement rather than a courtesy: provenance, what the build worked out, and what runs instead
>   of this file.
> - Nothing generated records a time. Git already answers when, and a clock would make two identical runs differ.
> - A reference to generated code carries a comment saying what to do when that class is not there.
> - A name comes from `operationId`, but identity is the path and the method, which is why rename detection was
>   designed, built, and removed before it shipped.

Generated code is read by people who did not write it. This file owns everything the build puts in a file to serve that
reader, and the naming rules that decide what the file is called in the first place.

> **This is shipped behaviour**, unlike most of what surrounds it: the source map, the docblock norm and the reference
> comment landed with the first emitter and are asserted by the generators' own tests. What a finding can say will grow
> with what the build knows. Items marked `Open` are undecided.

## The source map

**Decision: every generated file carries the JSON pointer it came from** — the operation, the schema, the exact position
in the specification.

**And the file it points into is named from the project root, never absolutely.** Whether a project commits its
[generated tree](../glossary.md#generated-tree) is [its own choice](./index.md#which-generated-code-is-committed), so an
absolute path is a defect waiting for the first project that does: it differs between every developer and every CI
runner, which is a diff nobody made, and it publishes one machine's directory layout — a username included — into a
repository. The root is the nearest ancestor holding a `composer.json`, which for an ordinary application is the same
directory as `base_path()` and stays correct where the two differ. A specification genuinely outside any project keeps
its absolute path, because there is no shorter honest name for it.

It is the same idea a bundler's source map serves, and the same need: generated code is read by people who did not write
it, and the first question any of them has is _where did this come from?_ A developer debugging, a reviewer judging a
diff, an AI agent working in the repository — all three are one annotation away from the contract instead of grepping
for it.

It costs nothing at runtime in PHP, so it is emitted **unconditionally**, in every mode. That is what keeps
[watch and build output identical](./scaffolding.md#borrowing-from-bundlers-and-where-to-stop), and it is why this is
not a development-only nicety.

**It is for readers, not for tooling, and that is a narrowing worth recording.** An earlier version of this document
justified the annotation partly by what a build could do with it —
[comparing pointers between builds](#rename-and-orphan-detection-decided-against) to report renames — and that feature
is decided against. The pointer stays, because answering _where did this come from_ was always the larger half: a
developer debugging, a reviewer judging a diff, and an agent working in the repository are all one annotation away from
the contract instead of grepping for it.

One future decision still leans on the same identity: breaking-change detection is keyed by it, so a finding in that
comparison and a header in a generated file will name the same thing.

## Every generated file explains itself

The source map answers _where did this come from_. It is one part of a larger norm, and this section owns the whole of
it.

**Decision: every file the build emits carries a docblock written for someone who did not write it, and it is a
requirement rather than a courtesy.** Not a banner saying "generated, do not edit" and nothing else — that says who owns
the file, which is [already settled elsewhere](./index.md#three-kinds-of-file-and-only-two-are-the-builds), and it is
not what a reader opening the file actually needs.

**This package optimizes for AI-assisted development as a stated goal, not as a side effect.** Developer experience is
the other half, and the two pull in the same direction here far more often than they conflict: what a coding agent needs
is what a new team member needs, made explicit instead of assumed. An agent reads a handful of files, not a codebase; it
cannot infer a convention from ten sibling examples the way a person skimming a directory can; and it has no way to know
that the interesting behavior lives in a class three directories away unless the file says so. Every guess it has to
make is a chance to write something plausible and wrong — into your application. So the generated file states what would
otherwise have to be guessed.

Three things belong in that docblock, and each answers a question a reader actually has:

| Part           | Answers                                  | Content                                                                                                                            |
| -------------- | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Provenance** | Where did this come from?                | The [JSON pointer](#the-source-map) into the specification — the operation, the schema, the exact position.                        |
| **Findings**   | What did the build work out, or resolve? | What generating this file discovered and decided: what matched, what did not, which defaults were resolved, what looked ambiguous. |
| **Navigation** | Where do I go from here?                 | `@see` to the files this one relates to — above all, to the extension point's actual implementation.                               |

**Findings are the part that has to stay honest.** A summary that says nothing costs a reader the time it takes to
discover that. What earns its place is what the build knew and the reader cannot see: a property the mapping could not
match, a default that was resolved rather than declared, a name derived because `operationId` was absent, two things
that collided. If generating a file was entirely unremarkable, the docblock says so in one line rather than padding
itself — a reliable "nothing to flag here" is information too, and it is only reliable if the interesting cases are
genuinely called out.

**Navigation follows one rule: point at what actually runs.** Where a generated file has an extension point, the
docblock either names the command that creates it or `@see`s the code that already did:

- **Nothing extends it yet:** name the `spec:make` invocation that scaffolds one. Discovering the extension point should
  never require reading this documentation first. It is
  [the same pattern](./scaffolding.md#the-build-names-the-command-instead-of-running-it) as the build naming commands
  rather than running them.
- **Something extends it:** the scaffold instruction is replaced by `@see` at the file the build detected — so a reader,
  human or agent, lands on the behavior that actually executes rather than studying a generated default that has been
  overridden.

That second case is what makes the norm worth the effort. A generated default and the class that replaced it are the
single most common way to misread this kind of codebase, and one annotation removes the mistake entirely.

### What a generated file deliberately does not carry: the time

**Decision: nothing in generated output records when it was generated.** No timestamp, no `created_at`, no `updated_at`.
Written down as a decision rather than left as an absence, because it is the first thing anybody proposes adding and it
looks harmless.

**A timestamp breaks idempotence outright.** The build compares contents to decide whether to write, so a clock in the
file means different bytes every run: every file rewritten every time, `changedNothing()` never true, and — for a
project that tracks its generated tree — a diff in every file on every build. It is the same defect as
[an absolute path](#the-source-map) with a worse blast radius, since a path only diverges between machines while a clock
diverges between two runs on one machine. It would also foreclose a genuinely useful gate: `spec:build` followed by
`git diff --exit-code` is how a pipeline checks the tree is current until [the doctor](../doctor.md) can, and a
timestamp makes that check fail always, which is the same as it saying nothing.

**`created_at` is worse, for a different reason.** Preserving it would mean the build reading its own previous output to
recover a date, so what it emits would depend on what was already there rather than only on the contract. Two things go
with that: the same specification would produce different files on a fresh clone than on an existing checkout, which is
reproducibility gone; and the file would carry a fact the specification cannot express, held only in a tree
[a project is free to delete](./index.md#which-generated-code-is-committed). A small database in the one place this
document calls disposable.

**Both fields already exist, and more accurately than a comment could state them.** `updated_at` is the file's
modification time, and it means something _because_ the build leaves unchanged files alone: it says when the content
last actually changed, not when a command last ran. `created_at` is git, with the author and the diff attached. This is
the same reasoning that makes [git the lock file](../remote-references.md#no-lock-file-git-is-the-lock) for vendored
references and [git the source of the contract baseline](../lifecycle.md#unstable-by-default-and-what-stable-costs-us):
the repository already records time, and reimplementing that inside a generated comment would be a worse copy of it.

**And if the worry behind the question is staleness, a date does not answer it.** A file written yesterday can be
perfectly current, and one written a minute ago can be stale if the specification moved since. What answers it is
comparing against the contract, which is [the drift check](../doctor.md#what-it-checks).

**It is testable, and it should be tested.** The docblock is output, so the generator's own test suite asserts it is
there and carries all three parts — the same way
[any other behavior earns a test](../../../AGENTS.md#automated-tests-are-required). A norm that only lives in prose
erodes the first time someone adds a new kind of generated file in a hurry.

**Open:** how much of this is a fixed template versus per-kind, and whether the findings section has a machine-readable
form. The doctor already learned that lesson — its `--json` exists because
[tooling and agents should not have to parse prose](../doctor.md#the-contract) — and the same argument plausibly applies
here, against the cost of putting a data format inside a comment.

### A reference to generated code says what to do when it goes missing

**Shipped, both halves.** The build writes it into the generated `routes.php`, which imports every generated controller,
and `spec:make` writes it above the class it scaffolds — the one reference that command creates, and therefore the one
it annotates.

A class-not-found on generated code is the most likely error anyone meets with this package, and the least informative
one PHP knows how to raise. **Decision: every reference to generated code carries a comment saying what to do about
it**, grouped above the block rather than repeated over each line — four generated references in one file should not
mean four copies of one paragraph.

**Two commands write that comment, and neither may write the other's files.** The build writes it into generated files
that reference other generated files. Only [`spec:make`](./scaffolding.md#scaffolding-is-specmake-not-a-build-step)
writes it into a file a developer will own, once, at the moment it creates that file — the build
[never writes outside its own directories](./index.md#the-invariant-a-build-never-destroys-human-work), and that rule
has no exception for a helpful comment. From then on the comment belongs to the developer, including the freedom to
delete it.

**The comment sits where the reference is, which for a scaffolded controller is not an import.** Because a custom
controller
[extends its generated parent by fully-qualified name](../controllers.md#two-classes-found-by-name-rather-than-by-a-scan),
there is no `use` statement to annotate — so the comment goes above the class:

```php
namespace App\Http\Controllers;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`
// (or `spec:watch`). If it still fails, the specification no longer has an
// `x-controller` pointing here. Note that the spec's git history will show what changed.
class UserController extends \App\Http\Generated\Controllers\UserController
{
}
```

That is the one reference `spec:make` created, so it is the one it annotates. Anything a developer imports afterwards —
a DTO, a factory — they added knowingly, and a comment explaining their own import back to them is noise. In a generated
file importing other generated files the same comment applies above the `use` block, minus the `x-controller` line,
since a DTO's name follows its schema rather than that extension.

**What the generated `routes.php` carries today is that comment, minus two names.** `x-controller` is left out for the
reason above — a route points at a generated class — and `spec:watch` is left out because it does not exist yet, on the
same grounds as [naming `spec:make`](./scaffolding.md#the-build-names-the-command-instead-of-running-it): printing a
command nobody can run would be worse than saying nothing. Both lines are owed once the features behind them ship, and
the emitter's tests assert their absence so that the debt is visible rather than forgotten.

Three situations sit behind those three lines, which is why the first answer is a command rather than an explanation:

- **The build has not run here.** On a fresh clone this is the normal state rather than a mistake, because
  [`.gitignore` decides what is committed](./index.md#which-generated-code-is-committed) and a project may legitimately
  ignore the generated tree — the `composer install` bargain, stated in [two layers](./index.md#two-layers). Running it
  is the whole fix.
- **The name changed in the specification.** A controller's generated name follows
  [`x-controller`](../controllers.md#the-specification-decides-what-is-customizable) and a DTO's follows its schema
  name, so the class moved because somebody edited one of those. Running the build writes the class under its new name,
  and the new name is the one that edit chose — the build
  [does not report the change](#rename-and-orphan-detection-decided-against), because the person reading this comment is
  the person who made it.
- **It was removed outright.** Only here does the build have nothing to offer, because there is no new name to report,
  and the specification's own history is what says what happened.

It is also, deliberately, the last line of defense rather than the first. [The doctor](../doctor.md) reports drift and
orphans before anyone reaches a stack trace; this comment is for the developer who met the error first and has not
thought to run it yet.

## Naming, and the rename problem

**This section's original premise is gone, and saying so is the point of keeping it.** It used to read: the generated
class name comes from `operationId`, which makes an `operationId` far more than a label, because **it is the name of the
class a developer extends** — so renaming one in the spec renames a class in their application. That was true, and the
[`x-controller` seam](../controllers.md#the-specification-decides-what-is-customizable) is what made it false. An
extendable class is named by `x-controller` and by nothing else; every other generated controller is `final`, so no
import may depend on its name. **A name a project can depend on can now only change when the project's own author edits
`x-controller`.**

The position that produced the section still holds: **designing an API is a skill, and changing an identifier is a
versioning decision.** What changed is who is exposed to it, and the answer is now "whoever typed the new name".

### Identity is the path and the method, not the name

An operation's **identity** is its path plus its HTTP method, which is what actually addresses it. Its **name** is what
the build generates from. The distinction earns its keep in two places that have nothing to do with each other:
[refusing two operations that address one endpoint](../openapi-support.md#reading-a-document), and keeping a rename of a
path _parameter_ out of everything that compares operations.

Identity is therefore normalized: **the names of path parameters are not part of it.** Renaming `/users/{id}` to
`/users/{userId}` changes nothing a client can observe — the URL on the wire is identical, and the template variable is
documentation. So identity is the method plus the path with its parameters reduced to positions. It also means
`/users/{id}` and `/users/{slug}` share an identity and collide — which is correct, because those two routes already
collide in the router, and surfacing it is a service rather than a limitation.

### Rename and orphan detection: decided against

**Decision: the build does not compare the previous build's output against the new one, and does not report renames or
orphans.** It was designed here, built, and removed before it shipped. The reasoning for removing it is worth more than
the feature was:

- **The premise expired.** Comparing pointers earns its complexity only when a name a project depends on can change
  without that project's author renaming anything. That was the world where an extendable class was named from
  `operationId`. Today an extendable name comes from `x-controller` alone, and every other generated class is `final` —
  so the only class-not-found this could have predicted is the one that follows an edit the developer just made
  themselves.
- **What it would still have caught belongs to the developer.** Remove an operation from the contract and the custom
  controller that extended its parent extends nothing. That is a consequence of deleting the operation, and deciding
  what happens to their own class is the developer's call, not a report's — the same position this subject takes on
  [a specification you do not control](../controllers.md#specmake-is-the-only-way-in) and on
  [identifier changes being versioning decisions](#naming-and-the-rename-problem).
- **It could never have been a guarantee.** The mechanism reads the previous build's own output, and whether that output
  exists is [the consumer's `.gitignore` choice](./index.md#which-generated-code-is-committed). On a fresh clone there
  is nothing to compare against, so the report is silent exactly where a CI check would have wanted it — a feature that
  works in the loop where you already know what you just changed, and not where you do not.

**The honest limit that remains, stated because it is what a reader would otherwise go looking for:** when a path moves,
identity and name change together, and no comparison could have told a moved operation from a deleted one anyway.

What does survive from that design is
[the reference comment](#a-reference-to-generated-code-says-what-to-do-when-it-goes-missing) a generated file carries,
which is the cheap half of the same job: it puts the instruction where the error will be read, without the build having
to predict anything.

### When `operationId` is absent, derive from method and path

**Decision: the fallback is the operation's HTTP method and its path.** There is nothing else that both exists on every
operation and means something to a reader. `GET /users/{id}` becomes `GetUsersIdController`.

**Revised, and the revision is worth naming rather than hiding.** This rule used to say the _normalized_ path, so that
parameter names were excluded and renaming `{id}` to `{userId}` could not rename a class. What removed that cost was a
later decision: a class with no `x-controller`
[is `final`](../controllers.md#the-specification-decides-what-is-customizable), so nothing may extend it and no import
can depend on it. Nobody can be hurt by a name nobody may reference, and what is left is that `GetUsersIdController`
tells a reader which endpoint it serves where `GetUsersParamController` does not. **Identity stays normalized
regardless** — that is a different question, asked for rename detection rather than for naming, and the two must not be
conflated.

The objection to raise and dismiss: deriving from the path means that reorganizing URLs renames classes. True — and
**proportionate**, because changing a path _is_ a change to the contract. Consumers have to update their calls; you
having to update a class name is the same event, visible in your own code. For a `stable` operation the build already
refuses the change until [`info.version`](../lifecycle.md#unstable-by-default-and-what-stable-costs-us) says so, and for
a `beta` one churn is what `beta` means. The case that would have been unfair — renaming a path _parameter_, which
changes nothing on the wire — is already excluded by normalizing identity.

What the fallback genuinely costs is readability: a derived name will never read as well as `listActiveSubscriptions`.
That is an argument for writing `operationId`, not against having a fallback, and it is the kind of nudge the doctor
should make rather than the build enforce.

**Decision: `operationId` is required on `public` + `stable` operations, and optional everywhere else.** A stable
operation's generated class name is a promise made to your own codebase, so it deserves to be chosen rather than
computed — while a `beta` or `internal` operation can be sketched without ceremony. The rule reuses the
[lifecycle](../lifecycle.md#unstable-by-default-and-what-stable-costs-us) vocabulary instead of inventing one of its
own, and it lands where it costs least: nobody meets it while exploring, and everybody meets it at the moment they
promise an endpoint to someone.

It also means promoting an operation to `stable` is the moment its name gets chosen deliberately — which is exactly when
a derived name would otherwise harden into something nobody picked and nobody can now change without a major version.

**Open:** collisions between two `operationId` values that differ only in characters PHP cannot use in an identifier,
and whether the build refuses them outright.

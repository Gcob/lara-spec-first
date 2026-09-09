---
title: Scaffolding & Watching
audience: Users
covers: >
    The command that creates a class you will own, what it refuses to do and why each refusal protects something the
    build promised, what an operation nobody has implemented answers in the meantime, where a scaffolded class lands and
    what happens when the project cannot place it, why scaffolding is not a flag on the build, why the build names the
    command to run instead of running it, and the watch loop that rebuilds on change.
read_before: >
    Changing what `spec:make` writes, what an unimplemented operation answers, or what the build prints at the end of a
    run.
tags: [code-generation, openapi, decisions, scope, laravel]
---

# Scaffolding & Watching

> **In brief**
>
> - The build never creates a class you will own. `spec:make` does, when you ask it to, and it never overwrites a file
>   that already exists.
> - An operation nobody has implemented answers `501` and names both itself and the command that implements it, rather
>   than a `404` or a class nobody can find.
> - A scaffolded class goes where PSR-4 says it goes, and a namespace your project does not map is refused by name
>   rather than guessed at.
> - The build tells you which commands to run instead of running them, and keeps it short; the doctor holds the full
>   list.
> - **Not built yet:** `spec:watch`.

Two commands produce something you are meant to edit, which is what separates them from the build. This file owns both,
plus what the contract answers for an operation nobody has got to yet.

> **`spec:make` is shipped in its three forms** — one operation, `--tag=`, and `--all` — and so is the `501` an
> unimplemented operation answers. `spec:watch` is [Phase 2](../../project/roadmap.md) and does not exist. Items marked
> `Open` are undecided.

## Scaffolding is `spec:make`, not a build step

**Decision: the build never creates a class you will own. `spec:make` does, on request.**

**Shipped, in three forms.** `spec:make showUser` scaffolds one operation's custom controller — named by its
`operationId`, or by its method and path for an operation that has none: `spec:make "delete /legacy"`. `--tag=Users` and
`--all` are loops over that, and both list the files they would create and ask before creating any. What it writes is
the class [`x-controller`](../controllers.md#the-specification-decides-what-is-customizable) names, extending that
operation's generated parent, in [the file PSR-4 says it belongs in](#where-your-classes-go) — and then it runs the
build, because [the `extends` has nothing to reach until it does](../controllers.md#specmake-is-the-only-way-in).

Three refusals are worth naming, because each of them protects something this subject promised elsewhere:

- **A file that already exists is left exactly as it is**, and the command says so and succeeds. A developer asked for
  the class to exist and it does; overwriting it is the one thing this command must never do, and the check that
  prevents it runs immediately before the write rather than only at planning time — a confirmation prompt is long enough
  for somebody to have created the file in another window.
- **An operation with no `x-controller` cannot be scaffolded**, because its generated controller is `final` and nothing
  may extend it. The command [offers a name to write](../controllers.md#specmake-is-the-only-way-in) — derived from the
  configured controller namespace, prefilled so it can be edited, with the exact line named — rather than sending a
  developer to read the documentation to learn the key's name.
- **A class in a namespace the project does not map** is refused, naming it. A path invented from the namespace by
  convention would produce a file that compiles, that the autoloader never finds, and whose route answers with a
  class-not-found for a reason nothing in the project states.

**And a non-interactive run writes nothing it would have asked about.** Artisan answers a prompt with its default when
nobody is at the keyboard, so a script gets "created nothing" rather than a contract's worth of empty classes and a
specification nobody agreed to edit. **`--yes` is how a script says yes**, taking the proposal for every question the
command would have asked — and changing nothing else: the insertion still verifies itself, an existing file is still
left alone, and a name the project cannot place is still refused. It is not `--force`, because
[that word already means overwrite](../controllers.md#specmake-is-the-only-way-in) and this command never does.

Laravel already has this shape and every Laravel developer already has the reflex: a `make` creates one file, when you
ask, once. Reusing the word costs no new concept — and it removes the only exception the
[invariant](./index.md#the-invariant-a-build-never-destroys-human-work) ever had.

Nothing forces the build to do it instead, because an operation with no implementation is not a broken application —
provided the package says what happens to it.

### An unimplemented operation answers 501

**Decision: the build registers the route and points it at a package-provided handler that returns
`501 Not Implemented`**, with a body naming the operation and the `spec:make` command that implements it.

The two alternatives are worse, and for reasons this subject has already committed to:

- **Not registering the route** would mean the contract describes an endpoint that does not exist, and a client would
  get a `404` indistinguishable from a typo. That is [rule 2](../openapi-support.md#the-four-rules) violated at the
  level of the wire: the spec says the endpoint is there, and nothing anywhere says otherwise.
- **Pointing at a class that does not exist** produces a class-not-found fatal at request time — an internal error
  blaming the consumer's application for a state the package created on purpose.

`501` is the status code HTTP already has for exactly this: the server recognizes the request and has not implemented
it. It is honest to the client, it is greppable in logs, and it is the seam the [Faker mock](../../project/roadmap.md)
plugs into in Phase 2 — same route, same handler position, a better answer in the body. Nothing about the Phase 1 shape
has to change for the mock to arrive.

This is what an operation gets when the build could
[detect no CRUD semantic for it](../controllers.md#how-the-semantic-is-detected) — no `x-model`, or a shape the package
refuses to guess at — and nobody has overridden it. An operation the build did understand answers from a generated
default with no subclass at all; [`controllers.md`](../controllers.md) owns which is which.

### Where your classes go

**In the application's own controller location, not in the generated directory.** Two reasons, and the first is not a
matter of taste:

- **`.gitignore` works by directory, and we made `.gitignore`
  [the mechanism](./index.md#which-generated-code-is-committed).** Put your classes inside the
  [generated tree](../glossary.md#generated-tree) and a consumer who ignores that tree loses their own work. That single
  fact rules the option out.
- **It is an ordinary Laravel controller.** Once the file exists it has nothing to do with this package except that it
  extends a generated class. Your conventions, your IDE, your tests and your `make:` habits all already point at that
  directory. The generated parent is the unusual object here; the concrete class is not.

**A class inside the generated tree is refused rather than merely discouraged.** An `x-controller` naming one is a build
error, because the generated parent takes that same short name there — so the class would extend itself — and because a
build rewrites everything under that namespace.

**The consequence to state plainly:** the generated route refers to your class by its fully-qualified name, so the name
and namespace are load-bearing. Moving the file is fine; moving it somewhere it no longer autoloads under the expected
name breaks the route. The [doctor](../doctor.md) reports that as a missing implementation rather than letting it
surface as a class-not-found at runtime.

### Not a flag on `spec:build`

`spec:build --make` is the tempting shortcut, and the analogy that suggests it does not survive contact.

`make:model --controller --migration` creates several files **for one thing you just named**: one subject, one
invocation, a human present. `build` does not operate on an operation you named — it operates on the whole
specification. So `spec:build --make` means _scaffold every missing implementation_, which is how a hundred empty
classes get committed by accident.

The deeper cost is that it makes the invariant conditional again: _the build never writes a file it does not own, unless
you pass `--make`_. The architecture test stops being absolute, and the next feature has a precedent to point at.

The counter-argument is real and worth recording, because it comes from this subject's own logic: a flag typed by a
human **is** explicit intent, exactly as [watch](#watching-specwatch) is. But that is precisely why watch is a separate
command rather than a flag — a flag ends up in a Procfile or a deploy script, and then it is creating files unattended.
Same risk, same answer. It is also why watch cannot scaffold either: watch must never produce output `build` would not.

### The build names the command instead of running it

**Shipped.** What the shortcut was really asking for is ergonomics, and those can be had without touching the invariant.
**When the build finds operations with no implementation, it names the command rather than running it** — the same
pattern as the
[reference comment naming the command to run](./generated-file-anatomy.md#a-reference-to-generated-code-says-what-to-do-when-it-goes-missing).

**The atomic form names one operation, and every other form is sugar over it:** `spec:make showUser` scaffolds
[one controller](../controllers.md#one-controller-per-operation-one-method-named-routeaction), carrying whichever
[CRUD default its own specification implies](../controllers.md#how-the-semantic-is-detected). Nothing else in this
package creates a grouped file, so there is nothing a bulk invocation could produce that is not simply this, run several
times.

The trap is printing one line per operation. A specification with two hundred operations, on the day somebody adopts
this package, would answer with two hundred commands — which is not a list, it is a wall, arriving at the worst possible
moment. So the build **summarises, and the [doctor](../doctor.md) holds the full list**, which is the division of labour
those two commands already have.

It summarises **by `tags`**, because the specification already carries the author's own grouping and inventing a second
one would be worse than using theirs — this is a grouping of the _printed list_, never of the files `spec:make` creates,
each of which stays
[one controller for one operation](../controllers.md#one-controller-per-operation-one-method-named-routeaction):

```
47 of 52 operation(s) have no implementation and answer 501.
  Users (12)      php artisan spec:make --tag=Users
  Orders (8)      php artisan spec:make --tag=Orders
  ... and 5 more tag(s).
  untagged (3)    php artisan spec:make showLegacyReport
```

Three details of that output are decisions rather than formatting. **An operation whose custom controller exists is not
counted**, because it is answered — warning about it would tell a developer their own class does not count. **An
untagged operation gets the atomic form named for it**, with one operation's own name, because no `--tag` would ever
reach it and a grouping it is not in is not a grouping. And **the full list is the [doctor](../doctor.md)'s**, which is
why nothing here grows past five tags; until that command exists, the count of what is not shown is the honest
substitute for it.

Which settles the bulk question that was open here, and revises the earlier reasoning: the objection was never to bulk
itself, it was to `build` doing it as a side effect. **`spec:make --tag=` and `--all` are legitimate**, because a human
typed them and creating files is that command's entire job — a loop over the singular invocation above, not a second
mechanism. Two guards keep the hundred-empty-classes scenario away: bulk is never the default, and it lists what it is
about to create and asks before doing it.

Adopting tag by tag is also the shape [Phase 3](../../project/roadmap.md) wants — a migration that proceeds route by
route rather than in one leap.

### Per-type flags belong here

The `make:model -mc` instinct is right; it just attaches to this command rather than to `build`. Once `spec:make` is the
thing that takes an operation's name, flags for what to create alongside it are natural and bounded — a test, a DTO
subclass, a policy — because they all concern the one operation you named.

**Open:** which types earn a flag. The list should be short, and each entry has to be something a developer genuinely
wants _per operation_ rather than something the build already produces for the whole contract.

## Watching: `spec:watch`

A build that is strict on purpose must not make API design tedious. Someone actively shaping a contract changes files
constantly, and asking them to type a fetch flag between every save is how a good rule earns a bad reputation.

**Decision: a separate command, `spec:watch`, owns the development loop.** Not a flag on `spec:build`.

The reason is the one that makes the whole scheme safe, and it is a genuinely better answer than a config key: **a
command is intent that cannot be forgotten.** A config option saying "auto-fetch is fine here" gets committed, travels
to another environment, and is still true at 3am in CI six months later — nobody re-decides it, because nothing asks. A
watch process is stated fresh every time and dies with the terminal. Staging, CI and production do not watch. They
build. There is no artifact of watch mode that can leak into them, because the intent was never written down anywhere.

That earns watch permissions build refuses:

- **Fetch new references automatically** as they appear in the spec.
- **Refresh every reference on a cadence**, for someone iterating against a contract that is moving under them — on file
  change, or on an interval. It stays a _rebuild_ trigger: since
  [the runtime never sees the spec](./index.md#the-runtime-never-sees-the-spec), there is no request path left that
  could fetch anything, in watch or anywhere else. What watch changes is how often the build runs and whether it may
  reach the network while doing so, never what happens during a request.
- **Rebuild on change**, which is the point of the mode.

Two rules keep it honest:

- **Watch must never produce output `build` would not.** It is `build` plus triggers plus network permission — not a
  second generator. The moment watched output differs from built output, "works on my machine" is back and the package's
  core promise goes with it.
- **The mode has to be visible while it is on.** A long-running process quietly fetching remote documents into your
  working tree should say so, continuously and unmistakably. Silence here would be the same mistake this subject rejects
  everywhere else.

### Borrowing from bundlers, and where to stop

The `dev` versus `build` split is exactly the shape module bundlers converged on, and the ergonomics are worth taking.
**The divergence is not.**

Bundlers accept that development and production output differ, and "works in dev, breaks in prod" is the famous,
recurring price. A package whose entire purpose is that code and contract cannot disagree cannot pay that price. So the
split is in the **process**, never in the **product**: watch adds triggers, fetching and diagnostics; it does not add,
remove or reshape a single generated line.

The one bundler nicety that does translate is [the source map](./generated-file-anatomy.md#the-source-map), and it turns
out to be worth having in every mode rather than only in development — which is why it has its own section rather than
living here.

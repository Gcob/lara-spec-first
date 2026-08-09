---
title: Code Generation
audience: Users, contributors and agents
covers: >
    The build command and what it produces, the boundary between build time and
    run time, where generated code lives, the rule that generated code is never
    edited by hand, why scaffolding a class you will own is a separate command,
    and how a contract change surfaces as a static analysis error rather than a
    runtime surprise.
read_before: >
    Writing anything that emits PHP from a specification, or changing what the
    build command does.
tags: [ code-generation, openapi, scope, decisions, laravel ]
---

# Code Generation

Spec-First only pays off if the contract reaches the code. This document owns how it gets there: **one
build command turns the specification into PHP, and the result is safe to regenerate at any time.**

> **Nothing described here is implemented yet.** Phase 1 of the [Roadmap](./ROADMAP.md). This states
> intent and reasoning; items marked `Open` are undecided.

What the build reads, and what it refuses to read, is a different subject and lives in
[`OPENAPI-SUPPORT.md`](./OPENAPI-SUPPORT.md).

## The invariant: a build never destroys human work

Every rule below exists to serve one property. **You can run the build at any moment, on any machine,
as many times as you like, and nothing a developer wrote is lost.** A code generator you are afraid to
re-run is a code generator that stops being run, and a Spec-First package whose generator stops being
run has quietly become Code-First again.

The invariant is structural, not a matter of care. It holds because generated files and
human-authored files are **disjoint sets** — different files, in different places. The build owns its
files completely and never opens the others.

**There is no exception clause, deliberately.** An earlier draft let the build create a starter class
when one was missing, which sounded harmless and was not: "the build never writes a file it does not
own, except when it does" is a rule that erodes, and every later feature would have argued for its own
carve-out. Creating a class a human will own is
[a different command's job](#scaffolding-is-specmake-not-a-build-step).

## The runtime never sees the spec

**Decision: the service provider does not know a specification exists.** Only the build-time commands
read one. At boot, the package loads generated PHP and nothing else — no YAML, no parser, no
resolution, no `$ref`.

Explicit over dynamic, everywhere. The alternative — a provider that parses the contract on every boot
— was never really compatible with the rest of this document, and saying so plainly is cheaper than
discovering it halfway through the implementation.

What follows from it:

* **The parser is a build-time dependency in practice.** `cebe\openapi\` classes must never be
  reachable from the routing or request path. This is not a convention to remember, it is an assertion
  to write: the existing architecture test in `tests/Unit/` is exactly the place to forbid the runtime
  namespace from referencing the parser at all.
* **Boot cost is loading PHP**, which is what `route:cache` and the opcode cache already optimise. No
  work to memoise, no cache of our own to invent.
* **The boundary is the production request path, not the process.** Serving a real application's
  traffic never involves a specification. Other contexts plausibly do, and pretending otherwise now
  would only mean rewriting this section later: contract testing has to compare a live response
  against the contract, and a [mock server](./ROADMAP.md) is a spec-driven server by definition. Those
  are separate execution contexts with their own rules. **Deferred deliberately** — the contexts get
  enumerated when the first one is built, not guessed at now. Until then, the architecture test
  forbids the parser to the *routing and request* namespaces specifically, not to the package at
  large.
* **It creates one new failure mode, and it must be named:** edit the spec, forget to build, and the
  application serves the previous contract without a word — because nothing at runtime knows a spec
  exists to compare against. **Detecting that drift is the doctor's job**, which makes it a required CI
  check rather than a convenience. A package this strict about contracts cannot ship the one silent
  way to be out of date.

## Three kinds of file, and only two are the build's

| Kind                | Lifecycle                                                                                            | Who owns it                                                                                                   |
|---------------------|------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------|
| **Generated**       | Rewritten from scratch on every build.                                                               | The package. Never edit — your edit is gone on the next run, by design.                                       |
| **Vendored inputs** | Never fetched unless asked; [frozen by default](#remote-references-during-a-build-frozen-by-default). | Upstream. See [remote references](./OPENAPI-SUPPORT.md#a-remote-reference-is-a-dependency-not-a-cache-entry). |
| **Your classes**    | Created once by [`spec:make`](#scaffolding-is-specmake-not-a-build-step), on request. The build never touches them. | You, entirely, from the moment the file exists.                                                              |

The generated kind should be unmistakable at a glance and at grep-time: its own directory, its own
namespace, and a header on every file saying it is generated and will be overwritten. A developer
should never have to wonder which side of the line a file is on — and neither should an AI agent
working in the repository, which is a first-class consideration for this package.

## The split is what makes a contract change loud

The mechanism is ordinary PHP, and that is the point: **generated abstract classes and interfaces,
extended by human-written concrete classes.**

Say an operation gains a required parameter. The build rewrites the generated abstract, whose method
signature changes. Every concrete subclass a developer wrote now fails to satisfy its parent, and
PHP — plus PHPStan at [level 8](./STACK.md) — says so immediately, by name, before anything runs.

That is the whole payoff of Spec-First expressed in one behaviour: **a change to the contract becomes
a compile-time error in the code that implements it, not a 500 in production.** It is also why the
generated side must be free to change shape without asking permission. It can only be free if nobody
has hand-edits in it to protect.

## The build command: `spec:build`

One command, run after any change to the specification, producing every derived output: the
[contract artifact](./OPENAPI-SUPPORT.md#the-contract-artifact), the routes, the abstract controllers,
the response DTOs and the validation. Everything it writes, it owns.

Its properties:

* **Idempotent.** Running it twice in a row changes nothing the second time. If a build produces a
  diff on an unchanged spec, that is a defect.
* **Ordered, and it stops.** Check the vendored references are present, parse, normalise into the
  prospective artifact, **compare it against the committed one**, then generate. A spec that fails
  [the doctor's](./OPENAPI-SUPPORT.md#where-the-diagnostics-go-the-doctor) hard checks does not reach
  the generator — half-generated output from a broken contract is worse than no output. The comparison
  sits before generation for the same reason: nothing is written until it is known to be allowed.
* **It never writes outside its own directories.** No exceptions, no conditions. This is the
  [invariant](#the-invariant-a-build-never-destroys-human-work) in one sentence, and it is testable —
  which is the point of stating it without a clause.

### Remote references during a build: frozen by default

**Decision: the build never reaches the network unless asked.** A missing vendored document is an
error that names the flag to run, not an excuse to open a socket.

This is a deliberate reversal of the earlier "fetch whatever is missing" default. That default was
convenient, and convenience is the wrong tiebreaker for the one operation that can change an API
contract without anyone deciding to. Under a frozen default:

* A fresh clone builds **offline**, because every vendored copy is committed.
* Adding a new `$ref` to the spec fails the build once, with a message saying exactly what to run.
  One deliberate command, and the new document lands in the next commit as a reviewable diff.
* A missing vendored copy in CI or production means somebody forgot to commit it — the pipeline says
  so instead of papering over it with a fetch.
* There is no environment-dependent behaviour to reason about. The build does the same thing on a
  laptop and in CI, which is the property that makes a build trustworthy.

Fetching therefore has one entry point in `build`: an explicit flag, whether the document is missing or
already vendored. Working name `--update-refs`, matching the install/update vocabulary the
[dependency framing](./OPENAPI-SUPPORT.md#borrowing-the-dependency-manager-shape) already borrows.
Whether missing and stale documents need *separate* flags is open — one flag is simpler, two let you
add a reference without silently refreshing the others.

None of which should make designing an API tedious. That is what [watch mode](#watching-specwatch)
is for, and it is a different command precisely so that `build` can stay this strict.

### Which generated code is committed

**Decision: the package does not decide. `.gitignore` does.**

The build writes files; git decides which are tracked. That is already every consumer's mechanism for
"I do not want this in my repository", it needs no config key, no documentation of its own, and no
opinion from us. Adding a config option here would be inventing a second, worse `.gitignore`.

**One exception, and it is not optional: the vendored references must be committed.** There is
[no lock file](./OPENAPI-SUPPORT.md#no-lock-file-git-is-the-lock) — the committed copies *are* the
lock. Ignoring that directory does not save you noise, it removes the only mechanism that makes a
build reproducible and an old release deployable. The doctor should detect it and report it as a
finding rather than let it be discovered during an incident.

#### Two layers

**Decision: the build emits an interface plus an abstract class**, splitting the output along the line
that matters:

| Layer | Carries | Naturally |
|---|---|---|
| **Interface** | The contract surface: method signatures, DTO shapes, the things a change to the spec actually changes. | Committed — this is the diff a reviewer wants, and it is small. |
| **Abstract class** | The mechanics that implement the interface. Predictable, derivable, and noisy in a diff. | A candidate for ignoring, since an idempotent build reproduces it exactly. |

It fits the [split](#the-split-is-what-makes-a-contract-change-loud) rather than complicating it: the
interface is what a human subclass is checked against, so the compile-time error survives even if the
abstract layer never enters version control.

The cost of ignoring the second layer is that a fresh clone does not analyse, autocomplete or run
until the build has been run once — the `composer install` bargain, which this ecosystem already
accepts. Which layer a given project chooses to ignore stays that project's call: this is still
[`.gitignore`'s decision](#which-generated-code-is-committed), not a config key.

**Open:** whether the second layer is an abstract class or a trait. An abstract class gives one
inheritance slot to the developer and takes it; a trait leaves it free and composes, at the cost of
not being able to declare abstract members quite as directly. It is a question best settled against
real generated output.

## Where generated code lives

**Decision: a config key, defaulting to `app/Http/Generated` and the namespace `App\Http\Generated`.**

Under `app/` because it is application code the developer will read, extend and debug, not a build
artefact hidden in `bootstrap/`. Under `app/Http/` because that is where Laravel already puts
controllers, form requests and middleware — everything generated here is HTTP-layer machinery, and it
belongs beside the concrete controllers that extend it rather than in a directory of its own
invention. Configurable because no default survives contact with every project.

**The name has a job.** It appears in every `use` statement, every stack trace and every IDE
autocomplete for the lifetime of the project, so it should say *do not edit this* without anyone
having to look it up. `Generated` does that in one word; a name like `Integration` says nothing about
ownership, which is the only thing a reader needs from it at a glance.

**One configurable root, with fixed sub-namespaces beneath it** — `Controllers`, `Data`, and whatever
follows — rather than a separate config key per kind of output. A team that keeps its DTOs in
`App\Data` will notice the difference, and it is a small one: these are files nobody may edit, so
where they sit matters far less than for hand-written code. What one root buys is worth more:

* **`.gitignore` is one line.** [The mechanism we chose](#which-generated-code-is-committed) works by
  directory, so a split tree means several entries, and a consumer who forgets one ends up with half a
  generated tree committed and half not.
* **"Everything under here is generated" is only a rule while there is one *here*.**
* **Widening later is a minor release, narrowing is a major one** — the same reasoning
  [`STACK.md`](./STACK.md) applies to version support. If per-kind overrides turn out to be wanted,
  they can be added without breaking anyone; starting with them and removing them cannot.

Two details that will otherwise be discovered the hard way:

* **PSR-4 requires the directory segment and the namespace segment to match, including case.** A
  standard Laravel application maps `App\` to `app/`, so `app/http/generated` autoloads as
  `App\http\generated` — legal PHP, and an immediate source of confusion. Every segment is capitalised
  in the default for that reason.
* **Path and namespace are two settings, not one.** Deriving one from the other means guessing at the
  consumer's autoload map. Both are configured, and the doctor checks they agree with what `composer`
  actually autoloads — a mismatch there produces class-not-found errors far from their cause.

The config key names and the default are public API surface under
[rule 4](./OPENAPI-SUPPORT.md#the-four-rules-that-govern-this-document).

## Scaffolding is `spec:make`, not a build step

**Decision: the build never creates a class you will own. `spec:make` does, on request.**

Laravel already has this shape and every Laravel developer already has the reflex: a `make` creates one
file, when you ask, once. Reusing the word costs no new concept — and it removes the only exception the
[invariant](#the-invariant-a-build-never-destroys-human-work) ever had.

Nothing forces the build to do it instead, because an operation with no implementation is not a broken
application — provided the package says what happens to it.

### An unimplemented operation answers 501

**Decision: the build registers the route and points it at a package-provided handler that returns
`501 Not Implemented`**, with a body naming the operation and the `spec:make` command that implements
it.

The two alternatives are worse, and for reasons this document has already committed to:

* **Not registering the route** would mean the contract describes an endpoint that does not exist, and
  a client would get a `404` indistinguishable from a typo. That is
  [rule 2](./OPENAPI-SUPPORT.md#the-four-rules-that-govern-this-document) violated at the level of the
  wire: the spec says the endpoint is there, and nothing anywhere says otherwise.
* **Pointing at a class that does not exist** produces a class-not-found fatal at request time — an
  internal error blaming the consumer's application for a state the package created on purpose.

`501` is the status code HTTP already has for exactly this: the server recognises the request and has
not implemented it. It is honest to the client, it is greppable in logs, and it is the seam the
[Faker mock](./ROADMAP.md) plugs into in Phase 2 — same route, same handler position, a better answer
in the body. Nothing about the Phase 1 shape has to change for the mock to arrive.

### Where your classes go

**In the application's own controller location, not in the generated directory.** Two reasons, and the
first is not a matter of taste:

* **`.gitignore` works by directory, and we made `.gitignore`
  [the mechanism](#which-generated-code-is-committed).** Put your classes inside the generated tree and
  a consumer who ignores that tree loses their own work. That single fact rules the option out.
* **It is an ordinary Laravel controller.** Once the file exists it has nothing to do with this package
  except that it extends a generated class. Your conventions, your IDE, your tests and your `make:`
  habits all already point at that directory. The generated abstract is the unusual object here; the
  concrete class is not.

**The consequence to state plainly:** the generated route refers to your class by its fully-qualified
name, so the name and namespace are load-bearing. Moving the file is fine; moving it somewhere it no
longer autoloads under the expected name breaks the route. The
[doctor](./OPENAPI-SUPPORT.md#where-the-diagnostics-go-the-doctor) reports that as a missing
implementation rather than letting it surface as a class-not-found at runtime.

### Not a flag on `spec:build`

`spec:build --make` is the tempting shortcut, and the analogy that suggests it does not survive contact.

`make:model --controller --migration` creates several files **for one thing you just named**: one
subject, one invocation, a human present. `build` does not operate on an operation you named — it
operates on the whole specification. So `spec:build --make` means *scaffold every missing implementation*,
which is how a hundred empty classes get committed by accident.

The deeper cost is that it makes the invariant conditional again: *the build never writes a file it
does not own, unless you pass `--make`*. The architecture test stops being absolute, and the next
feature has a precedent to point at.

The counter-argument is real and worth recording, because it comes from this document's own logic: a
flag typed by a human **is** explicit intent, exactly as [watch](#watching-specwatch) is. But
that is precisely why watch is a separate command rather than a flag — a flag ends up in a Procfile or
a deploy script, and then it is creating files unattended. Same risk, same answer. It is also why
watch cannot scaffold either: watch must never produce output `build` would not.

### The build names the command instead of running it

What the shortcut was really asking for is ergonomics, and those can be had without touching the
invariant. **When the build finds operations with no implementation, it names the command rather than
running it** — the same pattern as the
[rename report naming the files to fix](#how-it-says-it).

The trap is printing one line per operation. A specification with two hundred operations, on the day
somebody adopts this package, would answer with two hundred commands — which is not a list, it is a
wall, arriving at the worst possible moment. So the build **summarises, and the
[doctor](./OPENAPI-SUPPORT.md#where-the-diagnostics-go-the-doctor) holds the full list**, which is the
division of labour those two commands already have.

It summarises **by `tags`**, because the specification already carries the author's own grouping and
inventing a second one would be worse than using theirs:

```
47 operations have no implementation:
  Users (12)   php artisan spec:make --tag=Users
  Orders (8)   php artisan spec:make --tag=Orders
  … 5 more tags. Full list: php artisan spec:doctor
```

Which settles the bulk question that was open here, and revises the earlier reasoning: the objection
was never to bulk itself, it was to `build` doing it as a side effect. **`spec:make --tag=` and
`--all` are legitimate**, because a human typed them and creating files is that command's entire job.
Two guards keep the hundred-empty-classes scenario away: bulk is never the default, and it lists what
it is about to create and asks before doing it.

Adopting tag by tag is also the shape [Phase 3](./ROADMAP.md) wants — a migration that proceeds route
by route rather than in one leap.

### Per-type flags belong here

The `make:model -mc` instinct is right; it just attaches to this command rather than to `build`. Once
`spec:make` is the thing that takes an operation's name, flags for what to create alongside it are natural
and bounded — a test, a DTO subclass, a policy — because they all concern the one operation you named.

**Open:** which types earn a flag. The list should be short, and each entry has to be something a
developer genuinely wants *per operation* rather than something the build already produces for the
whole contract.

## Naming, and the rename problem

The generated class and method names come from `operationId`. That makes an `operationId` far more
than a label: **it is the name of the class a developer extends**, so renaming one in the spec renames
a class in their application.

The position on this is the project's position on API design generally: **designing an API is a skill,
and changing an identifier is a versioning decision.** The package is not going to hide that, and
versioning is the right answer. But there is a difference between refusing to hide a consequence and
leaving a beginner to discover it from a fatal error, and the difference costs us very little.

### Identity is the path and the method, not the name

The distinction that makes help possible: an operation's **identity** is its path plus its HTTP
method, which is what actually addresses it. Its **name** is `operationId`, which is what we generate
from. Renaming an operation therefore changes the name while the identity holds still — and a build
that knows both can tell the difference between a rename and a deletion.

Identity has to be normalised to be useful: **the names of path parameters are not part of it.**
Renaming `/users/{id}` to `/users/{userId}` changes nothing a client can observe — the URL on the wire
is identical, and the template variable is documentation. Identity is therefore the method plus the
path with its parameters reduced to positions, so that rename produces no diff at all. It also means
`/users/{id}` and `/users/{slug}` share an identity and collide — which is correct, because those two
routes already collide in the router, and surfacing it is a service rather than a limitation.

That requires no new state file. The build reads the generated tree before overwriting it, and every
generated file already carries [the pointer it came from](#the-source-map). Comparing the two gives:

* **Renames, reported as renames.** *This operation was `listUsers`, it is now `indexUsers`; the class
  you extended has been replaced.* Naming the old and the new turns a fatal error into an instruction.
* **Orphans, reported by name.** A human class extending a generated abstract that no longer exists is
  detectable, and is exactly what a rename leaves behind. The
  [invariant](#the-invariant-a-build-never-destroys-human-work) means their work is still there — it is
  just no longer connected to anything, and nobody should have to find that out at runtime.

The honest limit: when the path itself moves, identity and name change together and a rename becomes
indistinguishable from a delete plus an add. The build should say that it cannot tell, rather than
guess.

### How it says it

A rename is only useful as a message if it names the code that has to change. The build knows the old
fully-qualified class name it is about to replace, so it can find the references itself: scan the
application for that symbol and **report the files that mention it, with line numbers**, alongside the
old and new names.

That turns the output from *something was renamed* into *these four files reference a class that no
longer exists*, which is the difference between a notice and a fix. It is a token scan over PHP the
consumer already has — no AST work, no runtime reflection, nothing to keep in sync.

In [watch](#watching-specwatch) the same report arrives while the developer is still holding the
context in their head, which is when a rename costs almost nothing to absorb. That is the strongest
argument for watch mode existing at all.

**Open:** how prominent this is — a heading in the build output, a doctor finding, or a non-zero exit
until the references are updated. Failing the build is defensible under
[rule 2](./OPENAPI-SUPPORT.md#the-four-rules-that-govern-this-document) and might be intolerable in
watch. Probably different answers for the two commands.

### When `operationId` is absent, derive from method and path

**Decision: the fallback is the operation's [identity](#identity-is-the-path-and-the-method-not-the-name)
— its HTTP method and its normalised path.** There is nothing else that both exists on every operation
and means something to a reader.

The objection to raise and dismiss: deriving from the path means that reorganising URLs renames
classes. True — and **proportionate**, because changing a path *is* a change to the contract. Consumers
have to update their calls; you having to update a class name is the same event, visible in your own
code. For a `stable` operation the build already refuses the change until
[`info.version`](./OPENAPI-SUPPORT.md#unstable-by-default-and-what-stable-costs-us) says so, and for a
`beta` one churn is what `beta` means. The case that would have been unfair — renaming a path
*parameter*, which changes nothing on the wire — is already excluded by normalising identity.

What the fallback genuinely costs is readability: a derived name will never read as well as
`listActiveSubscriptions`. That is an argument for writing `operationId`, not against having a
fallback, and it is the kind of nudge the doctor should make rather than the build enforce.

**Decision: `operationId` is required on `public` + `stable` operations, and optional everywhere else.**
A stable operation's generated class name is a promise made to your own codebase, so it deserves to be
chosen rather than computed — while a `beta` or `internal` operation can be sketched without ceremony.
The rule reuses the [lifecycle](./OPENAPI-SUPPORT.md#unstable-by-default-and-what-stable-costs-us)
vocabulary instead of inventing one of its own, and it lands where it costs least: nobody meets it
while exploring, and everybody meets it at the moment they promise an endpoint to someone.

It also means promoting an operation to `stable` is the moment its name gets chosen deliberately —
which is exactly when a derived name would otherwise harden into something nobody picked and nobody
can now change without a major version.

**Open:** collisions between two `operationId` values that differ only in characters PHP cannot use in
an identifier, and whether the build refuses them outright.

## Watching: `spec:watch`

A build that is strict on purpose must not make API design tedious. Someone actively shaping a
contract changes files constantly, and asking them to type a fetch flag between every save is how a
good rule earns a bad reputation.

**Decision: a separate command, `spec:watch`, owns the development loop.** Not a flag on `spec:build`.

The reason is the one that makes the whole scheme safe, and it is a genuinely better answer than a
config key: **a command is intent that cannot be forgotten.** A config option saying "auto-fetch is
fine here" gets committed, travels to another environment, and is still true at 3am in CI six months
later — nobody re-decides it, because nothing asks. A watch process is stated fresh every time and
dies with the terminal. Staging, CI and production do not watch. They build. There is no artifact of
watch mode that can leak into them, because the intent was never written down anywhere.

That earns watch permissions build refuses:

* **Fetch new references automatically** as they appear in the spec.
* **Refresh every reference on a cadence**, for someone iterating against a contract that is moving
  under them — on file change, or on an interval. It stays a *rebuild* trigger: since
  [the runtime never sees the spec](#the-runtime-never-sees-the-spec), there is no request path left
  that could fetch anything, in watch or anywhere else. What watch changes is how often the build
  runs and whether it may reach the network while doing so, never what happens during a request.
* **Rebuild on change**, which is the point of the mode.

Two rules keep it honest:

* **Watch must never produce output `build` would not.** It is `build` plus triggers plus network
  permission — not a second generator. The moment watched output differs from built output, "works on
  my machine" is back and the package's core promise goes with it.
* **The mode has to be visible while it is on.** A long-running process quietly fetching remote
  documents into your working tree should say so, continuously and unmistakably. Silence here would be
  the same mistake this document rejects everywhere else.

### Borrowing from bundlers, and where to stop

The `dev` versus `build` split is exactly the shape module bundlers converged on, and the ergonomics
are worth taking. **The divergence is not.**

Bundlers accept that development and production output differ, and "works in dev, breaks in prod" is
the famous, recurring price. A package whose entire purpose is that code and contract cannot disagree
cannot pay that price. So the split is in the **process**, never in the **product**: watch adds
triggers, fetching and diagnostics; it does not add, remove or reshape a single generated line.

The one bundler nicety that does translate is [the source map](#the-source-map), and it turns out to
be worth having in every mode rather than only in development — which is why it has its own section
rather than living here.

## The source map

**Decision: every generated file carries the JSON pointer it came from** — the operation, the schema,
the exact position in the specification.

It is the same idea a bundler's source map serves, and the same need: generated code is read by people
who did not write it, and the first question any of them has is *where did this come from?* A
developer debugging, a reviewer judging a diff, an AI agent working in the repository — all three are
one annotation away from the contract instead of grepping for it.

It costs nothing at runtime in PHP, so it is emitted **unconditionally**, in every mode. That is what
keeps [watch and build output identical](#borrowing-from-bundlers-and-where-to-stop), and it is why
this is not a development-only nicety.

Two other decisions depend on it, which is the real reason it stands alone:

* [Rename detection](#identity-is-the-path-and-the-method-not-the-name) compares the pointers in the
  existing generated tree against the ones the new build would emit. Without the annotation there is
  no comparison to make and no rename to report.
* The [contract artifact](./OPENAPI-SUPPORT.md#the-contract-artifact) is keyed by the same identity,
  so a finding in the artifact diff and a header in a generated file name the same thing.

## Response DTOs

The DTOs are how a response schema becomes a PHP type. Two properties, and the tension between them is
the design:

* **The shape is generated, and not yours.** Properties, types and nullability come from the response
  schema. A hand-edited shape is drift from the contract by definition, and it is exactly what
  Spec-First exists to prevent.
* **The behaviour is yours.** Hydration is where real applications differ, and a generated DTO you
  cannot teach to build itself from your model is a generated DTO people will wrap or abandon.

The [two-layer split](#two-layers) resolves this cleanly: the generated
layer declares the shape and a default `from()`; your class overrides `from()` and adds whatever else
it needs. Hackable where it should be, fixed where the contract speaks.

`spatie/laravel-data` is the reference for what good feels like here, and its `from($model)` ergonomics
are the target. **Whether we depend on it or only take the shape is undecided** and belongs in
[`STACK.md`](./STACK.md) once settled — a dependency buys casting, validation and serialisation for
free, at the cost of binding generated code to another package's API and release cycle.

## Appending into human-owned files

**Status: undecided, and deliberately not planned for the first release.**

The idea is PhpStorm's getter/setter generator: inject valid code at the end of an existing class
without disturbing what is there. It is attractive, and it is the one feature on this page that would
break the [invariant](#the-invariant-a-build-never-destroys-human-work) structurally, so it deserves a
straight answer rather than a maybe.

Why it is harder here than in an IDE: PhpStorm runs one action, on one file, with a human watching and
undo one keystroke away. A build runs unattended, in CI, across every file at once. The failure modes
that follow are not hypothetical — re-running duplicates injected code unless the tool can recognise
its own previous output, which means markers inside human files; a contract change requires *removing*
previously injected code, which is materially harder than adding it; and formatting will fight Pint
until somebody loses.

**The alternative that costs nothing:** generate a trait and have the developer `use` it. PHP already
has a language feature whose entire purpose is injecting members into a class, it composes with the
abstract-class approach, and it keeps generated and human files disjoint. Most of what the append idea
promises is available this way, today, with no rewriting of anyone's code.

If it is ever built anyway, three non-negotiables: delimited regions the build owns entirely, nothing
outside those regions ever read or written, and a hard failure rather than a guess when the region is
missing or malformed.

## Open questions

* The config key names for the [generated location](#where-generated-code-lives) — the location's
  *default* is decided, what the keys are called is not. Public API surface under
  [rule 4](./OPENAPI-SUPPORT.md#the-four-rules-that-govern-this-document).
* Which [per-type flags](#per-type-flags-belong-here) `spec:make` accepts.
* Whether the second of the [two layers](#two-layers) is an abstract class or a trait.
* Whether fetching a *missing* reference and refreshing a *stale* one share one flag or take two.
* What [watch](#watching-specwatch) takes as parameters — in particular how "refresh references
  on every request" is asked for, and how the mode announces itself.
* Whether `spatie/laravel-data` becomes a dependency or only an influence.
* **Sequencing:** routes and abstract controllers are the Phase 1 target. Response DTOs and generated
  validation are Phase 2 — the same build command doing more, not a new one. See the
  [Roadmap](./ROADMAP.md).

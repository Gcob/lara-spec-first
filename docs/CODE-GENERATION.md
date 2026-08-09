---
title: Code Generation
audience: Users, contributors and agents
covers: >
    The build command and what it produces, the boundary between build time and
    run time, where generated code lives, the rule that generated code is never
    edited by hand, the difference between regenerated output and one-time stubs,
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

## Three kinds of output

| Kind                | Lifecycle                                             | Who owns it                                                                                                   |
|---------------------|-------------------------------------------------------|---------------------------------------------------------------------------------------------------------------|
| **Generated**       | Rewritten from scratch on every build.                | The package. Never edit — your edit is gone on the next run, by design.                                       |
| **Stubs**           | Written once, when absent. Never touched again, ever. | You, from the moment it exists. The build checks existence and skips.                                         |
| **Vendored inputs** | Fetched when missing, refreshed only on request.      | Upstream. See [remote references](./OPENAPI-SUPPORT.md#a-remote-reference-is-a-dependency-not-a-cache-entry). |

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

## The build command

One command, run after any change to the specification, producing every derived artifact: the
resolved spec, the routes, the abstract controllers, the response DTOs, the validation, and any stub
that does not exist yet.

Its properties:

* **Idempotent.** Running it twice in a row changes nothing the second time. If a build produces a
  diff on an unchanged spec, that is a defect.
* **Ordered, and it stops.** Vendoring, then parsing, then generation. A spec that fails
  [the doctor's](./OPENAPI-SUPPORT.md#where-the-diagnostics-go-the-doctor) hard checks does not reach
  the generator — half-generated output from a broken contract is worse than no output.
* **It never writes outside its own directories**, with the sole exception of creating a stub that
  does not exist. This is the invariant, in one sentence, and it is testable.

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

None of which should make designing an API tedious. That is what [watch mode](#watching-the-design-loop)
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

#### The idea worth designing for: two layers

Generating **an interface plus an abstract class or trait** splits the output along the line that
matters for this question:

| Layer | Carries | Naturally |
|---|---|---|
| **Interface** | The contract surface: method signatures, DTO shapes, the things a change to the spec actually changes. | Committed — this is the diff a reviewer wants, and it is small. |
| **Abstract class or trait** | The mechanics that implement the interface. Predictable, derivable, and noisy in a diff. | A candidate for ignoring, since an idempotent build reproduces it exactly. |

It fits the [split](#the-split-is-what-makes-a-contract-change-loud) rather than complicating it: the
interface is what a human subclass is checked against, so the compile-time error survives even if the
abstract layer never enters version control.

The cost of ignoring the second layer is that a fresh clone does not analyse, autocomplete or run
until the build has been run once — the `composer install` bargain, which this ecosystem already
accepts. **Decide it when there is generated output to look at**, not now.

## Where generated code lives

**Decision: a config key, defaulting to `app/Integration` and the namespace `App\Integration`.**

Under `app/` because it is application code the developer will read, extend and debug, not a build
artefact hidden in `bootstrap/`. Configurable because no default survives contact with every project.

Two details that will otherwise be discovered the hard way:

* **PSR-4 requires the directory segment and the namespace segment to match, including case.** A
  standard Laravel application maps `App\` to `app/`, so `app/integration` autoloads as
  `App\integration` — legal PHP, and an immediate source of confusion. The default is `app/Integration`.
* **Path and namespace are two settings, not one.** Deriving one from the other means guessing at the
  consumer's autoload map. Both are configured, and the doctor checks they agree with what `composer`
  actually autoloads — a mismatch there produces class-not-found errors far from their cause.

The config key names and the default are public API surface under
[rule 4](./OPENAPI-SUPPORT.md#the-four-rules-that-govern-this-document).

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

That requires no new state file. The build reads the generated tree before overwriting it, and every
generated file already carries
[the pointer it came from](#borrowing-from-bundlers-and-where-to-stop). Comparing the two gives:

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

In [watch](#watching-the-design-loop) the same report arrives while the developer is still holding the
context in their head, which is when a rename costs almost nothing to absorb. That is the strongest
argument for watch mode existing at all.

**Open:** how prominent this is — a heading in the build output, a doctor finding, or a non-zero exit
until the references are updated. Failing the build is defensible under
[rule 2](./OPENAPI-SUPPORT.md#the-four-rules-that-govern-this-document) and might be intolerable in
watch. Probably different answers for the two commands.

**Open:** the fallback when `operationId` is absent — deriving from method and path makes any URL
reorganisation a mass rename — plus collisions between two operations whose ids differ only in
characters PHP cannot use, and whether the build refuses those outright.

## Watching: the design loop

A build that is strict on purpose must not make API design tedious. Someone actively shaping a
contract changes files constantly, and asking them to type a fetch flag between every save is how a
good rule earns a bad reputation.

**Decision: a separate `watch` command owns the development loop.** Not a flag on `build`.

The reason is the one that makes the whole scheme safe, and it is a genuinely better answer than a
config key: **a command is intent that cannot be forgotten.** A config option saying "auto-fetch is
fine here" gets committed, travels to another environment, and is still true at 3am in CI six months
later — nobody re-decides it, because nothing asks. A watch process is stated fresh every time and
dies with the terminal. Staging, CI and production do not watch. They build. There is no artifact of
watch mode that can leak into them, because the intent was never written down anywhere.

That earns watch permissions build refuses:

* **Fetch new references automatically** as they appear in the spec.
* **Refresh every reference on a cadence, up to every request**, for someone iterating against a
  contract that is moving under them. Network I/O in the request path is banned everywhere else in
  this package; here it is legitimate, and only because a human explicitly started the process that
  does it.
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

Which leaves the one bundler nicety that does translate, and it turns out to be worth having in every
mode rather than just development: **a source map for API design.** Every generated file can carry the
JSON pointer it came from — the operation, the schema, the exact position in the spec — so that a
developer, a reviewer or an AI agent reading generated PHP can jump straight to the contract that
produced it. It costs nothing at runtime in PHP, so there is no reason to strip it for production, and
emitting it unconditionally keeps the output identical between modes.

## Response DTOs

The DTOs are how a response schema becomes a PHP type. Two properties, and the tension between them is
the design:

* **The shape is generated, and not yours.** Properties, types and nullability come from the response
  schema. A hand-edited shape is drift from the contract by definition, and it is exactly what
  Spec-First exists to prevent.
* **The behaviour is yours.** Hydration is where real applications differ, and a generated DTO you
  cannot teach to build itself from your model is a generated DTO people will wrap or abandon.

The [two-layer split](#the-idea-worth-designing-for-two-layers) resolves this cleanly: the generated
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

* The command name, the generated namespace and directory, and the stub location. All public API
  surface under [rule 4](./OPENAPI-SUPPORT.md#the-four-rules-that-govern-this-document).
* Whether the build emits [two layers](#the-idea-worth-designing-for-two-layers), and if so which one
  a consumer is expected to ignore.
* Whether fetching a *missing* reference and refreshing a *stale* one share one flag or take two.
* What [watch](#watching-the-design-loop) takes as parameters — in particular how "refresh references
  on every request" is asked for, and how the mode announces itself.
* How controllers and methods are named — this depends on `operationId`, which is still
  [open](./OPENAPI-SUPPORT.md#still-to-discuss).
* Whether `spatie/laravel-data` becomes a dependency or only an influence.
* **Sequencing:** routes and abstract controllers are the Phase 1 target. Response DTOs and generated
  validation are Phase 2 — the same build command doing more, not a new one. See the
  [Roadmap](./ROADMAP.md).

---
title: Code Generation
audience: Users
covers: >
    The build command and what it produces, the boundary between build time and run time, where generated code lives,
    the rule that generated code is never edited by hand, why scaffolding a class you will own is a separate command,
    the docblock every generated file carries so that a human or an AI agent can navigate it without guessing, the
    comment that sits above a reference to generated code and what to do when that class goes missing, why the
    specification the build reads is private and how a sanitized copy is produced for publication, why a formatter has
    to be told to leave the generated tree alone and why the build emits the canonical form anyway, why the build
    touches the filesystem directly rather than through a Storage disk and what that means for permissions, why
    generated output records no time and where the repository already answers that, and how a contract change surfaces
    as a static analysis error rather than a runtime surprise.
read_before: >
    Writing anything that emits PHP from a specification, or changing what the build command does.
tags: [code-generation, openapi, scope, decisions, laravel]
---

# Code Generation

Spec-First only pays off if the contract reaches the code. This document owns how it gets there: **one build command
turns the specification into PHP, and the result is safe to regenerate at any time.**

> **Almost all of this is intent rather than behaviour**, and like [`openapi-support.md`](./openapi-support.md) this
> file marks the difference per section rather than per file, so the banner does not become a little more wrong with
> every release. Items marked `Open` are undecided.
>
> **Shipped:** `spec:build` in its Phase 1 form, which resolves the specification and emits the routes and one
> controller per operation, each carrying [its own docblock](#every-generated-file-explains-itself) and answering
> [501](#an-unimplemented-operation-answers-501). It is idempotent, it plans before it writes, and it
> [never writes outside its own tree](#the-invariant-a-build-never-destroys-human-work). The provider
> [loads what it emitted](#the-routes-are-one-file-and-the-only-one-the-runtime-opens) and reads no specification to do
> it. Not built yet: the `x-controller` seam, so every generated controller is `final` today; response DTOs and request
> validation; rename detection; and `spec:make`.

What the build reads, and what it refuses to read, is a different subject and lives in
[`openapi-support.md`](./openapi-support.md).

## The invariant: a build never destroys human work

Every rule below exists to serve one property. **You can run the build at any moment, on any machine, as many times as
you like, and nothing a developer wrote is lost.** A code generator you are afraid to re-run is a code generator that
stops being run, and a Spec-First package whose generator stops being run has quietly become Code-First again.

The invariant is structural, not a matter of care. It holds because generated files and human-authored files are
**disjoint sets** — different files, in different places. The build owns its files completely and never opens the
others.

**There is no exception clause, deliberately.** An earlier draft let the build create a starter class when one was
missing, which sounded harmless and was not: "the build never writes a file it does not own, except when it does" is a
rule that erodes, and every later feature would have argued for its own carve-out. Creating a class a human will own is
[a different command's job](#scaffolding-is-specmake-not-a-build-step).

## The runtime never sees the spec

**Decision: the service provider does not know a specification exists.** Only the build-time commands read one. At boot,
the package loads generated PHP and nothing else — no YAML, no parser, no resolution, no `$ref`.

Explicit over dynamic, everywhere. The alternative — a provider that parses the contract on every boot — was never
really compatible with the rest of this document, and saying so plainly is cheaper than discovering it halfway through
the implementation.

What follows from it:

- **The parser is a build-time dependency in practice.** `cebe\openapi\` classes must never be reachable from the
  routing or request path. This is not a convention to remember: the architecture test contains the parser to
  [one namespace](./openapi-support.md#where-the-parser-sits-decided), which forbids it to the request path and to
  everything else at once. The assertion was written before anything imported the parser and is binding now that
  `OperationExtractor` does.
- **Boot cost is loading PHP**, which is what `route:cache` and the opcode cache already optimize. No work to memoize,
  no cache of our own to invent.
- **The boundary is the production request path, not the process.** Serving a real application's traffic never involves
  a specification. Other contexts plausibly do, and pretending otherwise now would only mean rewriting this section
  later: contract testing has to compare a live response against the contract, and a
  [mock server](../project/roadmap.md) is a spec-driven server by definition. Those are separate execution contexts with
  their own rules. **Deferred deliberately** — the contexts get enumerated when the first one is built, not guessed at
  now. Nothing about containing the parser to `Parsing\` blocks them: a mock server reads a contract through the same
  door as everything else.
- **It creates one new failure mode, and it must be named:** edit the spec, forget to build, and the application serves
  the previous contract without a word — because nothing at runtime knows a spec exists to compare against. **Detecting
  that drift is the doctor's job**, which makes it a required CI check rather than a convenience. A package this strict
  about contracts cannot ship the one silent way to be out of date.

## Three kinds of file, and only two are the build's

| Kind                | Lifecycle                                                                                                           | Who owns it                                                                                                     |
| ------------------- | ------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| **Generated**       | Rewritten from scratch on every build.                                                                              | The package. Never edit — your edit is gone on the next run, by design.                                         |
| **Vendored inputs** | Never fetched unless asked; [frozen by default](#remote-references-during-a-build-frozen-by-default).               | Upstream. See [remote references](./remote-references.md#a-remote-reference-is-a-dependency-not-a-cache-entry). |
| **Your classes**    | Created once by [`spec:make`](#scaffolding-is-specmake-not-a-build-step), on request. The build never touches them. | You, entirely, from the moment the file exists.                                                                 |

The generated kind should be unmistakable at a glance and at grep-time: its own directory, its own namespace, and a
header on every file saying it is generated and will be overwritten. A developer should never have to wonder which side
of the line a file is on — and neither should an AI agent working in the repository, which is a first-class
consideration for this package.

## The split is what makes a contract change loud

The mechanism is ordinary PHP, and that is the point: **generated abstract classes and interfaces, extended by
human-written concrete classes.**

Say an operation gains a required parameter. The build rewrites the generated abstract, whose method signature changes.
Every concrete subclass a developer wrote now fails to satisfy its parent, and PHP — plus PHPStan at
[level 8](../project/stack.md) — says so immediately, by name, before anything runs.

That is the whole payoff of Spec-First expressed in one behavior: **a change to the contract becomes a compile-time
error in the code that implements it, not a 500 in production.** It is also why the generated side must be free to
change shape without asking permission. It can only be free if nobody has hand-edits in it to protect.

## The build command: `spec:build`

One command, run after any change to the specification, producing every derived output: the routes, the abstract
controllers, the response DTOs and the validation. Everything it writes, it owns.

**Shipped, in the form Phase 1 asked for.** It reads the document named by `lara-spec-first.spec.path`, extracts the
contract's operations, plans every file in memory, and writes. Which specification it reads is one root document rather
than a list: multi-file contracts are written as local `$ref`s from it, and a list of roots would raise questions
nothing has settled — whose order wins, and whether each gets its own generated tree. Widening it later is non-breaking.

**The two steps are the design rather than structure for its own sake.** A build that emitted as it went would leave a
half-generated tree behind the first operation it could not handle, and half-generated output from a broken contract is
worse than no output: it analyses, it autocompletes, and it lies. Planning first means a refusal costs nothing, and the
working tree is exactly as it was.

Its properties:

- **Idempotent.** Running it twice in a row changes nothing the second time. If a build produces a diff on an unchanged
  spec, that is a defect. A formatter counts as part of that promise, which is why
  [it gets its own section](#your-formatter-and-the-build-both-want-to-own-these-files).
- **Ordered, and it stops.** Check the vendored references are present, parse, normalize in memory, **compare against
  the specification's previously committed version, read from git**, then generate. A spec that fails
  [the doctor's](./doctor.md) hard checks does not reach the generator — half-generated output from a broken contract is
  worse than no output. The comparison sits before generation for the same reason: nothing is written until it is known
  to be allowed. See [the baseline](./lifecycle.md#unstable-by-default-and-what-stable-costs-us) for what "previously
  committed" means and why it depends on git history rather than a file the build writes.
- **It never writes outside its own directories.** No exceptions, no conditions. This is the
  [invariant](#the-invariant-a-build-never-destroys-human-work) in one sentence, and it is testable — which is the point
  of stating it without a clause.

### Your formatter and the build both want to own these files

**Shipped, and learned the hard way rather than designed.**

Idempotence is a property of the _pair_, not of the build alone. Almost every Laravel project formats its code, and a
formatter rewriting a generated file is a formatter the next build undoes — so the two rewrite each other forever, a
`git status` is never clean, and the promise above quietly stops being true. It is not hypothetical: this package's own
Workbench application caught exactly this, because Pint's Laravel preset inserts a blank line before an annotation
(`phpdoc_separation`) and the generated docblock did not have one. No test saw it, because the tests wrote to a
temporary directory where no formatter was looking.

There are two halves to the answer, and only one of them is ours.

**Ours: the build emits the canonical form.** What it writes is what Pint's `laravel` preset would have produced, and
[the test suite runs Pint over the generated output](https://github.com/Gcob/lara-spec-first/blob/main/tests/Feature/Console/BuildCommandTest.php)
to keep it that way. A project on the default preset needs to do nothing at all.

**Yours: exclude the generated tree from your formatter anyway.** We can be canonical under one rule set, not under
every rule set — a project on `psr12`, on `symfony`, or with rules of its own will disagree with us somewhere, and it
should win in its own codebase without a fight. In `pint.json`:

```json
{
    "preset": "laravel",
    "exclude": ["app/Http/Generated"]
}
```

`exclude` takes directories; `notPath` takes single files, and `notName` takes filename patterns. `php-cs-fixer` has the
same shape through its own `Finder`. One detail worth knowing, because a pipeline can lose it: **`exclude` applies to
the default scan, not to a path passed explicitly** — `pint app/Http/Generated` still formats the tree, so a CI step
that names paths has to leave it out itself.

**And there is nothing lost by excluding it.** These files are
[rewritten from scratch on every build](#three-kinds-of-file-and-only-two-are-the-builds), so formatting them is work
with no product: the result is discarded the next time the specification changes.

### Native filesystem calls, not a Storage disk

**Shipped.** It looks wrong in a Laravel package, so it is worth stating why it is not.

**There are two filesystems in Laravel and conflating them is the whole trap.** `Storage`, backed by Flysystem, is for
application data whose location is a deployment concern — an upload, an export, something that may live on S3 tomorrow.
`Illuminate\Filesystem\Filesystem` is a thin wrapper over the native functions, and it is what every `make:` command in
the framework uses to write a class. "Disks are the norm" is true of the first and not of the second.

**The rule that decides it: is _where_ this file goes a deployment concern, or a language one?** Generated PHP has to be
on the local filesystem at a path PSR-4 maps to a namespace, or nothing can load it. A disk would let a project point it
at S3 and produce files that autoload from nowhere — a setting whose only outcome is a broken application.

**And this package answers the same question the other way where the other way is right**, which is the best evidence
the rule is doing work rather than rationalizing: the [sanitized public specification](#where-the-public-copy-goes) is
configured as a **disk**, because that document is served, and whether it is served from local storage, S3 or a CDN is
exactly the kind of thing a deployment decides. One rule, two answers, no inconsistency.

Native calls rather than `Illuminate\Filesystem\Filesystem` is then a smaller choice, and deliberate on two grounds:
`GeneratedTree` stays a plain object a unit test can build with no container, the pattern this package already follows
for its guards; and the wrapper would fix nothing, since its `put()` also reports failure by returning `false`.

#### Permissions, and the mode that looks alarming

`mkdir` is called with `0777`, and **the umask decides, not that number**: the process umask is subtracted from it, so a
normal `022` yields `0755` and a shared-group `002` yields `0775`. Passing the permissive value defers the policy to the
operator instead of overriding it, and it is exactly what the framework's own generators pass —
`GeneratorCommand::makeDirectory()` calls `makeDirectory($path, 0777, true, true)` for every `make:` command. **Nothing
is ever `chmod`-ed afterwards**, for the same reason: forcing a mode would override the policy this defers to.

**What actually needed fixing was not the mode but the silence.** The scenario a container makes ordinary is that the
build runs as one user and the tree belongs to another — root inside Docker, or a deploy step. PHP reports that by
returning `false` and emitting a warning, so a build that ignored the return value counted a file as written that was
never on disk, reported success and exited zero. Every write is now checked, and an unwritable tree stops the build with
a message naming the path. **A refusal leaves the tree exactly as it was**, which is the same promise
[the planner](#the-build-command-specbuild) makes one step earlier.

The friction worth naming rather than solving: if a build has run as another user, the developer on the host cannot
overwrite the result. That is a property of any generator in a container, and the answer is the one this repository
already uses for itself — map the host UID and GID into the container, as `compose.yaml` does. It is not something a
package can fix from the inside, and inventing a permission strategy here would only add a second policy to disagree
with the operator's.

### Remote references during a build: frozen by default

**Decision: the build never reaches the network unless asked.** A missing vendored document is an error that names the
flag to run, not an excuse to open a socket.

This is a deliberate reversal of the earlier "fetch whatever is missing" default. That default was convenient, and
convenience is the wrong tiebreaker for the one operation that can change an API contract without anyone deciding to.
Under a frozen default:

- A fresh clone builds **offline**, because every vendored copy is committed.
- Adding a new `$ref` to the spec fails the build once, with a message saying exactly what to run. One deliberate
  command, and the new document lands in the next commit as a reviewable diff.
- A missing vendored copy in CI or production means somebody forgot to commit it — the pipeline says so instead of
  papering over it with a fetch.
- There is no environment-dependent behavior to reason about. The build does the same thing on a laptop and in CI, which
  is the property that makes a build trustworthy.

Fetching therefore has one entry point in `build`: an explicit flag, whether the document is missing or already
vendored. Working name `--update-refs`, matching the install/update vocabulary the
[dependency framing](./remote-references.md#borrowing-the-dependency-manager-shape) already borrows. Whether missing and
stale documents need _separate_ flags is open — one flag is simpler, two let you add a reference without silently
refreshing the others.

None of which should make designing an API tedious. That is what [watch mode](#watching-specwatch) is for, and it is a
different command precisely so that `build` can stay this strict.

### Which generated code is committed

**Decision: the package does not decide. `.gitignore` does.**

The build writes files; git decides which are tracked. That is already every consumer's mechanism for "I do not want
this in my repository", it needs no config key, no documentation of its own, and no opinion from us. Adding a config
option here would be inventing a second, worse `.gitignore`.

**One exception, and it is not optional: the vendored references must be committed.** There is
[no lock file](./remote-references.md#no-lock-file-git-is-the-lock) — the committed copies _are_ the lock. Ignoring that
directory does not save you noise, it removes the only mechanism that makes a build reproducible and an old release
deployable. The doctor should detect it and report it as a finding rather than let it be discovered during an incident.

#### Two layers

**Decision: the build emits an interface plus an abstract class**, splitting the output along the line that matters:

| Layer              | Carries                                                                                                | Naturally                                                                  |
| ------------------ | ------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| **Interface**      | The contract surface: method signatures, DTO shapes, the things a change to the spec actually changes. | Committed — this is the diff a reviewer wants, and it is small.            |
| **Abstract class** | The mechanics that implement the interface. Predictable, derivable, and noisy in a diff.               | A candidate for ignoring, since an idempotent build reproduces it exactly. |

It fits the [split](#the-split-is-what-makes-a-contract-change-loud) rather than complicating it: the interface is what
a human subclass is checked against, so the compile-time error survives even if the abstract layer never enters version
control.

The cost of ignoring the second layer is that a fresh clone does not analyse, autocomplete or run until the build has
been run once — the `composer install` bargain, which this ecosystem already accepts. Which layer a given project
chooses to ignore stays that project's call: this is still
[`.gitignore`'s decision](#which-generated-code-is-committed), not a config key.

**Open:** whether the second layer is an abstract class or a trait. An abstract class gives one inheritance slot to the
developer and takes it; a trait leaves it free and composes, at the cost of not being able to declare abstract members
quite as directly. It is a question best settled against real generated output.

**And it no longer applies to DTOs, which is a reversal worth naming.** An earlier version of this document made
response DTOs the canonical example of this split: a generated abstract declaring the shape, a human subclass overriding
`from()`. [DTOs are now `final readonly`](#response-dtos) and their customization lives in a factory instead, because a
value object mirroring the contract has no behavior of its own to extend. The two layers still describe the controller
seam; they no longer describe DTOs. Said plainly so that a reader coming from the old version reads a changed position
rather than a contradiction.

## The specification the build reads is private

**Decision: the specification this package consumes is an internal document, and nothing assumes it is safe to
publish.** That is not caution for its own sake: the extensions that make the build useful are precisely the ones that
describe the inside of the application.

| Extension                                                                         | What publishing it hands out                                                       |
| --------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| [`x-model`](./controllers.md#how-the-semantic-is-detected)                        | Your Eloquent class names, so the shape of your database and its relationships.    |
| [`x-controller`](./controllers.md#the-specification-decides-what-is-customizable) | Your application's namespace layout, and which endpoints carry hand-written logic. |

Neither means anything to a consumer of the API, and both help somebody map an application they are attacking. A
document written for the build is simply not the same document as one written for the public, and treating them as one
file is how internal detail gets published by accident.

**Decision: `spec:build` can emit a sanitized copy for publication, and does so only when a project names a disk to put
it on.** Not by default, in the same spirit as the [remote-reference allowlist](./remote-references.md) and the factory
scan: a feature nobody asked for should not start writing files.

**The strip list denies by default rather than allowing by default.** Configuration says which extensions to _keep_, not
which to remove, and every other `x-` extension is dropped. The reverse would fail the day a project adds an extension
of its own and forgets to list it, which is exactly when the failure costs the most and is least likely to be noticed.
This is the same posture the allowlist takes for hosts, applied to information disclosure.

The default keep list is the extensions that tell a consumer something they can act on:
[`x-lifecycle` and `x-sunset`](./lifecycle.md) exist so a client can plan around how strong a promise is and when it
ends, so stripping them would remove the one part of this package's own vocabulary the public document should carry.

**`x-audience` is deliberately not on that list**, even though it is a consumer-facing extension elsewhere. Once
[internal operations are removed outright](#internal-operations-are-excluded-not-merely-stripped), every operation left
in the published copy is `public` — so the key would publish a constant, and a constant tells a reader nothing. It is
the exclusion that carries the information, not the annotation that survived it.

Two properties hold it together:

- **The public copy is output, never input.** The build reads the private document and nothing else, so there is never a
  question of which one is authoritative. It is generated, so it belongs to the build, carries a header saying so, and
  is never hand-edited — the same rule as
  [every other generated file](#three-kinds-of-file-and-only-two-are-the-builds).
- **The doctor reports what is being removed.** A strip list is a security boundary, and a security boundary nobody can
  see is one nobody maintains. Printing the resolved keep list, what it dropped, and how many operations were excluded
  turns "did we publish our model names" into a one-command answer rather than an audit.

### Internal operations are excluded, not merely stripped

Stripping a key and dropping an operation are different acts, and the weaker one is not enough. Removing
`x-audience: internal` from an operation still publishes the operation, which invites exactly the outside consumer the
extension existed to say there wasn't one.

**Decision: the public copy carries `public` operations only. An operation marked
[`x-audience: internal`](./lifecycle.md#two-keys-one-discriminator) is removed from it entirely.**

`public` being the default means an operation that says nothing gets published, and that is deliberate rather than
convenient: it is the direction [`lifecycle.md`](./lifecycle.md#two-keys-one-discriminator) already set for this key,
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
[required](./openapi-support.md#the-differences-the-strategy-must-absorb), and in either case far more likely a
misconfiguration than an intent, so it is a finding rather than a file. And exclusion gives
[breaking-change detection](./lifecycle.md#unstable-by-default-and-what-stable-costs-us) a second reason to care about
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
contract baseline is [read from git](./lifecycle.md#unstable-by-default-and-what-stable-costs-us) rather than kept
there. The public copy is the opposite case: it is derived, the build reproduces it exactly, and committing it would
mean reviewing a generated diff on every contract change. Being gitignored is the correct outcome for it.

Two consequences to state rather than let anyone hit:

- **A remote disk means the build writes over the network.** That is not what
  [frozen by default](#remote-references-during-a-build-frozen-by-default) forbids — that rule protects the build's
  _inputs_, since an input fetched silently can change the contract, and an output written somewhere cannot. But the
  asymmetry is worth naming so nobody reads it as an oversight, and a project may reasonably decide publishing belongs
  to its deploy step rather than to `spec:build`.
- **A published copy can go stale.** Edit the specification, forget to build, and the document being served describes a
  contract the application no longer honors — publicly, which is worse than the internal version of the same mistake. It
  is the same failure the [drift check](./doctor.md#what-it-checks) already exists for, and the published copy belongs
  in its scope.

**Open:** the config key names, whether the sanitized copy is emitted in the document's own format or normalized to
JSON, and whether an operation's `summary` and `description` need a keep-or-strip decision of their own — internal notes
end up in those fields far more often than anyone intends.

## Where generated code lives

**Decision: a config key, defaulting to `app/Http/Generated` and the namespace `App\Http\Generated`.**

Under `app/` because it is application code the developer will read, extend and debug, not a build artefact hidden in
`bootstrap/`. Under `app/Http/` because that is where Laravel already puts controllers, form requests and middleware —
everything generated here is HTTP-layer machinery, and it belongs beside the concrete controllers that extend it rather
than in a directory of its own invention. Configurable because no default survives contact with every project.

**The name has a job.** It appears in every `use` statement, every stack trace and every IDE autocomplete for the
lifetime of the project, so it should say _do not edit this_ without anyone having to look it up. `Generated` does that
in one word; a name like `Integration` says nothing about ownership, which is the only thing a reader needs from it at a
glance.

**One configurable root, with fixed sub-namespaces beneath it** — `Controllers`, `Data`, and whatever follows — rather
than a separate config key per kind of output. A team that keeps its DTOs in `App\Data` will notice the difference, and
it is a small one: these are files nobody may edit, so where they sit matters far less than for hand-written code. What
one root buys is worth more:

- **`.gitignore` is one line.** [The mechanism we chose](#which-generated-code-is-committed) works by directory, so a
  split tree means several entries, and a consumer who forgets one ends up with half a generated tree committed and half
  not.
- **"Everything under here is generated" is only a rule while there is one _here_.**
- **Widening later is a minor release, narrowing is a major one** — the same reasoning [`stack.md`](../project/stack.md)
  applies to version support. If per-kind overrides turn out to be wanted, they can be added without breaking anyone;
  starting with them and removing them cannot.

Two details that will otherwise be discovered the hard way:

- **PSR-4 requires the directory segment and the namespace segment to match, including case.** A standard Laravel
  application maps `App\` to `app/`, so `app/http/generated` autoloads as `App\http\generated` — legal PHP, and an
  immediate source of confusion. Every segment is capitalised in the default for that reason.
- **Path and namespace are two settings, not one.** Deriving one from the other means guessing at the consumer's
  autoload map. Both are configured, and the doctor checks they agree with what `composer` actually autoloads — a
  mismatch there produces class-not-found errors far from their cause.

The config key names and the default are public API surface under [rule 4](./openapi-support.md#the-four-rules).

### The routes are one file, and the only one the runtime opens

**Shipped.** `spec:build` writes it, `Routing\GeneratedRoutesLocator` locates it, and the service provider loads it at
boot.

**Decision: route registrations go in a single `routes.php` at the root of the generated tree**, beside the
sub-namespaces rather than inside one. It is the only generated file the runtime ever opens, and it is a script rather
than a class: PSR-4 has nothing to say about it, and the provider reaches it through Laravel's own `loadRoutesFrom()`,
which is what skips the file when the application's routes are already cached.

**A fixed name inside the configured root rather than a setting of its own.** The build owns every file under that root,
so a second key could only ever let the writer and the reader disagree about one filename.

**And the file is loaded through `Route::` calls rather than read as data.** A manifest the package walked at boot would
mean the runtime deciding something the build already decided, and it is the shape this document rejects everywhere
else. What the emitter writes is the registration itself, with the controller named as a
`[Controller::class, 'routeAction']` pair of plain strings, in the specification's own
[order](./openapi-support.md#route-order-the-spec-files-order-is-the-route-order).

**Decision: a missing file is silence, not an exception.** The reasoning is structural rather than lenient:

- **`spec:build` is a command of this package.** A provider that refused to boot without a generated tree would make the
  application unbootable exactly when the command that writes one needs to run. A fresh clone could never produce its
  own routes, which is a deadlock rather than a strict default.
- **It is a legitimate state,** because [`.gitignore` decides](#which-generated-code-is-committed) what a project
  commits, and the [two layers](#two-layers) already accept that a fresh clone does not run until the build has.
- **Reporting it is [the doctor](./doctor.md#what-it-checks)'s job**, where it is caught before a deploy rather than
  during one. This is the same division of labour as everywhere else here: refusing to load and reporting a fault are
  different jobs.

**A missing file and an unusable setting are not the same thing, and only the first one is silent.** A `generated.path`
that is empty, or is not a string at all, throws and names the key: nothing can be looked for without a path, so
carrying on would mean registering no route on an application that asked for some. The distinction is worth stating
because the two failures look alike from the outside and have opposite correct answers.

**But not in the console, and that exemption comes from the same reasoning rather than softening it.** Throwing
everywhere would take `config:clear`, `spec:build` and `spec:doctor` down with the application, so a project that has
cached a broken configuration would have no way out but deleting a cache file by hand. A request fails loudly; the
commands that repair the installation stay reachable. It is the deadlock argument above, applied to a setting instead of
to a file.

An absolute value for the configured path is taken as written rather than joined under the application root. A generated
tree outside that root is a real monorepo layout, and joining an absolute path anyway produces a path that is silently
wrong rather than one that fails.

## Scaffolding is `spec:make`, not a build step

**Decision: the build never creates a class you will own. `spec:make` does, on request.**

Laravel already has this shape and every Laravel developer already has the reflex: a `make` creates one file, when you
ask, once. Reusing the word costs no new concept — and it removes the only exception the
[invariant](#the-invariant-a-build-never-destroys-human-work) ever had.

Nothing forces the build to do it instead, because an operation with no implementation is not a broken application —
provided the package says what happens to it.

### An unimplemented operation answers 501

**Decision: the build registers the route and points it at a package-provided handler that returns
`501 Not Implemented`**, with a body naming the operation and the `spec:make` command that implements it.

The two alternatives are worse, and for reasons this document has already committed to:

- **Not registering the route** would mean the contract describes an endpoint that does not exist, and a client would
  get a `404` indistinguishable from a typo. That is [rule 2](./openapi-support.md#the-four-rules) violated at the level
  of the wire: the spec says the endpoint is there, and nothing anywhere says otherwise.
- **Pointing at a class that does not exist** produces a class-not-found fatal at request time — an internal error
  blaming the consumer's application for a state the package created on purpose.

`501` is the status code HTTP already has for exactly this: the server recognizes the request and has not implemented
it. It is honest to the client, it is greppable in logs, and it is the seam the [Faker mock](../project/roadmap.md)
plugs into in Phase 2 — same route, same handler position, a better answer in the body. Nothing about the Phase 1 shape
has to change for the mock to arrive.

This is what an operation gets when the build could
[detect no CRUD semantic for it](./controllers.md#how-the-semantic-is-detected) — no `x-model`, or a shape the package
refuses to guess at — and nobody has overridden it. An operation the build did understand answers from a generated
default with no subclass at all; [`controllers.md`](./controllers.md) owns which is which.

### Where your classes go

**In the application's own controller location, not in the generated directory.** Two reasons, and the first is not a
matter of taste:

- **`.gitignore` works by directory, and we made `.gitignore` [the mechanism](#which-generated-code-is-committed).** Put
  your classes inside the generated tree and a consumer who ignores that tree loses their own work. That single fact
  rules the option out.
- **It is an ordinary Laravel controller.** Once the file exists it has nothing to do with this package except that it
  extends a generated class. Your conventions, your IDE, your tests and your `make:` habits all already point at that
  directory. The generated abstract is the unusual object here; the concrete class is not.

**The consequence to state plainly:** the generated route refers to your class by its fully-qualified name, so the name
and namespace are load-bearing. Moving the file is fine; moving it somewhere it no longer autoloads under the expected
name breaks the route. The [doctor](./doctor.md) reports that as a missing implementation rather than letting it surface
as a class-not-found at runtime.

### Not a flag on `spec:build`

`spec:build --make` is the tempting shortcut, and the analogy that suggests it does not survive contact.

`make:model --controller --migration` creates several files **for one thing you just named**: one subject, one
invocation, a human present. `build` does not operate on an operation you named — it operates on the whole
specification. So `spec:build --make` means _scaffold every missing implementation_, which is how a hundred empty
classes get committed by accident.

The deeper cost is that it makes the invariant conditional again: _the build never writes a file it does not own, unless
you pass `--make`_. The architecture test stops being absolute, and the next feature has a precedent to point at.

The counter-argument is real and worth recording, because it comes from this document's own logic: a flag typed by a
human **is** explicit intent, exactly as [watch](#watching-specwatch) is. But that is precisely why watch is a separate
command rather than a flag — a flag ends up in a Procfile or a deploy script, and then it is creating files unattended.
Same risk, same answer. It is also why watch cannot scaffold either: watch must never produce output `build` would not.

### The build names the command instead of running it

What the shortcut was really asking for is ergonomics, and those can be had without touching the invariant. **When the
build finds operations with no implementation, it names the command rather than running it** — the same pattern as the
[rename report naming the files to fix](#how-it-says-it).

**The atomic form names one operation, and every other form is sugar over it:** `spec:make showUser` scaffolds
[one controller](./controllers.md#one-controller-per-operation-one-method-named-routeaction), carrying whichever
[CRUD default its own specification implies](./controllers.md#how-the-semantic-is-detected). Nothing else in this
package creates a grouped file, so there is nothing a bulk invocation could produce that is not simply this, run several
times.

The trap is printing one line per operation. A specification with two hundred operations, on the day somebody adopts
this package, would answer with two hundred commands — which is not a list, it is a wall, arriving at the worst possible
moment. So the build **summarises, and the [doctor](./doctor.md) holds the full list**, which is the division of labour
those two commands already have.

It summarises **by `tags`**, because the specification already carries the author's own grouping and inventing a second
one would be worse than using theirs — this is a grouping of the _printed list_, never of the files `spec:make` creates,
each of which stays
[one controller for one operation](./controllers.md#one-controller-per-operation-one-method-named-routeaction):

```
47 operations have no implementation:
  Users (12)   php artisan spec:make --tag=Users
  Orders (8)   php artisan spec:make --tag=Orders
  … 5 more tags. Full list: php artisan spec:doctor
```

Which settles the bulk question that was open here, and revises the earlier reasoning: the objection was never to bulk
itself, it was to `build` doing it as a side effect. **`spec:make --tag=` and `--all` are legitimate**, because a human
typed them and creating files is that command's entire job — a loop over the singular invocation above, not a second
mechanism. Two guards keep the hundred-empty-classes scenario away: bulk is never the default, and it lists what it is
about to create and asks before doing it.

Adopting tag by tag is also the shape [Phase 3](../project/roadmap.md) wants — a migration that proceeds route by route
rather than in one leap.

### Per-type flags belong here

The `make:model -mc` instinct is right; it just attaches to this command rather than to `build`. Once `spec:make` is the
thing that takes an operation's name, flags for what to create alongside it are natural and bounded — a test, a DTO
subclass, a policy — because they all concern the one operation you named.

**Open:** which types earn a flag. The list should be short, and each entry has to be something a developer genuinely
wants _per operation_ rather than something the build already produces for the whole contract.

## Naming, and the rename problem

The generated class and method names come from `operationId`. That makes an `operationId` far more than a label: **it is
the name of the class a developer extends**, so renaming one in the spec renames a class in their application.

The position on this is the project's position on API design generally: **designing an API is a skill, and changing an
identifier is a versioning decision.** The package is not going to hide that, and versioning is the right answer. But
there is a difference between refusing to hide a consequence and leaving a beginner to discover it from a fatal error,
and the difference costs us very little.

### Identity is the path and the method, not the name

The distinction that makes help possible: an operation's **identity** is its path plus its HTTP method, which is what
actually addresses it. Its **name** is `operationId`, which is what we generate from. Renaming an operation therefore
changes the name while the identity holds still — and a build that knows both can tell the difference between a rename
and a deletion.

Identity has to be normalized to be useful: **the names of path parameters are not part of it.** Renaming `/users/{id}`
to `/users/{userId}` changes nothing a client can observe — the URL on the wire is identical, and the template variable
is documentation. Identity is therefore the method plus the path with its parameters reduced to positions, so that
rename produces no diff at all. It also means `/users/{id}` and `/users/{slug}` share an identity and collide — which is
correct, because those two routes already collide in the router, and surfacing it is a service rather than a limitation.

That requires no new state file. The build reads the generated tree before overwriting it, and every generated file
already carries [the pointer it came from](#the-source-map). Comparing the two gives:

- **Renames, reported as renames.** _This operation was `listUsers`, it is now `indexUsers`; the class you extended has
  been replaced._ Naming the old and the new turns a fatal error into an instruction.
- **Orphans, reported by name.** A human class extending a generated abstract that no longer exists is detectable, and
  is exactly what a rename leaves behind. The [invariant](#the-invariant-a-build-never-destroys-human-work) means their
  work is still there — it is just no longer connected to anything, and nobody should have to find that out at runtime.

The honest limit: when the path itself moves, identity and name change together and a rename becomes indistinguishable
from a delete plus an add. The build should say that it cannot tell, rather than guess.

### How it says it

A rename is only useful as a message if it names the code that has to change. The build knows the old fully-qualified
class name it is about to replace, so it can find the references itself: scan the application for that symbol and
**report the files that mention it, with line numbers**, alongside the old and new names.

That turns the output from _something was renamed_ into _these four files reference a class that no longer exists_,
which is the difference between a notice and a fix. It is a token scan over PHP the consumer already has — no AST work,
no runtime reflection, nothing to keep in sync.

In [watch](#watching-specwatch) the same report arrives while the developer is still holding the context in their head,
which is when a rename costs almost nothing to absorb. That is the strongest argument for watch mode existing at all.

**Open:** how prominent this is — a heading in the build output, a doctor finding, or a non-zero exit until the
references are updated. Failing the build is defensible under [rule 2](./openapi-support.md#the-four-rules) and might be
intolerable in watch. Probably different answers for the two commands.

### When `operationId` is absent, derive from method and path

**Decision: the fallback is the operation's HTTP method and its path.** There is nothing else that both exists on every
operation and means something to a reader. `GET /users/{id}` becomes `GetUsersIdController`.

**Revised, and the revision is worth naming rather than hiding.** This rule used to say the _normalized_ path, so that
parameter names were excluded and renaming `{id}` to `{userId}` could not rename a class. What removed that cost was a
later decision: a class with no `x-controller`
[is `final`](./controllers.md#the-specification-decides-what-is-customizable), so nothing may extend it and no import
can depend on it. Nobody can be hurt by a name nobody may reference, and what is left is that `GetUsersIdController`
tells a reader which endpoint it serves where `GetUsersParamController` does not. **Identity stays normalized
regardless** — that is a different question, asked for rename detection rather than for naming, and the two must not be
conflated.

The objection to raise and dismiss: deriving from the path means that reorganizing URLs renames classes. True — and
**proportionate**, because changing a path _is_ a change to the contract. Consumers have to update their calls; you
having to update a class name is the same event, visible in your own code. For a `stable` operation the build already
refuses the change until [`info.version`](./lifecycle.md#unstable-by-default-and-what-stable-costs-us) says so, and for
a `beta` one churn is what `beta` means. The case that would have been unfair — renaming a path _parameter_, which
changes nothing on the wire — is already excluded by normalizing identity.

What the fallback genuinely costs is readability: a derived name will never read as well as `listActiveSubscriptions`.
That is an argument for writing `operationId`, not against having a fallback, and it is the kind of nudge the doctor
should make rather than the build enforce.

**Decision: `operationId` is required on `public` + `stable` operations, and optional everywhere else.** A stable
operation's generated class name is a promise made to your own codebase, so it deserves to be chosen rather than
computed — while a `beta` or `internal` operation can be sketched without ceremony. The rule reuses the
[lifecycle](./lifecycle.md#unstable-by-default-and-what-stable-costs-us) vocabulary instead of inventing one of its own,
and it lands where it costs least: nobody meets it while exploring, and everybody meets it at the moment they promise an
endpoint to someone.

It also means promoting an operation to `stable` is the moment its name gets chosen deliberately — which is exactly when
a derived name would otherwise harden into something nobody picked and nobody can now change without a major version.

**Open:** collisions between two `operationId` values that differ only in characters PHP cannot use in an identifier,
and whether the build refuses them outright.

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
  [the runtime never sees the spec](#the-runtime-never-sees-the-spec), there is no request path left that could fetch
  anything, in watch or anywhere else. What watch changes is how often the build runs and whether it may reach the
  network while doing so, never what happens during a request.
- **Rebuild on change**, which is the point of the mode.

Two rules keep it honest:

- **Watch must never produce output `build` would not.** It is `build` plus triggers plus network permission — not a
  second generator. The moment watched output differs from built output, "works on my machine" is back and the package's
  core promise goes with it.
- **The mode has to be visible while it is on.** A long-running process quietly fetching remote documents into your
  working tree should say so, continuously and unmistakably. Silence here would be the same mistake this document
  rejects everywhere else.

### Borrowing from bundlers, and where to stop

The `dev` versus `build` split is exactly the shape module bundlers converged on, and the ergonomics are worth taking.
**The divergence is not.**

Bundlers accept that development and production output differ, and "works in dev, breaks in prod" is the famous,
recurring price. A package whose entire purpose is that code and contract cannot disagree cannot pay that price. So the
split is in the **process**, never in the **product**: watch adds triggers, fetching and diagnostics; it does not add,
remove or reshape a single generated line.

The one bundler nicety that does translate is [the source map](#the-source-map), and it turns out to be worth having in
every mode rather than only in development — which is why it has its own section rather than living here.

## The source map

**Decision: every generated file carries the JSON pointer it came from** — the operation, the schema, the exact position
in the specification.

**And the file it points into is named from the project root, never absolutely.** Whether a project commits its
generated tree is [its own choice](#which-generated-code-is-committed), so an absolute path is a defect waiting for the
first project that does: it differs between every developer and every CI runner, which is a diff nobody made, and it
publishes one machine's directory layout — a username included — into a repository. The root is the nearest ancestor
holding a `composer.json`, which for an ordinary application is the same directory as `base_path()` and stays correct
where the two differ. A specification genuinely outside any project keeps its absolute path, because there is no shorter
honest name for it.

It is the same idea a bundler's source map serves, and the same need: generated code is read by people who did not write
it, and the first question any of them has is _where did this come from?_ A developer debugging, a reviewer judging a
diff, an AI agent working in the repository — all three are one annotation away from the contract instead of grepping
for it.

It costs nothing at runtime in PHP, so it is emitted **unconditionally**, in every mode. That is what keeps
[watch and build output identical](#borrowing-from-bundlers-and-where-to-stop), and it is why this is not a
development-only nicety.

Two other decisions depend on it, which is the real reason it stands alone:

- [Rename detection](#identity-is-the-path-and-the-method-not-the-name) compares the pointers in the existing generated
  tree against the ones the new build would emit. Without the annotation there is no comparison to make and no rename to
  report.
- Breaking-change detection is keyed by the same identity, so a finding in that comparison and a header in a generated
  file name the same thing.

## Every generated file explains itself

The source map answers _where did this come from_. It is one part of a larger norm, and this section owns the whole of
it.

**Decision: every file the build emits carries a docblock written for someone who did not write it, and it is a
requirement rather than a courtesy.** Not a banner saying "generated, do not edit" and nothing else — that says who owns
the file, which is [already settled elsewhere](#three-kinds-of-file-and-only-two-are-the-builds), and it is not what a
reader opening the file actually needs.

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
  [the same pattern](#the-build-names-the-command-instead-of-running-it) as the build naming commands rather than
  running them.
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
`git diff --exit-code` is how a pipeline checks the tree is current until [the doctor](./doctor.md) can, and a timestamp
makes that check fail always, which is the same as it saying nothing.

**`created_at` is worse, for a different reason.** Preserving it would mean the build reading its own previous output to
recover a date, so what it emits would depend on what was already there rather than only on the contract. Two things go
with that: the same specification would produce different files on a fresh clone than on an existing checkout, which is
reproducibility gone; and the file would carry a fact the specification cannot express, held only in a tree
[a project is free to delete](#which-generated-code-is-committed). A small database in the one place this document calls
disposable.

**Both fields already exist, and more accurately than a comment could state them.** `updated_at` is the file's
modification time, and it means something _because_ the build leaves unchanged files alone: it says when the content
last actually changed, not when a command last ran. `created_at` is git, with the author and the diff attached. This is
the same reasoning that makes [git the lock file](./remote-references.md#no-lock-file-git-is-the-lock) for vendored
references and [git the source of the contract baseline](./lifecycle.md#unstable-by-default-and-what-stable-costs-us):
the repository already records time, and reimplementing that inside a generated comment would be a worse copy of it.

**And if the worry behind the question is staleness, a date does not answer it.** A file written yesterday can be
perfectly current, and one written a minute ago can be stale if the specification moved since. What answers it is
comparing against the contract, which is [the drift check](./doctor.md#what-it-checks).

**It is testable, and it should be tested.** The docblock is output, so the generator's own test suite asserts it is
there and carries all three parts — the same way
[any other behavior earns a test](../../AGENTS.md#automated-tests-are-required). A norm that only lives in prose erodes
the first time someone adds a new kind of generated file in a hurry.

**Open:** how much of this is a fixed template versus per-kind, and whether the findings section has a machine-readable
form. The doctor already learned that lesson — its `--json` exists because
[tooling and agents should not have to parse prose](./doctor.md#the-contract) — and the same argument plausibly applies
here, against the cost of putting a data format inside a comment.

### A reference to generated code says what to do when it goes missing

A class-not-found on generated code is the most likely error anyone meets with this package, and the least informative
one PHP knows how to raise. **Decision: every reference to generated code carries a comment saying what to do about
it**, grouped above the block rather than repeated over each line — four generated references in one file should not
mean four copies of one paragraph.

**Two commands write that comment, and neither may write the other's files.** The build writes it into generated files
that reference other generated files. Only [`spec:make`](#scaffolding-is-specmake-not-a-build-step) writes it into a
file a developer will own, once, at the moment it creates that file — the build
[never writes outside its own directories](#the-invariant-a-build-never-destroys-human-work), and that rule has no
exception for a helpful comment. From then on the comment belongs to the developer, including the freedom to delete it.

**The comment sits where the reference is, which for a scaffolded controller is not an import.** Because a custom
controller
[extends its generated parent by fully-qualified name](./controllers.md#two-classes-found-by-name-rather-than-by-a-scan),
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

Three situations sit behind those three lines, which is why the first answer is a command rather than an explanation:

- **The build has not run here.** On a fresh clone this is the normal state rather than a mistake, because
  [`.gitignore` decides what is committed](#which-generated-code-is-committed) and a project may legitimately ignore the
  generated tree — the `composer install` bargain, stated in [two layers](#two-layers). Running it is the whole fix.
- **The name changed in the specification.** A controller's generated name follows
  [`x-controller`](./controllers.md#the-specification-decides-what-is-customizable) and a DTO's follows its schema name,
  so the class moved because somebody edited one of those. The build
  [reports that as a rename](#identity-is-the-path-and-the-method-not-the-name), naming the old and the new, so running
  it does not merely fix the tree — it tells you what to change the import to.
- **It was removed outright.** Only here does the build have nothing to offer, because there is no new name to report,
  and the specification's own history is what says what happened.

It is also, deliberately, the last line of defense rather than the first. [The doctor](./doctor.md) reports drift and
orphans before anyone reaches a stack trace; this comment is for the developer who met the error first and has not
thought to run it yet.

## Response DTOs

The DTOs are how a response schema becomes a PHP type. Two properties, and the tension between them is the design:

- **The shape is generated, and not yours.** Properties, types and nullability come from the response schema. A
  hand-edited shape is drift from the contract by definition, and it is exactly what Spec-First exists to prevent.
- **The behavior is yours.** Hydration is where real applications differ, and a generated DTO you cannot teach to build
  itself from your model is a generated DTO people will wrap or abandon.

**Decision: a DTO is `final readonly`.** It is a value object mirroring a piece of the contract, not a class with
behavior of its own to grow — the same reasoning that makes
[`Operation`](https://github.com/Gcob/lara-spec-first/blob/main/src/Contract/Operation.php) and its neighbors
`final readonly` in this package's own types. Which rules out the [two-layer split](#two-layers) that customization
elsewhere in this document relies on: there is no abstract DTO to extend, because there is no DTO to extend, full stop.

### Factories, not subclasses, are where behavior lives

**A note on the name, before anything else.** This "factory" is the design pattern — a class whose one job is
constructing another object — not Laravel's own model factories, which generate fake data for tests and carry
`HasFactory` and `Factory::class` with them. The two share a word and nothing else. Nothing here touches, extends, or
competes with `Illuminate\Database\Eloquent\Factories`.

Hydration therefore cannot live on the DTO itself. It lives one level removed, in a **factory** — an ordinary class, not
final, whose only job is turning a source (a model, an array, whatever the response needs) into the DTO.

**Decision: the build generates one factory per DTO**, mapping by naming convention — the same nomenclature-driven
matching already used [when `operationId` is absent](#when-operationid-is-absent-derive-from-method-and-path) — with a
default implementation that covers the ordinary case: properties that already exist on the source, under the same name.
This is what makes the other ninety-six DTOs in a hundred-DTO contract need nothing from a developer at all.

### Overriding a factory: extend it, in a directory the project declares

Most response shapes need nothing beyond the default mapping. The few that do should not cost the other ninety-six.
**Decision: a project overrides a factory by writing a class that `extends` the generated one** — no fixed name, no
fixed file, and no service provider to touch.

**The generated factory never disappears, even once overridden.** It is not replaced, it is extended — the override
would have nothing to inherit from otherwise, and a developer would be starting from an empty file instead of a working
default mapping they only need to adjust in part. Every generated factory therefore exists for every DTO, always,
whether or not a project has ever looked at it.

The build finds the override itself, by scanning a **configured set of directories — not the whole project** — for a
class extending each generated factory, and wiring whichever it finds in place of the generated default. The directories
are named in configuration, the same shape as [`remote_references.allowed_hosts`](./remote-references.md): empty by
default, and nothing is scanned until a project says where to look. Scanning the whole application would mean touching
every autoloaded class, vendored packages included, on every build, for a feature four DTOs out of a hundred will ever
use — the cost has to be bounded by what the project actually declares, not by how large `vendor/` happens to be.

**Exactly one override per factory.** Extending a generated factory twice is not a project needing two behaviors from
one thing, it is two behaviors with no rule for which wins. The build refuses to guess: finding two classes that extend
the same generated factory is a hard error, naming both offending classes and the factory they both claim, not a silent
pick of whichever the classmap happened to load first.

This is detection **at build time**, deliberately, not a runtime `class_exists()` check scattered across every place a
DTO gets built — the same reasoning as [everywhere else in this document](#the-runtime-never-sees-the-spec): explicit
over dynamic, and the cost paid once rather than on every request. The consequence to state plainly: an override added
without rerunning the build has not taken effect yet — a case for [drift](./doctor.md#what-it-checks), not a new failure
mode.

**Open:** the config key's name, and whether it recurses into subdirectories by default; the exact mechanism for finding
the `extends` relationship — reflection over the classes the configured directories autoload is the leading answer,
rather than [the token scan rename detection already uses](#how-it-says-it), since an `extends` clause needs the
language's own resolution of `use` imports and aliases to be trustworthy, not a match on spelling; and the name of the
exception thrown when two classes claim one factory.

### What a factory's docblock carries

Factories follow the norm [every generated file follows](#every-generated-file-explains-itself); what is specific to
them is what counts as a finding worth reporting. **The mapping's own result:** which properties matched the source by
name, which did not, and anything a reader should check before trusting the default — because a factory that silently
skipped a property is the one thing a reader cannot see by looking at it.

Its navigation line is the general rule applied to
[the override scan](#overriding-a-factory-extend-it-in-a-directory-the-project-declares): the `spec:make`
[flag](#per-type-flags-belong-here) that scaffolds an override when none was found, replaced by `@see` at the detected
class when one was. Since the generated factory
[stays in place even when overridden](#overriding-a-factory-extend-it-in-a-directory-the-project-declares), that
annotation is the only thing distinguishing the default a reader is looking at from the behavior that actually runs.

`spatie/laravel-data` remains a candidate for the generated shape itself — its casting, validation and serialization are
useful independently of who builds the object — but its own `from()`-override ergonomics are no longer the fit they once
were: a `Data` object is not `final`, and this design deliberately does not lean on DTO-level inheritance for
customization. **Whether we depend on it or only take the shape is undecided** and belongs in
[`stack.md`](../project/stack.md) once settled — a dependency buys casting, validation and serialization for free, at
the cost of binding generated code to another package's API and release cycle.

## Appending into human-owned files

**Status: undecided, and deliberately not planned for the first release.**

The idea is PhpStorm's getter/setter generator: inject valid code at the end of an existing class without disturbing
what is there. It is attractive, and it is the one feature on this page that would break the
[invariant](#the-invariant-a-build-never-destroys-human-work) structurally, so it deserves a straight answer rather than
a maybe.

Why it is harder here than in an IDE: PhpStorm runs one action, on one file, with a human watching and undo one
keystroke away. A build runs unattended, in CI, across every file at once. The failure modes that follow are not
hypothetical — re-running duplicates injected code unless the tool can recognize its own previous output, which means
markers inside human files; a contract change requires _removing_ previously injected code, which is materially harder
than adding it; and formatting will fight Pint until somebody loses.

**The alternative that costs nothing:** generate a trait and have the developer `use` it. PHP already has a language
feature whose entire purpose is injecting members into a class, it composes with the abstract-class approach, and it
keeps generated and human files disjoint. Most of what the append idea promises is available this way, today, with no
rewriting of anyone's code.

If it is ever built anyway, three non-negotiables: delimited regions the build owns entirely, nothing outside those
regions ever read or written, and a hard failure rather than a guess when the region is missing or malformed.

## Open questions

- The config key names for the [generated location](#where-generated-code-lives) — the location's _default_ is decided,
  what the keys are called is not. Public API surface under [rule 4](./openapi-support.md#the-four-rules).
- Which [per-type flags](#per-type-flags-belong-here) `spec:make` accepts.
- Whether the second of the [two layers](#two-layers) is an abstract class or a trait.
- Whether fetching a _missing_ reference and refreshing a _stale_ one share one flag or take two.
- What [watch](#watching-specwatch) takes as parameters — in particular how its rebuild cadence is expressed, and how
  the mode announces itself while it is running.
- Whether `spatie/laravel-data` becomes a dependency or only an influence.
- The [factory override scan](#overriding-a-factory-extend-it-in-a-directory-the-project-declares): the config key's
  name, whether it recurses by default, the exact mechanism for finding the `extends` relationship, and the name of the
  exception thrown when two classes claim one factory.
- **Whether a generated file records the package version that emitted it.** Distinct from
  [the time, which is refused](#what-a-generated-file-deliberately-does-not-carry-the-time), and it is the difference
  that makes it worth considering: a version changes only when the emitter might genuinely produce something else, so
  stamping it costs a rewrite exactly when a rewrite is warranted rather than on every run. What it would buy is a
  reader — or a support conversation — being able to tell that a file came from an older emitter than the one installed.

    Not before the first tag, because there is no version to record until the package is published, and the shape is
    worth settling near the [name freeze](../project/roadmap.md#before-10-freeze-what-a-major-would-cost): once a header
    line is there, tooling reads it, and its format is then as much public API as a config key. The costs to weigh when
    it is decided: every upgrade rewrites the whole tree, which is loud for a consumer who tracks it, and a
    `git diff --exit-code` gate would fail across an upgrade for a reason that is correct but needs explaining.

- **Sequencing:** routes and abstract controllers are the Phase 1 target. Response DTOs and generated validation are
  Phase 2 — the same build command doing more, not a new one. See the [Roadmap](../project/roadmap.md).

---
title: Code Generation
audience: Users
covers: >
    What this subject rests on and what the build itself does: the invariant that a build never destroys human work, the
    boundary between build time and run time, the three kinds of file and who owns each, how the generated and human
    layers make a contract change loud, then `spec:build` proper — why a formatter has to be told to leave the generated
    tree alone and why the build emits the canonical form anyway, how a value from the document is escaped on its way
    into a literal or a comment, why the build touches the filesystem directly rather than through a Storage disk and
    what that means for permissions, why a remote reference is frozen during a build, which generated code a project
    commits, where generated code lives and why the routes are one file, why appending into a human-owned file is
    refused, and the questions the whole subject still has open.
read_before: >
    Writing anything that emits PHP from a specification, or changing what the build command does.
tags: [code-generation, openapi, scope, decisions, laravel]
---

# Code Generation

> **TL;DR**
>
> - `spec:build` reads the contract and emits `routes.php` plus one controller per operation, each answering 501 until
>   something implements it.
> - A build never destroys human work: generated code and your code never share a file, so the build is always safe to
>   re-run.
> - Nothing at runtime ever opens a specification. The provider loads one generated file and knows nothing about how it
>   was produced.
> - Generated abstracts extended by your concrete classes is what turns a contract change into a static analysis error
>   rather than a runtime surprise.
> - **Not built yet:** response DTOs, request validation, and the sanitized public copy.

Spec-First only pays off if the contract reaches the code. This document owns how it gets there: **one build command
turns the specification into PHP, and the result is safe to regenerate at any time.** It carries what the rest of the
subject rests on, and the build command itself; the four files beside it answer one question each, and are linked from
wherever that question comes up.

> **Almost all of this is intent rather than behaviour**, and like [`openapi-support.md`](../openapi-support.md) this
> file marks the difference per section rather than per file, so the banner does not become a little more wrong with
> every release. Items marked `Open` are undecided.
>
> **Shipped:** `spec:build` in its Phase 1 form, which resolves the specification and emits the routes and one
> controller per operation, each carrying
> [its own docblock](./generated-file-anatomy.md#every-generated-file-explains-itself) and answering
> [501](./scaffolding.md#an-unimplemented-operation-answers-501). It is idempotent, it plans before it writes, and it
> [never writes outside its own tree](#the-invariant-a-build-never-destroys-human-work). The provider
> [loads what it emitted](#the-routes-are-one-file-and-the-only-one-the-runtime-opens) and reads no specification to do
> it. The [`x-controller` seam](../controllers.md#the-specification-decides-what-is-customizable) is shipped, so an
> operation that declares one gets a parent it may extend and a route pointing at the child, and
> [`spec:make`](./scaffolding.md#scaffolding-is-specmake-not-a-build-step) scaffolds that child. Not built yet: response
> DTOs and request validation. Rename detection was designed here and
> [decided against](./generated-file-anatomy.md#rename-and-orphan-detection-decided-against).

What the build reads, and what it refuses to read, is a different subject and lives in
[`openapi-support.md`](../openapi-support.md).

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
[a different command's job](./scaffolding.md#scaffolding-is-specmake-not-a-build-step).

## The runtime never sees the spec

**Decision: the service provider does not know a specification exists.** Only the build-time commands read one. At boot,
the package loads generated PHP and nothing else — no YAML, no parser, no resolution, no `$ref`.

Explicit over dynamic, everywhere. The alternative — a provider that parses the contract on every boot — was never
really compatible with the rest of this document, and saying so plainly is cheaper than discovering it halfway through
the implementation.

What follows from it:

- **The parser is a build-time dependency in practice.** `cebe\openapi\` classes must never be reachable from the
  routing or request path. This is not a convention to remember: the architecture test contains the parser to
  [one namespace](../openapi-support.md#where-the-parser-sits-decided), which forbids it to the request path and to
  everything else at once. The assertion was written before anything imported the parser and is binding now that
  `OperationExtractor` does.
- **Boot cost is loading PHP**, which is what `route:cache` and the opcode cache already optimize. No work to memoize,
  no cache of our own to invent.
- **The boundary is the production request path, not the process.** Serving a real application's traffic never involves
  a specification. Other contexts plausibly do, and pretending otherwise now would only mean rewriting this section
  later: contract testing has to compare a live response against the contract, and a
  [mock server](../../project/roadmap.md) is a spec-driven server by definition. Those are separate execution contexts
  with their own rules. **Deferred deliberately** — the contexts get enumerated when the first one is built, not guessed
  at now. Nothing about containing the parser to `Parsing\` blocks them: a mock server reads a contract through the same
  door as everything else.
- **It creates one new failure mode, and it must be named:** edit the spec, forget to build, and the application serves
  the previous contract without a word — because nothing at runtime knows a spec exists to compare against. **Detecting
  that drift is the doctor's job**, which makes it a required CI check rather than a convenience. A package this strict
  about contracts cannot ship the one silent way to be out of date.

## Three kinds of file, and only two are the build's

| Kind                | Lifecycle                                                                                                                           | Who owns it                                                                                                      |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| **Generated**       | Rewritten from scratch on every build.                                                                                              | The package. Never edit — your edit is gone on the next run, by design.                                          |
| **Vendored inputs** | Never fetched unless asked; [frozen by default](#remote-references-during-a-build-frozen-by-default).                               | Upstream. See [remote references](../remote-references.md#a-remote-reference-is-a-dependency-not-a-cache-entry). |
| **Your classes**    | Created once by [`spec:make`](./scaffolding.md#scaffolding-is-specmake-not-a-build-step), on request. The build never touches them. | You, entirely, from the moment the file exists.                                                                  |

The generated kind should be unmistakable at a glance and at grep-time: its own directory, its own namespace, and a
header on every file saying it is generated and will be overwritten. A developer should never have to wonder which side
of the line a file is on — and neither should an AI agent working in the repository, which is a first-class
consideration for this package.

## The marker is how the build recognizes its own output

**Every generated file carries the same line, `@generated by lara-spec-first`, and it is load-bearing rather than
decoration.** It is the one thing that tells the build which files are its to delete.

That matters because "rewritten from scratch" has to mean the tree matches the contract rather than accumulating what
the contract used to say. **A controller for an operation the specification no longer has is pruned**, or the generated
tree slowly fills with classes nothing routes to. Pruning is therefore a deletion the build performs on its own, which
is exactly the act [the invariant](#the-invariant-a-build-never-destroys-human-work) exists to bound — and the marker is
what bounds it. **Only a file carrying it is ever removed**, so a file somebody wrote inside the generated tree survives
a build even though it should not be there.

**One exception, stated rather than left to be discovered: an empty directory inside the tree is removed whoever created
it.** A directory carries no marker, so there is no way to tell one the build made from one a person did, and leaving
`Controllers/` behind after its last controller was pruned reads as a bug. Nothing that holds a file is touched, which
is the part that matters.

**Its value is public API surface**, and for a reason worth naming: changing it orphans every tree an earlier version
wrote, and nothing will ever prune those files again. It is
[named in the freeze list a `1.0` owes its consumers](../../project/roadmap.md#before-10-freeze-what-a-major-would-cost)
for that reason, alongside the generated tree's layout.

## The split is what makes a contract change loud

The mechanism is ordinary PHP, and that is the point: **generated abstract classes and interfaces, extended by
human-written concrete classes.**

Say an operation gains a required parameter. The build rewrites the generated abstract, whose method signature changes.
Every concrete subclass a developer wrote now fails to satisfy its parent, and PHP — plus PHPStan at
[level 8](../../project/stack.md) — says so immediately, by name, before anything runs.

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
  [the doctor's](../doctor.md) hard checks does not reach the generator — half-generated output from a broken contract
  is worse than no output. The comparison sits before generation for the same reason: nothing is written until it is
  known to be allowed. See [the baseline](../lifecycle.md#unstable-by-default-and-what-stable-costs-us) for what
  "previously committed" means and why it depends on git history rather than a file the build writes.
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

### A specification is data, and generated code is code

**Shipped, and it was found by a review rather than by design.**

**Every value the build takes from the document crosses a boundary**, and that boundary is where injection lives. A path
template, an `operationId`, an `x-sunset`: all of them are free text as far as OpenAPI is concerned, and all of them end
up inside PHP the application loads. Two rules, because there are two kinds of destination:

- **Into a string literal, always through `var_export()`.** OpenAPI puts almost no constraint on a literal path segment,
  so `/users/o'brien` is a valid contract — and a hand-quoted literal built from it is PHP that does not parse. A
  trailing backslash breaks it a character later, by escaping the closing quote.
- **Into a comment, always neutralized first.** A value that closes a block comment does not merely break the file, and
  this is the part worth reading twice: the docblock ends early, whatever follows becomes a statement, and the
  docblock's own closing delimiter reopens and closes a comment around the rest. The file **parses, loads and
  executes**. Verified rather than argued: an `x-sunset` carrying that sequence produced a controller that ran code when
  autoloaded.

**The severity comes from where the output lands.** `routes.php` is loaded at boot, so a broken one takes down every
request _and_ every Artisan command, including the `spec:build` that would repair it — the only way out is deleting the
tree by hand. And a specification is exactly the document [nobody reviews like code](../remote-references.md): it can
arrive from another team, a vendor, or a generator.

**It contradicted this document's own invariant, which is the part to learn from.** The build promises that a contract
it cannot serve leaves the tree untouched rather than half generated. Here the contract was not refused: it was
accepted, and the output lied. **Escaping rather than refusing is nonetheless the right answer**, because an apostrophe
in a path is something this package _can_ honor, and [`Rejected`](../openapi-support.md#support-levels) is reserved for
what it cannot.

**And the guard already existed.** A test runs `php -l` over everything emitted; what was missing was a contract written
to attack it. That is the general lesson rather than a detail of this bug: a test covers the inputs somebody thought to
write down, so the fixture is now adversarial by design and every new emitted construct earns a hostile case in it.

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
the rule is doing work rather than rationalizing: the
[sanitized public specification](./publishing.md#where-the-public-copy-goes) is configured as a **disk**, because that
document is served, and whether it is served from local storage, S3 or a CDN is exactly the kind of thing a deployment
decides. One rule, two answers, no inconsistency.

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

Fetching therefore has one entry point in `build`: `--update-refs`, matching the install/update vocabulary the
[dependency framing](../remote-references.md#borrowing-the-dependency-manager-shape) already borrows. One flag for both
cases — adding a reference that is missing, and refreshing one already vendored — rather than two: simpler, and the
consequence either way is the same command to run again.

None of which should make designing an API tedious. That is what [watch mode](./scaffolding.md#watching-specwatch) is
for, and it is a different command precisely so that `build` can stay this strict.

### Which generated code is committed

**Decision: the package does not decide. `.gitignore` does.**

The build writes files; git decides which are tracked. That is already every consumer's mechanism for "I do not want
this in my repository", it needs no config key, no documentation of its own, and no opinion from us. Adding a config
option here would be inventing a second, worse `.gitignore`.

**One exception, and it is not optional: the vendored references must be committed.** There is
[no lock file](../remote-references.md#no-lock-file-git-is-the-lock) — the committed copies _are_ the lock. Ignoring
that directory does not save you noise, it removes the only mechanism that makes a build reproducible and an old release
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
`from()`. [DTOs are now `final readonly`](./response-dtos.md#response-dtos) and their customization lives in a factory
instead, because a value object mirroring the contract has no behavior of its own to extend. The two layers still
describe the controller seam; they no longer describe DTOs. Said plainly so that a reader coming from the old version
reads a changed position rather than a contradiction.

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
- **Widening later is a minor release, narrowing is a major one** — the same reasoning
  [`stack.md`](../../project/stack.md) applies to version support. If per-kind overrides turn out to be wanted, they can
  be added without breaking anyone; starting with them and removing them cannot.

Two details that will otherwise be discovered the hard way:

- **PSR-4 requires the directory segment and the namespace segment to match, including case.** A standard Laravel
  application maps `App\` to `app/`, so `app/http/generated` autoloads as `App\http\generated` — legal PHP, and an
  immediate source of confusion. Every segment is capitalised in the default for that reason.
- **Path and namespace are two settings, not one.** Deriving one from the other means guessing at the consumer's
  autoload map. Both are configured, and the doctor checks they agree with what `composer` actually autoloads — a
  mismatch there produces class-not-found errors far from their cause.

The config key names and the default are public API surface under [rule 4](../openapi-support.md#the-four-rules).

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
[order](../openapi-support.md#route-order-the-spec-files-order-is-the-route-order).

**A contract with nothing to route still gets the file, and the file says so.** Zero operations is a supported outcome
rather than an error — a 3.1 document may legally carry only `webhooks`, or only `components` — and skipping the write
would leave the previous build's routes registered, which is drift the runtime would go on serving. So the file is
written with no registration in it, its findings say why rather than reporting a count of zero, and it carries no
[reference comment](./generated-file-anatomy.md#a-reference-to-generated-code-says-what-to-do-when-it-goes-missing),
because it imports no generated class to explain. That last part is not a detail: a note about generated controllers,
sitting above imports holding none, is the file telling a reader something untrue.

**Decision: a missing file is silence, not an exception.** The reasoning is structural rather than lenient:

- **`spec:build` is a command of this package.** A provider that refused to boot without a generated tree would make the
  application unbootable exactly when the command that writes one needs to run. A fresh clone could never produce its
  own routes, which is a deadlock rather than a strict default.
- **It is a legitimate state,** because [`.gitignore` decides](#which-generated-code-is-committed) what a project
  commits, and the [two layers](#two-layers) already accept that a fresh clone does not run until the build has.
- **Reporting it is [the doctor](../doctor.md#what-it-checks)'s job**, where it is caught before a deploy rather than
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
  what the keys are called is not. Public API surface under [rule 4](../openapi-support.md#the-four-rules).
- Which [per-type flags](./scaffolding.md#per-type-flags-belong-here) `spec:make` accepts.
- Whether the second of the [two layers](#two-layers) is an abstract class or a trait.
- Whether fetching a _missing_ reference and refreshing a _stale_ one share one flag or take two.
- What [watch](./scaffolding.md#watching-specwatch) takes as parameters — in particular how its rebuild cadence is
  expressed, and how the mode announces itself while it is running.
- Whether `spatie/laravel-data` becomes a dependency or only an influence.
- The [factory override scan](./response-dtos.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares):
  the config key's name, whether it recurses by default, the exact mechanism for finding the `extends` relationship, and
  the name of the exception thrown when two classes claim one factory.
- **Whether a generated file records the package version that emitted it.** Distinct from
  [the time, which is refused](./generated-file-anatomy.md#what-a-generated-file-deliberately-does-not-carry-the-time),
  and it is the difference that makes it worth considering: a version changes only when the emitter might genuinely
  produce something else, so stamping it costs a rewrite exactly when a rewrite is warranted rather than on every run.
  What it would buy is a reader — or a support conversation — being able to tell that a file came from an older emitter
  than the one installed.

    Not before the first tag, because there is no version to record until the package is published, and the shape is
    worth settling near the [name freeze](../../project/roadmap.md#before-10-freeze-what-a-major-would-cost): once a
    header line is there, tooling reads it, and its format is then as much public API as a config key. The costs to
    weigh when it is decided: every upgrade rewrites the whole tree, which is loud for a consumer who tracks it, and a
    `git diff --exit-code` gate would fail across an upgrade for a reason that is correct but needs explaining.

- **Sequencing:** routes and abstract controllers are the Phase 1 target. Response DTOs and generated validation are
  Phase 2 — the same build command doing more, not a new one. See the [Roadmap](../../project/roadmap.md).

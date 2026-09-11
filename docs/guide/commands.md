---
title: Commands
audience: Users
covers: >
    The three Artisan commands this package ships: what each one writes and what it refuses to write, every argument and
    flag with the behavior behind it, how all three resolve which specification to read, what each exit code means, and
    what a command does differently when no terminal is attached. The reasoning behind each command's design lives in
    the guide that owns it.
read_before: Running any command of this package, or wiring one into a script or a pipeline.
tags: [commands, code-generation, workflow, openapi, ci]
---

# Commands

> **In brief**
>
> - `spec:build` writes the generated tree, `spec:make` writes the one class you own, and `spec:doctor` writes nothing
>   at all.
> - All three read the same document and find it the same way: `--spec` when you pass it, the configured path otherwise.
> - `spec:build` and `spec:make` exit `0` or `1`. The doctor adds `2`, which reports a gap in the package rather than a
>   fault in your document.
> - With nobody at the keyboard, nothing gets created: a bulk `spec:make` lists the files it would write and stops
>   there, and `--yes` is how you mean it.
> - **Not built yet:** `spec:watch`, and the doctor's `--check=` flag. Everything else on this page ships today.

Everything this package does from the command line, in one place. Each entry states what the command writes, what it
refuses to write, and what every flag does; the reasoning behind a behavior lives in the guide named beside it, which is
where to go when the question is why rather than how.

## Three commands ship today

What each one writes, and whether it can reach the network:

| Command       | Writes                                             | Reads the network          |
| ------------- | -------------------------------------------------- | -------------------------- |
| `spec:build`  | The [generated tree](./glossary.md#generated-tree) | Only under `--update-refs` |
| `spec:make`   | The controllers you own, never a generated one     | Never                      |
| `spec:doctor` | Nothing                                            | Never                      |

**Only `spec:build` writes inside the generated tree, and only `spec:make` writes a file you own.** Neither ever does
the other's job, which is [the invariant](./code-generation/index.md#a-build-never-destroys-your-work) that makes the
build safe to re-run at any moment.

**A fourth is designed and not built.** [`spec:watch`](./code-generation/scaffolding.md#watching-specwatch) owns the
development loop, and it is a command of its own rather than a flag on the build for a reason that page argues. Until it
exists, three is the whole set.

## `spec:build` writes the generated tree

```bash
php artisan spec:build
```

Reads the contract, plans every file in memory, and writes. Run it after any change to the specification.

| Flag            | What it does                                                   |
| --------------- | -------------------------------------------------------------- |
| `--spec=`       | Read this specification instead of the configured one.         |
| `--update-refs` | Fetch and vendor any remote reference the specification names. |

**`--update-refs` is the only thing in this package that reaches the network**, and it obeys the allowlist, which
[ships empty](./remote-references.md#the-allowlist-ships-empty). Every other run reads the working tree and nothing
else. Leave the flag off in CI: a pipeline that fetches is a pipeline whose result depends on somebody else's server.

What it prints when it finishes:

```text
INFO  12 operation(s) built. 4 file(s) written, 8 unchanged, 1 pruned.

WARN  3 of 12 operation(s) have no implementation and answer 501.

  Users (12)      php artisan spec:make --tag=Users
  untagged (3)    php artisan spec:make showLegacyReport
```

The warning is the point rather than a caveat: an operation nothing implements answers `501`, and a contract full of
them should not read as a finished application. The commands under it are
[summarized by tag](./code-generation/scaffolding.md#the-build-names-the-command) so that a two-hundred-operation
contract answers with a list rather than a wall.

Why it plans everything before writing anything, and why it never scaffolds:
[`code-generation/index.md`](./code-generation/index.md#the-build-command-specbuild).

## `spec:make` writes one class you own

```bash
php artisan spec:make listUsers
php artisan spec:make "get /users/{id}"
php artisan spec:make --tag=Users
php artisan spec:make --all
```

**Quote the method-and-path form.** A path carries `{`, `}` and `/`, and an unquoted argument is the shell's to
interpret before Artisan ever sees it.

| Argument or flag | What it does                                                  |
| ---------------- | ------------------------------------------------------------- |
| `operation`      | One operation, by `operationId` or by method and path.        |
| `--tag=`         | Every operation the document tags with this name.             |
| `--all`          | Every operation the document describes.                       |
| `--yes`          | Take the proposed `x-controller` and skip every confirmation. |
| `--spec=`        | Read this specification instead of the configured one.        |

**Name exactly one of the three forms.** Two together is refused rather than resolved by precedence, which is what keeps
a mistyped invocation from scaffolding a whole contract.

**`--yes` answers questions; it does not overwrite.** A class that already exists is reported and left alone, whatever
flags you passed. The two questions it answers are the `x-controller` proposal and the bulk forms' confirmation. Why it
is not spelled `--force`: [`controllers.md`](./controllers.md#specmake-is-the-only-way-in).

**A run that created something then runs `spec:build`, and exits with the build's code.** A scaffold on its own connects
nothing: the class it just wrote extends a generated parent whose name comes from the `x-controller` the build has not
read yet, so without the build the new file does not even load. A bulk run you decline ends there instead, with no files
and no build.

Where the class goes, what it extends, and why scaffolding is a command rather than a flag on the build:
[`code-generation/scaffolding.md`](./code-generation/scaffolding.md#scaffolding-is-specmake-not-a-build-step).

## `spec:doctor` reports and writes nothing

```bash
php artisan spec:doctor
php artisan spec:doctor --json
```

| Flag      | What it does                                                  |
| --------- | ------------------------------------------------------------- |
| `--spec=` | Read this specification instead of the configured one.        |
| `--json`  | Machine-readable findings, for CI annotation and for tooling. |

**Read-only, always.** No cache, no database, no network, and it never touches the specification or the generated tree.
It plans against both exactly as the build does before the build writes anything.

**`--json` emits one of two shapes, and a consumer needs to tell them apart.** A run that produced a report emits the
full object, with a `summary` key. A refusal it could not proceed past at all, an unset `spec.path` or an unusable
setting, emits `{"error": "…"}` and nothing else. Branch on the presence of `summary`, never on the exit code, because a
report with every section empty would claim the document was read and found clean.

**Put `git` on the `PATH` before wiring this into a slim CI image.** Without it the installation check reports "cannot
be determined" rather than passing, so the report comes back one check short.
[Why it asks git](./doctor.md#running-it-in-ci).

What it checks, what each finding class means, and how to read a zero exit code: [`doctor.md`](./doctor.md).

## Every command resolves one specification

All three find the document the same way, because two commands disagreeing about which file the project has is a defect
nobody would suspect until the class names stopped matching:

1. `--spec=`, when you pass it.
2. `lara-spec-first.spec.path`, otherwise.

**A relative path is resolved against the application root, not your working directory.** An absolute path is taken as
it is, on every platform: `C:\specs\api.yaml` is absolute too.

When the file is not there, every command says the same three things, which is the shape of every refusal this package
raises:

```text
ERROR  No specification at /app/openapi.yaml. Set `lara-spec-first.spec.path` in
       config/lara-spec-first.php, or pass --spec.
```

**One root document, not a list.** Multi-file contracts are written as local `$ref`s from the root, see
[remote references](./remote-references.md#a-reference-is-a-dependency) for the ones that live elsewhere.

## Exit codes differ between commands

| Code | `spec:build` and `spec:make` | `spec:doctor`                                               |
| ---- | ---------------------------- | ----------------------------------------------------------- |
| `0`  | It ran to the end.           | Nothing gating was found.                                   |
| `1`  | It refused, and said why.    | It refused, or your document is broken.                     |
| `2`  | Not used.                    | The document is fine; this package cannot honor part of it. |

**`1` means it refused, and a broken document is one reason among several.** An unset `spec.path` or an unusable setting
is the other, on every command: the doctor emits `{"error": "…"}` under `--json` for that branch and exits `1` without
ever having read a document.

**`0` means it ran to the end, not that it created anything.** A bulk `spec:make` you decline, which is every `--tag=`
or `--all` run under `--no-interaction` without `--yes`, exits `0` having written no files. A pipeline step gating on
the exit code alone reads success from it.

**Decide what `2` should do in your pipeline before you wire one up.** `spec:doctor || exit 1` treats it as a failure,
which is a policy choice rather than the obvious reading of a non-zero code.
[The doctor's CI section](./doctor.md#running-it-in-ci) carries the reasoning and the recipe.

**`spec:build` and `spec:make` stop at the first fault and count the rest.** You get one message, named at its position
in the document, plus how many other faults that read found. `spec:doctor` is the command that reports all of them in
one pass.

## A script gets the cautious answer

**Under `--no-interaction`, or with no terminal attached, every prompt is answered with the safe branch rather than the
convenient one.** Artisan answers a prompt with its default when nobody is at the keyboard, so the defaults are chosen
for what a script should get:

- **A bulk `spec:make` creates nothing.** The confirmation defaults to no, so `--tag=` or `--all` in a pipeline lists
  what it would create and stops there. Pass `--yes` to mean it.
- **A missing `x-controller` is printed, not inserted.** The command shows the exact row and the line it belongs on,
  then stops, because editing your specification is not something a script should do on its own. `--yes` is that
  agreement given up front, so it does insert the row even under `--no-interaction`.
- **Nothing you own is ever overwritten**, with or without a terminal. That one does not depend on how the command was
  invoked.

## Designed and not built

Both of these are settled enough to plan around and neither exists. They are here because a reference that only lists
what shipped leaves you to discover the shape of the rest from a roadmap, and because knowing a flag is coming is what
stops you building your own version of it.

**Nothing below runs today.** Where the design leaves something open, this says so rather than picking an answer the
code will contradict.

### `spec:watch` rebuilds while you edit

```bash
php artisan spec:watch
```

Phase 2. A long-running process that rebuilds on change, for somebody actively shaping a contract rather than consuming
a finished one.

Three things it gets that `spec:build` does not, two of them network permissions the build deliberately refuses. That is
the whole reason it is a command of its own rather than a flag:

| It will                                         | Where `spec:build` stands today                          |
| ----------------------------------------------- | -------------------------------------------------------- |
| Fetch new references as they appear in the spec | Fetches only under `--update-refs`, and never on its own |
| Refresh every reference on a cadence            | Never refreshes what is already vendored                 |
| Rebuild on change                               | Runs once, when you run it                               |

**Its one hard rule is that watched output equals built output.** It adds triggers, fetching and diagnostics; it does
not add, remove or reshape a single generated line. So nothing in
[`spec:build`'s section above](#specbuild-writes-the-generated-tree) will read differently once this ships.

**It will announce itself, continuously, while it runs.** A process quietly fetching remote documents into your working
tree is the failure this design exists to avoid, so saying so out loud is part of the design rather than a nicety.

**Its flags are not settled**, including whether the cadence is named on the command line or inferred. Nothing is
promised here that the command would then have to break. Why it is a process rather than a config key, and why staging
and CI build rather than watch: [`scaffolding.md`](./code-generation/scaffolding.md#watching-specwatch).

### `--check=` narrows a doctor run

No phase committed. One command holding every check is one command you will eventually want to run part of:

| Flag              | Purpose                                                           |
| ----------------- | ----------------------------------------------------------------- |
| `--check=syntax`  | Document validity only: is this valid OpenAPI.                    |
| `--check=honored` | Support findings only: what this package will and will not honor. |

**A filtered run's exit code will cover only what it ran**, and the report already names its skipped checks on every
run, so a green exit under a filter is never mistaken for a full pass. Why those two values do not partition the
doctor's sections, and what is still open about them: [`doctor.md`](./doctor.md#flags-narrow-what-runs).

## What this document does not cover

- **Why any of it behaves this way.** Each section links the guide that owns the reasoning, and those guides are where a
  disagreement with a decision belongs.
- **Configuration.** `config/lara-spec-first.php` documents every key in the file itself, including which ones are inert
  and the phase that makes them live.
- **Anything designed but unnamed.** [The section above](#designed-and-not-built) carries `spec:watch` and the doctor's
  `--check=` because both have a settled shape. What has neither a shape nor a phase lives in
  [the roadmap](../project/roadmap.md), not here.

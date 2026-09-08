---
title: Agent Guidance
audience: AI coding agents
covers: >
    The three-places rule (code, docs, tests) and the Workbench demonstration that follows it, day-to-day working rules
    for agents, the automated testing requirement, and code review priorities.
read_before: Making any change to this repository.
tags: [agents, workflow, testing, code-review, conventions, onboarding]
---

# AGENTS.md

> **TL;DR**
>
> - Every change lands in three places at once: code, documentation, and tests. A partial change is an unfinished one.
> - When a change can be shown working in the Workbench, showing it is part of finishing it.
> - The OpenAPI contract is the source of truth. Anything that makes PHP authoritative over it is going the wrong way.
> - Review in priority order, and the three places is what you check before reading a line of logic.
> - Verify with `just check`: Pint, then Larastan, then Pest.

Guidance for AI coding agents working on `lara-spec-first`.

This package is **Spec-First**: the OpenAPI contract is the source of truth, and PHP follows from it. When a change
would make the code authoritative over the spec, it is going the wrong way.

The project is in **early bootstrap** (Phase 1 of the [Roadmap](./docs/project/roadmap.md)). It reads a specification
file, refuses what it cannot serve, and generates the PHP that serves it: `spec:build` emits the routes and one
controller per operation, and the provider loads them at boot without opening a specification. `x-controller` is read,
so an operation that declares one gets a parent it may extend and a route that points at the child once that class
exists; an operation that declares none stays `final`. `spec:make` scaffolds that child on request — one operation, a
`--tag` or `--all` — offers to write `x-controller` into the specification when it is missing, and builds afterwards;
the build itself never scaffolds, it names the invocation. `spec:doctor` reports what the package will honor, what it
will not, and the routing table that results, read-only always and asserted to be. There is no response DTO and no
generated validation. The roadmap's [state section](./docs/project/roadmap.md#where-the-code-is-today) is the
authoritative list, checked boxes meaning behavior with tests behind it.

Verify your work with `just check` (or `composer check`) — Pint, Larastan, then Pest.

**Read [`docs/project/stack.md`](./docs/project/stack.md) before touching dependencies, version constraints, or CI
config.** It holds every technology choice, its status, and the reasoning behind it.

## Every change lands in three places

Code, documentation, and tests move together. A change is not finished when the code works — it is finished when all
three are updated, in the same commit. **And when a change can be shown working in the Workbench, that is a fourth
place** — see [below](#and-the-workbench-when-there-is-something-to-show).

| Place             | What it answers                       | Rule                                                                                                                                   |
| ----------------- | ------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| **Code**          | What the package does.                | The behavior itself.                                                                                                                   |
| **Documentation** | What it is _supposed_ to do, and why. | Docs follow the code. Never let the two contradict — see [`docs/contributing/documentation.md`](./docs/contributing/documentation.md). |
| **Tests**         | Proof that it actually does it.       | Tests follow the code. New behavior means new tests — see [below](#automated-tests-are-required).                                      |

Yes, this is three times the surface area for a single behavior change. That cost is deliberate, and it is small in
practice: writing tests and documentation is exactly the kind of work an agent does quickly and well. What it buys is
worth far more than the keystrokes:

- **Guard rails.** Tests catch the regression a reviewer would have skimmed past. Docs catch the design drift a test
  cannot see. Neither is redundant with the other.
- **Onboarding.** The next agent or contributor learns intent from the docs and behavior from the tests, without reading
  the whole codebase first. In a repository worked on largely by AI, this is the memory — nothing else carries context
  between sessions.
- **Regression safety.** Every behavior that is documented and tested is a behavior that cannot quietly disappear in a
  later refactor.

Two rules keep this from eroding:

- **Never trade one place for the others.** Do not ship code with "docs to follow" or "tests to follow". A partial
  change is an unfinished change, not a fast one.
- **If a change genuinely needs no doc or test update, say so and say why.** A pure rename with no behavioral effect is
  a fair exemption. Silence is not — an unexplained gap reads as an oversight.

### And the Workbench, when there is something to show

`workbench/` is a real Laravel application with this package installed, served by `composer serve`. **When a change can
be demonstrated there, demonstrating it is part of finishing the change** — not a nice-to-have, and not something to
leave for later.

The reason is that it is the closest thing this repository has to end-to-end. A unit test proves a class behaves; a
feature test proves the package behaves inside a booted framework; the Workbench is the only place where a contract
becomes a route that a browser actually reaches, through the same Composer autoloader, the same service provider
discovery and the same request lifecycle a consumer will have. **Several defects in this package have only ever been
visible there** — a docblock a consumer's formatter would rewrite, a generated tree landing where nothing is committed,
a path that resolves differently than it reads.

Concretely, for a change that adds or alters behavior a consumer can observe:

- **Put whatever the feature needs into `workbench/`.** Its _inputs_ are committed and its _outputs_ are not, the same
  split any project makes: `workbench/app/Http/Generated` is gitignored and `composer build` regenerates it. The
  contract lives in
  [`workbench/openapi.yaml`](https://github.com/Gcob/lara-spec-first/blob/main/workbench/openapi.yaml), and it is
  written to be read: every operation in it exists to make one behaviour visible in the generated output rather than
  only in an assertion.
- **Run it, and look at what came out.** Not "the tests pass" — open the generated files and read them, hit the routes
  and read the responses. A test asserts what you thought to assert; the output shows what you did not.
- **Say what you ran and what you saw.** A claim that something works end to end is only worth the command behind it.

Two traps worth knowing before you go in:

- **`base_path()` is not `workbench/`.** With `laravel: '@testbench'`, the application root is the Testbench skeleton
  under `vendor/`, so a default relative path resolves somewhere nothing is committed and `composer clear` wipes it.
  [`workbench/config/lara-spec-first.php`](https://github.com/Gcob/lara-spec-first/blob/main/workbench/config/lara-spec-first.php)
  is where that is corrected — an ordinary config file, computing its paths from `__DIR__`, loaded because
  `workbench.discovers.config` says so in `testbench.yaml`. It names only the keys the Workbench has to differ on: it is
  also read by the fresh application `php artisan route:cache` boots, so anything it changes changes what the package's
  own tests measure.
- **The Workbench is not a substitute for a test.** What you learn there earns a test; it does not replace one. A
  behavior only the Workbench covers is a behavior nothing will catch when it regresses.

## Working notes for agents

- **Do not invent stack decisions.** If a row in [`docs/project/stack.md`](./docs/project/stack.md) says `Undecided` or
  `Planned`, surface the choice to the user rather than silently picking one and writing it into config.
- **Docker is a convenience, not the source of truth.** Any command runnable via Docker must be runnable natively
  through the same Composer script.
- **Public API is expensive to change.** Once the package is published, class names, config keys, and Artisan command
  signatures become a compatibility contract. Flag such changes explicitly.
- **Never format by hand — run the tool.** Do not rewrap prose, realign table columns, or count line widths yourself: it
  is slow, and the next command overwrites it anyway. Run `just format-md` after editing Markdown and `just format`
  after editing PHP. The settings live in `.editorconfig`, `.prettierrc.json` and `pint.json`; the recipes are in the
  `justfile`. Same for reviews: formatting is not a finding.
- **Let the docs build find your dead links.** Moving or renaming a document breaks every link into it.
  `just docs-build` fails on the first one and names the file — faster and more complete than grepping for the old path.
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/)** — see `CONTRIBUTING.md`.
- **No emojis in documentation.**

## Automated tests are required

Tests follow the code exactly as documentation does — they are the third of the
[three places](#every-change-lands-in-three-places) every change must land.

A change that alters behavior and ships without tests is not complete, and "too small to test" is not an accepted reason
— small changes are precisely the ones that regress unnoticed. In review, check the diff against the test suite the same
way you check it against the docs: behavior with no test covering it is a finding.

| Level       | Scope                                                                | Notes                                                                                                                                                                                                    |
| ----------- | -------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unit        | A single class in isolation: parsing, name resolution, path mapping. | Fast, no framework boot. Should be the bulk of the suite.                                                                                                                                                |
| Feature     | The package running inside a real Laravel application.               | Booted through the package test harness. Covers routing, controllers, mocks.                                                                                                                             |
| Contract    | Live responses validated against the OpenAPI spec.                   | The signature test type of a Spec-First package. Guards the core promise.                                                                                                                                |
| Regression  | A test reproducing a reported bug.                                   | Must fail before the fix and pass after it.                                                                                                                                                              |
| Conformance | The reading engine against a partitioned input space.                | `tests/Conformance/`. Organized by equivalence class rather than by example, so coverage can be argued. Every parser defect found earns a permanent case — see the [Roadmap](./docs/project/roadmap.md). |

Expectations:

- **Cover both OpenAPI versions.** Keep fixtures for 3.0.x and 3.1.x, since supporting both is a stated guarantee of the
  package.
- **Test the failure paths.** Invalid specs, unresolved `$ref`, missing controllers, responses that do not match the
  contract. A parser is judged on what it correctly rejects.
- **Never weaken a test to make it pass.** Do not delete assertions, skip cases, or loosen a matcher to turn CI green.
  If a test is genuinely wrong, say so and explain why before changing it.
- **Tests run identically in Docker and natively**, behind the same Composer script.

## Code review

Review in this order. The list is a priority ranking, not a checklist to run in parallel — an incomplete change is not
worth reviewing for style.

1. **The three places — this is the first thing you check, before reading a line of logic.** Did the change land in
   code, documentation, _and_ tests? And where the change is something a consumer could observe, was it
   [shown working in the Workbench](#and-the-workbench-when-there-is-something-to-show)? A behavior change missing its
   docs or its tests is an **incomplete change**, and you report it as such. Do not treat it as a minor follow-up, do
   not offer to "add them later", and do not approve the change on the grounds that the code itself is correct. This is
   the highest-severity category of finding in this repository.
2. **Direction of truth.** Does the change keep the OpenAPI spec authoritative over the code? Anything that makes PHP
   the source of truth is a design defect, however well written.
3. **Correctness.** Logic, edge cases, failure paths, `$ref` resolution, behavior across OpenAPI 3.0 and 3.1.
4. **Stack compliance.** No syntax newer than PHP 8.3 in `src/`. No dependency or version constraint that contradicts
   [`docs/project/stack.md`](./docs/project/stack.md), and no choice invented for a row still marked `Planned` or
   `Undecided`.
5. **Compatibility surface.** Class names, config keys, and Artisan command signatures are a public contract once
   published. Flag anything that would force a major version bump.
6. **Documentation placement.** Does the new prose live in the file that owns the topic, or was it appended somewhere
   convenient and duplicated? See [one topic, one file](./docs/contributing/documentation.md#one-topic-one-file).
7. **Reuse and simplification.** Last, and only once the above are clean.

How to report:

- **Name the file and the line.** "The docs should probably be updated" is not a finding; "`README.md:34` states PHP 8.2
  but `composer.json:12` now requires `^8.3`" is.
- **Say which side is wrong.** A doc/code contradiction has a correct resolution — work out which one it is rather than
  reporting the mismatch and leaving it to the author.
- **Never propose weakening a test** to resolve a failure. If a test is genuinely wrong, argue why.
- **An explained exemption is acceptable.** If the author states that a change needs no doc or test update and the
  reason holds, accept it. An unexplained gap is a finding.

## Where to look

Two documents govern most of what you will need. Read the one that owns your subject rather than guessing:

- [`docs/contributing/documentation.md`](./docs/contributing/documentation.md) — how documentation is written and
  organized, the front matter schema, the tag vocabulary, and the **inventory of every document in the repository **.
  Start there when you need to find something.
- [`docs/project/stack.md`](./docs/project/stack.md) — every technology choice, its status, and its reasoning.
- [`docs/guide/openapi-support.md`](./docs/guide/openapi-support.md) — what the package honors of the specification and
  what it does not, and why. Read it before writing anything that reads a spec.
- [`docs/guide/code-generation/index.md`](./docs/guide/code-generation/index.md) — the build, and the rule that
  generated code and human code never share a file. Read it before writing anything that emits PHP.
- [`docs/guide/glossary.md`](./docs/guide/glossary.md) — every term this set coined, in one clause each, with a link to
  the section that owns it. Read a row when a word means nothing to you; read the section it points at before you rely
  on the word.

Eight more documents own one subject each, and each says in its `read_before` when to open it:
[`docs/guide/doctor.md`](./docs/guide/doctor.md) (what the package reports),
[`docs/guide/remote-references.md`](./docs/guide/remote-references.md) (`$ref` over the network),
[`docs/guide/lifecycle.md`](./docs/guide/lifecycle.md) (how strong a promise an operation carries),
[`docs/guide/security.md`](./docs/guide/security.md) (how `security` becomes an authorization check),
[`docs/guide/drivers.md`](./docs/guide/drivers.md) (the extension mechanism for everything OpenAPI never standardized),
[`docs/guide/rate-limiting.md`](./docs/guide/rate-limiting.md) (reading a limit neither OpenAPI nor the community
standardized), [`docs/guide/pagination.md`](./docs/guide/pagination.md) (the same problem, for pages),
[`docs/guide/controllers.md`](./docs/guide/controllers.md) (what answers an operation, and how much of it is generated).

Documents are tagged in their front matter, so you can find everything touching a subject without opening files:

```bash
grep -rl 'tags:.*testing' --include='*.md' .
```

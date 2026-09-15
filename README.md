# lara-spec-first

> A Spec-First API framework and integration layer for Laravel. Define your contracts with OpenAPI, generate stubs for
> AI, mock endpoints with Faker, and bridge legacy code.

[![Tests](https://github.com/Gcob/lara-spec-first/actions/workflows/tests.yml/badge.svg)](https://github.com/Gcob/lara-spec-first/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Documentation](https://img.shields.io/badge/docs-VitePress-brightgreen.svg)](https://gcob.github.io/lara-spec-first/)

## Why `lara-spec-first`?

Start with your OpenAPI contract (YML file), and let `lara-spec-first` handle the routing, validation, and skeletal
architecture while letting your business logic live safely in standard Laravel controllers.

## Documentation

**[gcob.github.io/lara-spec-first](https://gcob.github.io/lara-spec-first/)** publishes every page under `docs/`, with
search and a sidebar. It is the same content this repository carries, so each link below works from either one.

## Key Features

- **Contract-Driven Routing:** Automatically register routes based on your OpenAPI specification.
- **Legacy Friendly:** Transitional migration path (extend generated base controllers) to adopt Spec-First route by
  route without breaking existing apps. Already Code-First? Phase 3 will bootstrap your spec from the code you already
  run. See the [Roadmap](./docs/project/roadmap.md).
- **Instant Mocks:** Fallback to automatic Faker-powered responses if the concrete implementation isn't written yet.
- **Built for AI-assisted coding, and we say it out loud:** a stated goal, not a side effect. Every generated file
  explains itself: where in the contract it came from, what the build worked out while emitting it, and an `@see` at the
  code that actually runs. Your agent shouldn't have to guess. See
  [`code-generation/generated-file-anatomy.md`](./docs/guide/code-generation/generated-file-anatomy.md#every-generated-file-explains-itself).
- **Safe to regenerate:** Generated code and your code never share a file, so the build can be re-run at any time
  without losing work, and a contract change surfaces as a static analysis error, not a production incident. See
  [`docs/guide/code-generation/index.md`](./docs/guide/code-generation/index.md).
- **Plays well with your formatter, and tell it to skip the [generated tree](./docs/guide/glossary.md#generated-tree)
  anyway.** What the build emits is already canonical under Pint's `laravel` preset, so most projects need to do
  nothing. Add `"exclude": ["app/Http/Generated"]` to your `pint.json` regardless: a formatter and a build that both
  rewrite one file undo each other forever, and no generator can be canonical under every rule set. Two lines of
  reasoning, one line of config:
  [your formatter and the build](./docs/guide/code-generation/index.md#your-formatter-fights-the-build).

---

## Installation

PHP 8.3 or newer, Laravel 12 or 13. The [full matrix](#requirements) is below.

```bash
composer require gcob/lara-spec-first
```

The service provider is discovered automatically. Publish the configuration only when you need to change something in
it, since the package merges its own defaults underneath whatever you publish:

```bash
php artisan vendor:publish --tag=lara-spec-first-config
```

Point it at your contract. The default is `openapi.yaml` at the root of your application, and
`config/lara-spec-first.php` is where you change that.

Then read your contract before generating from it:

```bash
php artisan spec:doctor   # reports what this package will and will not honor
php artisan spec:build    # writes the routes and one controller per operation
```

**That order is worth keeping on a contract this package has never read, and not because building is risky.**
`spec:build` plans every file in memory before writing any of them, so a document it refuses leaves your working tree
exactly as it was. What it will not do is tell you everything at once: it stops at the first fault and only counts the
rest, and it says nothing at all about the constructs it will simply not act on. Reporting both, in one pass, is the
doctor's entire job.

`spec:build` writes only inside `app/Http/Generated`, never outside it, so the first run cannot touch anything you
wrote. Every operation answers `501` until you implement it, and the build names the command that implements each one.

Two things to do before your second build:

1. **Ignore the generated tree, the way you ignore `vendor/`.** Add `app/Http/Generated/` to your `.gitignore`, and
   `php artisan spec:build` to your deploy. See
   [which generated code is committed](./docs/guide/code-generation/index.md#which-generated-code-is-committed).
2. **Tell your formatter to skip that tree.** Add `"exclude": ["app/Http/Generated"]` to your `pint.json`. A formatter
   and a build that both rewrite one file undo each other forever, whatever formatter you run. See
   [your formatter and the build](./docs/guide/code-generation/index.md#your-formatter-fights-the-build).

Every command, flag and exit code is in [`docs/guide/commands.md`](./docs/guide/commands.md), and what changed in each
version is in [`CHANGELOG.md`](./CHANGELOG.md).

---

## Roadmap

We are building in public. The [project board](https://github.com/users/Gcob/projects/1) is the source of truth for what
is in flight, what is done, and in which order. This section is the shape of the whole thing, and
[CONTRIBUTING.md](./CONTRIBUTING.md) is how to get involved.

**Where we are:** Phase 1 shipped as `0.1.0`. Phase 2 is in progress.

### Phase 1: the foundation

One operation in a specification becomes one route that answers, and everything the package will not honor is said out
loud before anything runs. Nothing at runtime ever opens a specification.

Shipped in `0.1.0`, and what each command does is in [`docs/guide/commands.md`](./docs/guide/commands.md).

### Phase 2: the generated pipeline

For an ordinary CRUD endpoint, the route, the form request, the controller and the DTO are all derived from the
contract, and the only thing you write is the model and the business logic it carries. Not less typing for its own sake:
less surface where the code and the contract can quietly disagree.

It is `spec:build` and `spec:doctor` doing more rather than new commands, with two exceptions that say so (`spec:watch`
and the mock server). It carries the generated request validation, the response DTOs and their factories, `x-model` and
the CRUD defaults, `security` becoming a real authorization check, the Faker mocks, pagination and rate limiting, and
the sanitized public copy of the specification.

**Four configuration blocks are inert until this phase lands**, and `config/lara-spec-first.php` says which beside each
one. Changing one has no effect today.

### Phase 3: legacy bridge and ecosystem

Turn an existing Code-First Laravel app into a Spec-First one. Use existing Code-First tooling once, as an on-ramp:
extract a spec from the code you already run, then flip the direction of truth so the spec leads from there. A one-way
door, not a permanent round trip.

### Before 1.0: freeze what a major would cost

Everything you write code against stops being ours to change: the config keys, the command signatures and flags, the
controller interface and trait names, the exception class names, the driver registration API, and the generated tree's
own layout. A `0.x` makes no compatibility promise, which is the only window in which a name can be corrected for free.

### Breaking-change enforcement

Deliberately outside any phase: a rule that fails somebody's build has to be right before it ships. Until it lands,
`x-lifecycle: stable` is a declaration the doctor reports on, not a rule that fails a build, and
[`lifecycle.md`](./docs/guide/lifecycle.md) says so in those words.

---

## Stack & Philosophy

- **Spec-First, always.** The OpenAPI contract is the source of truth, and PHP follows from it, never the other way
  around.
- **Opinionated, and we own it.** Where the specification leaves a choice open, we make one and state it rather than
  adding a config flag for every fork in the road. A stated opinion you can plan around beats a flexible behavior nobody
  can predict.
- **Parser:** `devizzent/cebe-php-openapi`, a drop-in fork of `cebe/php-openapi` that adds **OpenAPI 3.1** support
  (upstream targets 3.0.x only). Modern design tools export 3.1, so we parse 3.1.
- **Parsing is not honoring.** We read 3.0.x and 3.1.x; we honor a documented subset of what they allow, and we say so
  out loud rather than ignoring a contract in silence. What is honored, what is not, and why, lives in
  [`docs/guide/openapi-support.md`](./docs/guide/openapi-support.md).
- **Two goals, stated together: developer experience and AI-assisted coding.** They pull in the same direction more
  often than they conflict. What an agent needs is what a new teammate needs, made explicit instead of assumed. Where
  the OpenAPI ecosystem never standardized something, we would rather be flexible and pleasant than literal and rigid.
- **Testing:** Pest, with `Spectator` for contract testing.
- **AI-Assisted Development:** this repository is itself built with transparent AI workflows, a separate claim from the
  goal above, which is about your project. See [`AGENTS.md`](./AGENTS.md).

Every technology choice, its status, and the reasoning behind it live in
[`docs/project/stack.md`](./docs/project/stack.md).

## Requirements

|             | Supported     |
| ----------- | ------------- |
| **PHP**     | 8.3, 8.4, 8.5 |
| **Laravel** | 12.x, 13.x    |

## Development Environment

See [CONTRIBUTING.md](./CONTRIBUTING.md).

## License

The MIT License (MIT). Please see [License File](https://github.com/Gcob/lara-spec-first/blob/main/LICENSE) for more
information.

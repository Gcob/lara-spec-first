# lara-spec-first

> A Spec-First API framework and integration layer for Laravel. Define your contracts with OpenAPI, generate stubs for
> AI, mock endpoints instantly with Faker, and seamlessly bridge legacy code.

[![Tests](https://github.com/Gcob/lara-spec-first/actions/workflows/tests.yml/badge.svg)](https://github.com/Gcob/lara-spec-first/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Documentation](https://img.shields.io/badge/docs-VitePress-brightgreen.svg)](https://gcob.github.io/lara-spec-first/)

## Why `lara-spec-first`?

Start with your OpenAPI contract (YML file), and let `lara-spec-first` handle the routing, validation, and skeletal
architecture while letting your business logic live safely in standard Laravel controllers.

## Key Features

- **Contract-Driven Routing:** Automatically register routes based on your OpenAPI specification.
- **Legacy Friendly:** Transitional migration path (extend generated base controllers) to adopt Spec-First route by
  route without breaking existing apps. Already Code-First? Phase 3 will bootstrap your spec from the code you already
  run. See the [Roadmap](./docs/project/roadmap.md).
- **Instant Mocks:** Fallback to automatic Faker-powered responses if the concrete implementation isn't written yet.
- **Built for AI-assisted coding, and we say it out loud:** a stated goal, not a side effect. Every generated file
  explains itself — where in the contract it came from, what the build worked out while emitting it, and an `@see` at
  the code that actually runs. Your agent shouldn't have to guess. See
  [`code-generation/generated-file-anatomy.md`](./docs/guide/code-generation/generated-file-anatomy.md#every-generated-file-explains-itself).
- **Safe to regenerate:** Generated code and your code never share a file, so the build can be re-run at any time
  without losing work — and a contract change surfaces as a static analysis error, not a production incident. See
  [`docs/guide/code-generation/index.md`](./docs/guide/code-generation/index.md).
- **Plays well with your formatter, and tell it to skip the [generated tree](./docs/guide/glossary.md#generated-tree)
  anyway.** What the build emits is already canonical under Pint's `laravel` preset, so most projects need to do
  nothing. Add `"exclude": ["app/Http/Generated"]` to your `pint.json` regardless: a formatter and a build that both
  rewrite one file undo each other forever, and no generator can be canonical under every rule set. Two lines of
  reasoning, one line of config:
  [your formatter and the build](./docs/guide/code-generation/index.md#your-formatter-and-the-build-both-want-to-own-these-files).

---

## Roadmap

We are building in public! Check out our [Roadmap](./docs/project/roadmap.md) to see where the project is heading, and
[CONTRIBUTING.md](./CONTRIBUTING.md) for how to get involved.

The project is in early bootstrap (Phase 1)

---

## Stack & Philosophy

- **Spec-First, always.** The OpenAPI contract is the source of truth, and PHP follows from it — never the other way
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
  often than they conflict — what an agent needs is what a new teammate needs, made explicit instead of assumed. Where
  the OpenAPI ecosystem never standardized something, we would rather be flexible and pleasant than literal and rigid.
- **Testing:** Pest, with `Spectator` for contract testing.
- **AI-Assisted Development:** this repository is itself built with transparent AI workflows — a separate claim from the
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

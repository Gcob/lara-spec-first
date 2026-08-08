# lara-spec-first

> A Spec-First API framework and integration layer for Laravel. Define your contracts with OpenAPI, generate stubs for
> AI, mock endpoints instantly with Faker, and seamlessly bridge legacy code.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

## Why `lara-spec-first`?

Start with your OpenAPI contract (YML file), and let `lara-spec-first` handle the routing, validation, and skeletal
architecture while letting your business logic live safely in standard Laravel controllers.

## Key Features

* **Contract-Driven Routing:** Automatically register routes based on your OpenAPI specification.
* **Legacy Friendly:** Transitional migration path (extend generated base controllers) to adopt Spec-First route by
  route without breaking existing apps. Already Code-First? Phase 3 will bootstrap your spec from the code you
  already run. See the [Roadmap](./docs/ROADMAP.md).
* **Instant Mocks:** Fallback to automatic Faker-powered responses if the concrete implementation isn't written yet.
* **AI-Ready:** Designed to output clean stubs that AI coding agents can easily understand and implement.

---

## Roadmap

We are building in public! Check out our [Roadmap](./docs/ROADMAP.md) to see where the project is heading, and
[CONTRIBUTING.md](./CONTRIBUTING.md) for how to get involved.

The project is in early bootstrap (Phase 1)

---

## Stack & Philosophy

* **Spec-First, always.** The OpenAPI contract is the source of truth, and PHP follows from it — never
  the other way around.
* **Parser:** `devizzent/cebe-php-openapi`, a drop-in fork of `cebe/php-openapi` that adds **OpenAPI 3.1**
  support (upstream targets 3.0.x only). Modern design tools export 3.1, so we parse 3.1.
* **Testing:** Pest, with `Spectator` for contract testing.
* **AI-Assisted Development:** Built with transparent AI workflows. See [`AGENTS.md`](./AGENTS.md).

Every technology choice, its status, and the reasoning behind it live in
[`docs/STACK.md`](./docs/STACK.md).

## Requirements

|             | Supported     |
|-------------|---------------|
| **PHP**     | 8.3, 8.4, 8.5 |
| **Laravel** | 12.x, 13.x    |

## Development Environment

See [CONTRIBUTING.md](./CONTRIBUTING.md).

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

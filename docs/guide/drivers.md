---
title: Drivers
audience: Users
covers: >
    The extension mechanism behind every feature OpenAPI never standardized: the split between a driver that knows where
    a structure lives in the document and a configurable mapping that names its fields, why that split makes a driver
    portable between projects, how a project registers its own driver from its service provider, and which features are
    driver-based today.
read_before: >
    Writing a driver, adding a driver-based feature, or changing how a driver is registered or resolved.
tags: [drivers, openapi, decisions, scope, laravel]
---

# Drivers

Some things every real API needs are things OpenAPI never gave a vocabulary to. [Rate limits](./rate-limiting.md) and
[pagination](./pagination.md) are the two this package has met so far, and they will not be the last. This document owns
the mechanism they share, so that the next one is a driver rather than a new invention.

The problem those features have in common is not that they are hard. It is that **they are not unified** — every project
expresses them differently, and no name means the same thing across two specifications. A package that picked one
convention would be wrong for most readers; a package that tried to detect all of them would be guessing, which
[rule 2](./openapi-support.md#the-four-rules) does not allow. What is needed is neither: it is a way for a project to
say what it does, without that answer having to be written into this package.

> **Not implemented yet, and not yet slotted into a phase.** Items marked `Open` are undecided.

## A driver is structure; the mapping is names

This is the whole design, and everything good about it follows from keeping the two apart:

| Concern                                                     | Lives in    | Because                                                                                   |
| ----------------------------------------------------------- | ----------- | ----------------------------------------------------------------------------------------- |
| **Where and how the structure is declared** in the document | The driver  | It is behavior — response headers, an `x-` extension, a nested envelope — so it is code.  |
| **What this project's fields are called**                   | The mapping | It is a fact about one specification, and it changes per project, so it is configuration. |

A driver knows that a rate limit is expressed as documented response headers, or that a page's collection sits in a
nested property of the response schema. It does **not** know that _this_ project calls that header `X-RateLimit-Limit`
or that property `items`. Those names arrive from the config mapping, at the moment the driver is asked.

**The consequence is the point: a driver never hardcodes a name, so a driver is portable.** The same "documented
response headers" driver serves a project on the IETF's unprefixed names and a project still on the legacy `X-` prefix,
with no fork and no second class — the difference between them is three lines of config, not a different driver.
Structure is what is worth writing code for, because structure is what several projects genuinely share.

### The test for whether a feature needs a driver at all

Customization elsewhere in this documentation is driven by nomenclature: an `operationId`
[derives a class name](./code-generation.md#when-operationid-is-absent-derive-from-method-and-path), a `securitySchemes`
name [matches a guard](./security.md#scheme-names-are-a-naming-contract-with-your-guards), a factory override is
[found by what it `extends`](./code-generation.md#overriding-a-factory-extend-it-in-a-directory-the-project-declares).
Those work because a name means the same thing everywhere it appears.

So the test is one question: **is there a name that means the same thing across specifications?** Where there is, match
it, and do not add a driver. Where there is not, a driver plus a mapping is the answer — and the reason it is not a
cop-out is that there is genuinely nothing to match.

## Turnkey by default, yours when you need it

Two properties, and the tension between them is what this mechanism is for:

- **Built-in drivers cover the common cases**, so the ordinary project configures a driver name and a mapping and is
  done. A feature that requires writing a class before it does anything is a feature most people will skip.
- **A project can write its own**, for a convention nobody anticipated, without waiting for this package to grow support
  for it — and without patching it.

**Decision: writing a driver is an ordinary Laravel extension, registered from the consumer's own service provider.**
Not a config key naming a class to be reflected into, and not a directory this package scans: `extend()` from a provider
is the idiom Laravel already uses for a custom cache store, queue connection or filesystem disk, so it costs no new
concept and every Laravel developer already has the reflex.

```php
// In the application's own service provider.
public function boot(): void
{
    LaraSpecFirst::extendPagination('json-api', fn () => new JsonApiPaginationDriver);
}
```

That name then works in configuration exactly like a built-in one does, mapping included:

```php
'pagination' => [
    'driver' => 'json-api',
    'mapping' => [/* ... */],
],
```

**Open:** the registration API's actual shape — one `extend()` per driver-based feature as sketched above, one manager
per feature resolved from the container, or a single registry taking the feature as an argument. This is public API
surface under [rule 4](./openapi-support.md#the-four-rules) and should be decided once, for every driver-based feature
at once, rather than per feature.

### An interface for the contract, an abstract class for the boring half

**Decision: each driver-based feature publishes both.** The interface is the contract and the thing to type against. The
abstract class implements what every driver of that feature would otherwise rewrite — reading the mapping, resolving a
configured key, normalizing what comes back — so a custom driver only writes the part that is actually custom: where to
look in the document.

It is the [same split as the generated two layers](./code-generation.md#two-layers), for the same reason: the interface
is what makes a substitution safe, and the abstract class is what makes writing one cheap. A driver author may ignore
the abstract class and implement the interface directly; nothing depends on the base class being used.

## Drivers are meant to be shared

Because structure is code and names are config, **a driver is worth publishing as a package.** It carries no project's
field names, so installing someone else's JSON:API pagination driver or `Link`-header rate-limit driver is a Composer
requirement plus a config mapping — never a fork, and never a set of names to reconcile with your own.

That is a deliberate goal rather than a side effect. The set of conventions in the wild is larger than this package
should ever try to ship, and a driver ecosystem is how the long tail gets covered without the package growing a built-in
for every one of them. What this package owes that ecosystem is a stable interface and a resolution mechanism that
treats a third-party driver exactly like a built-in one — which is why the registration API above is public API surface
rather than an internal detail.

**Open:** whether the package documents a naming convention for community drivers, and whether [the doctor](./doctor.md)
reports which driver is in effect for each feature — including third-party ones — in its configuration section. Printing
the resolved driver is cheap and turns "why is nothing paginated" into a one-command answer.

## Which features are driver-based

| Feature                             | Built-in drivers       | What the structure is                                          |
| ----------------------------------- | ---------------------- | -------------------------------------------------------------- |
| [Rate limiting](./rate-limiting.md) | `headers`, `extension` | Documented response headers, or a custom `x-` extension.       |
| [Pagination](./pagination.md)       | `laravel`              | Laravel's own paginator envelope, and the query parameters in. |

Each feature's own document owns its interface, its built-in drivers and its mapping keys. This file owns only what they
have in common.

## Two rules that keep this honest

- **A driver resolves at build time, and what it resolved is baked into what the build emits.** Registering a driver in
  a service provider is how the class becomes _findable_; it is not a licence for the request path to read a
  specification, which [it never does](./code-generation.md#the-runtime-never-sees-the-spec). The build runs as an
  Artisan command, so a provider-registered driver is available to it — the ordering works out without an exception to
  that rule. Where a feature genuinely needs the driver at request time, that is stated in the feature's own document
  and is a different claim from this one.
- **A driver never invents a diagnostic of its own.** A misconfigured mapping, an unknown driver name, a driver that
  cannot find what its mapping points at: all of it reports through [the doctor](./doctor.md), in the doctor's format,
  with the [document position](./doctor.md#the-contract) like any other finding. A third-party driver that writes to the
  log instead has quietly opted its users out of rule 2.

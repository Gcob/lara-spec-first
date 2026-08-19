---
title: Rate Limiting
audience: Users
covers: >
    Why OpenAPI has no vocabulary for rate limits and the two informal conventions specifications use instead, the
    adapter interface that normalizes reading either one, the two built-in drivers, and the one config mapping this
    package asks for instead of matching by name.
read_before: >
    Implementing anything that reads or reports a rate limit, or touching the rate-limiting configuration.
tags: [openapi, rate-limiting, decisions, scope, laravel]
---

# Rate Limiting

OpenAPI has no construct for rate limits — no `security`-style object, no reserved keyword, nothing every tool agrees to
read. Two informal conventions fill the gap in the wild, and neither is universal:

- **A custom `x-` extension**, naming its own fields for the limit, the remaining count, and the reset — whatever the
  spec's author called them.
- **Documented response headers**, under `responses.<code>.headers`, using whichever names that project settled on — the
  legacy `X-RateLimit-*` prefix, the newer unprefixed `RateLimit-*` the IETF draft proposes, or something a team
  invented before either was common.

Neither convention is something this package can discover — there is no `operationId`-style name that means the same
thing everywhere, and guessing wrong here is worse than declaring nothing, per
[rule 2](./openapi-support.md#the-four-rules).

> **Not implemented yet, and not yet slotted into a phase.** Items marked `Open` are undecided.

## Decision: an adapter behind one interface

**Decision: a `RateLimitAdapterInterface`, with three methods — `getLimit()`, `getRemaining()`, and `getResetTime()`.**
Everything downstream of it — reporting, and whatever emits a response — asks these three questions and nothing else,
regardless of which convention a given project's specification actually uses.

**`getResetTime()` normalizes to one spelling, the same problem this package already solved for `x-sunset`** — several
ways to write one moment have to come out as one thing, not as a value the caller must know to interpret differently
depending on where it came from. The IETF draft's `RateLimit-Reset` is _seconds until_ reset; the legacy
`X-RateLimit-Reset` is usually an _absolute_ Unix timestamp. Two conventions describing one moment cannot leak that
difference past the adapter.

## The one place this package asks for a mapping, not a name

Everywhere else in this document set, customization is driven by nomenclature: an `operationId` derives a class name, a
`securitySchemes` name matches a guard, a factory override is found by what it `extends`. Rate limiting is the
exception, and deliberately so — **there is no name to match by**, because the community never converged on one. Asking
a project to declare, explicitly, which field or header means what is not a shortcut around designing a convention; it
is the honest response to there not being one.

## Two built-in drivers

**Decision: the driver is configured, not detected**, exactly because detection has nothing reliable to key off. Two
ship with the package:

```php
'rate_limiting' => [
    'driver' => 'headers', // or 'extension'
    'mapping' => [
        'limit' => 'RateLimit-Limit',
        'remaining' => 'RateLimit-Remaining',
        'reset' => 'RateLimit-Reset',
    ],
],
```

The same shape, pointed at extension fields instead of header names:

```php
'rate_limiting' => [
    'driver' => 'extension',
    'mapping' => [
        'limit' => 'x-ratelimit-limit',
        'remaining' => 'x-ratelimit-remaining',
        'reset' => 'x-ratelimit-reset',
    ],
],
```

**Open:** whether `driver` also accepts a fully-qualified class name implementing `RateLimitAdapterInterface` directly,
for a project whose convention is neither of the two — the same shape Laravel itself uses for a custom cache or queue
driver, and consistent with this package's own goal of DX over rigidity: two defaults should not mean two choices.

## Open: what the adapter's answer actually powers

The interface only has getters, and what reads them is not yet decided. Two candidates, and they are not the same
feature:

- **Build-time:** the `extension` driver's mapped fields carry an actual numeric threshold in the specification, and the
  build reads it to attach Laravel's own `throttle` middleware to the generated route with the right limit — the spec
  becomes the source of truth for enforcement, not only for documentation.
- **Runtime relay:** the application already enforces its own limits (Laravel's `RateLimiter`, or infrastructure in
  front of it), and this package's only job is making sure the response carries that state under whichever header names
  — or extension-shaped field — the specification promises, translating Laravel's own conventions into the project's.

These are not mutually exclusive, but which one ships first, and whether the `extension` driver is expected to carry a
real threshold at all rather than just naming fields, needs deciding before the adapter is more than an interface.

## Open questions

- The config key names — `rate_limiting`, `driver`, `mapping` — are working names, not decided. Public API surface under
  [rule 4](./openapi-support.md#the-four-rules).
- Whether [the doctor](./doctor.md) validates the mapping (a required key missing, a driver name it does not recognize)
  the same way it validates
  [the security scheme naming contract](./security.md#scheme-names-are-a-naming-contract-with-your-guards).
- Whether a document that declares a rate limit through neither convention — silence — is worth a doctor finding at all,
  given the package has no way to know a limit exists in the first place.

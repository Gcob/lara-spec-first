---
title: "ADR 0025: A request DTO sets a `date-time` to the app timezone, and `toArray()` writes that same value"
audience: Users and contributors
covers: >
    The decision that a request DTO's `from()` sets every `date-time` to the app timezone at the same instant, wherever
    it sits, that `toArray()` writes the value the property holds and never converts, what stays as it was (a `date`, a
    response DTO, `query` and path parameters), the alternatives turned down, and the breaking change for 0.2 users.
read_before: >
    Asking why a request DTO's `date-time` does not keep the client's offset, why `toArray()` writes the app's offset,
    why `from()` reads `date_default_timezone_get()` and not `config('app.timezone')`, why a value stored through a
    `datetime` cast was hours off in 0.2, or changing how an input DTO builds or writes a date.
tags: [decisions, code-generation, laravel]
---

# ADR 0025: A request DTO sets a `date-time` to the app timezone, and `toArray()` writes that same value

> **In brief**
>
> - A DTO is a type, and `toArray()` is the same data as an array, never a hidden conversion. A value is normalized when
>   the DTO is built, so the property and `toArray()` always agree.
> - A request `date-time` is set to the app timezone in `from()`, at every depth: a property, a nested object, a list or
>   a map. The instant is kept, only the timezone changes. The app timezone is `date_default_timezone_get()`.
> - `toArray()` writes the string of the value the property holds, so it is now in the app timezone, and
>   `Model::create($dto->toArray())` stores the right instant through a `datetime` cast.
> - A `date`, a response DTO, and `query` and path parameters do not change. What the client sent stays on
>   `$request->input()` and `validated()`.
> - Turned down: keeping the client's offset, converting in `toArray()`, `config('app.timezone')`, and handing out the
>   `CarbonImmutable` from `toArray()`.

**Status:** Accepted · **Card:** [#241](https://github.com/Gcob/lara-spec-first/issues/241) · **Pull request:**
[#248](https://github.com/Gcob/lara-spec-first/pull/248)

## Context

In 0.2 an input DTO read a `date-time` with `CarbonImmutable::parse($value)`, which holds the offset the client wrote,
and `toArray()` wrote it back with that offset. The two agreed, and the value was right. Passed to a model, it was not.

- **Eloquent's `datetime` cast keeps the clock digits and drops the offset.** It formats a value it is given with the
  model's date format, in the value's own timezone, and a stored date is read back in PHP's default timezone. So
  `2026-12-01T19:00:00-05:00` was stored as `2026-12-01 19:00:00` in a UTC app, five hours off, and read back as 19:00
  UTC. It is right only when the client's offset is the app's at that instant.
- **`CarbonImmutable::parse($value, $timezone)` does not help.** The second argument applies only to a string with no
  offset, and the contract requires one, so it would change nothing. `setTimezone()` is what moves the instant.
- **Laravel sets PHP's default timezone from `config('app.timezone')` at boot.** After boot the two agree. If only the
  config changes at run time, `now()` and what Eloquent reads a stored date in stay on PHP's default.

A response DTO is a different case: it is built from what the application holds, and
[ADR 0013](./0013-mirror-a-response-schema-or-leave-it-untyped.md) records that it writes a `date-time` as Laravel's
`toJSON()` does.

## Decision

**The rule.** A DTO is a type. `toArray()` is the same data as an array and never a hidden conversion: whatever a value
should become, it becomes when the DTO is built, so `$dto->starts_at` and `$dto->toArray()['starts_at']` always agree.

**A request `date-time` is set to the app timezone in `from()`.** The expression is
`CarbonImmutable::parse($value)->setTimezone(date_default_timezone_get())`, at every depth: a property, a nested object,
a list of objects, a list, a map's values and a body that is a map. A nullable value is checked for `null` first, as it
was. A list or map item leaves the first-class callable for a closure, since a callable cannot say the timezone. The
instant never changes, so no contract is loosened.

**`toArray()` writes what the property holds.** It still writes `format('Y-m-d\TH:i:s.uP')`, so it is still a string
with the fraction, and now in the app timezone: `2026-12-02T00:00:00.000000+00:00` for the value above in a UTC app. The
code that writes it does not change.

**What does not change:**

- **A `date`.** It is a calendar day with no timezone to set, and `toDateString()` keeps writing it.
- **A response DTO.** Its `from()` reads a value as it came and `toArray()` writes `toJSON()`, as ADR 0013 records.
- **`query` and path parameters.** Neither becomes a `CarbonImmutable`: a `query` parameter is read with `validated()`,
  and a path parameter is a `string` in `routeAction`. They stay the string as sent.
- **What the client sent.** It stays on `$request->input()` and `validated()`, the string as written.
- **A `date-time` with no single type, or with an `enum`.** The property is `mixed` or `string` and read as it came, so
  there is no `CarbonImmutable` to set.

**The app timezone is `date_default_timezone_get()`.** It is the timezone Eloquent reads a stored date in, and the one
`now()` uses, so the DTO and the model mean the same thing by it. It needs no booted container, so `from()` keeps
working in a plain unit test or a script, and a generated DTO still depends on nothing but `Optional`.

## Alternatives turned down

- **Keeping the client's offset.** It is what 0.2 did, and it stores a value hours off through a `datetime` cast
  whenever the client's offset is not the app's. A hidden conversion in every controller (the demo had one) is the cost
  of keeping it.
- **Converting in `toArray()`.** The property would hold one offset and the array another, so `$dto->starts_at` and
  `toArray()['starts_at']` would disagree, a conversion hidden in a method that is meant to be the same data.
- **`config('app.timezone')`.** `config()` is a Foundation helper that needs a booted container, and the package
  requires only `illuminate/support` and `illuminate/routing`. It is also not what Eloquent reads a stored date in when
  only the config changes at run time: the DTO would store clock digits that read back wrong, while PHP's default stays
  right.
- **Handing out the `CarbonImmutable` from `toArray()`.** It would put an object in a plain PHP array, which
  [ADR 0013](./0013-mirror-a-response-schema-or-leave-it-untyped.md) keeps `toArray()` from being at any depth, and
  `jsonSerialize()`, which returns `toArray()`, would write UTC with a `Z`, a conversion of its own hidden in the JSON.

## Consequences

- **This is a breaking change for 0.2 users.** The property no longer carries the client's offset, and `toArray()`
  writes the app's (`+00:00` in a UTC app). Code that read the offset from the DTO reads `$request->input()` instead.
  The CHANGELOG lists it under Changed. Before 1.0 a breaking change goes to the CHANGELOG and the README says there
  will be some.
- **`Model::create($dto->toArray())` stores the right instant.** The test runs a model with a `datetime` cast on an
  in-memory SQLite connection and reads the raw column, in a UTC app, a Toronto app, and an app whose config alone
  changed at run time.
- **A query builder write takes the property.** `insert($dto->toArray())` stores the ISO string as written on SQLite,
  and MySQL and PostgreSQL each read an offset in a datetime literal their own way (not measured). Passing the property
  makes the connection format it in its own timezone. The guide says so.
- **The hour that repeats in the fall stays Eloquent's.** In a timezone with daylight saving time, a cast stores a clock
  time and reads it back in the first hour it meets, so `2026-11-01T06:30:00Z` (01:30 EST in Toronto) is stored as
  `2026-11-01 01:30:00` and reads back as 01:30 EDT. Any value the cast stores in a non-UTC app has this, whatever the
  DTO does, and Laravel's default timezone is UTC.
- **A response DTO does not change.** ADR 0013's status does not change either: nothing is superseded.

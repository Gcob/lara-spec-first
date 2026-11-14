<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Data;

/**
 * "The client did not send this", as a type.
 *
 * A property a generated DTO may lack is typed `Optional|T`, and holds an
 * instance of this class when the key was absent. It exists so that absent is a
 * state a controller can test for, distinct from `null`, which the schema's
 * `nullable` already owns: a `PATCH` that omits `nickname` must not write `null`
 * over a stored one, and a DTO whose absent properties arrived as `null` would.
 *
 * **It carries no state and has no behavior.** The name and the `instanceof`
 * test are `spatie/laravel-data`'s, on purpose: a developer who has used that
 * package reads the generated type without looking anything up.
 *
 * **The name collides with `Illuminate\Support\Optional`**, the class behind
 * Laravel's `optional()` helper. An IDE that auto-imports the wrong one makes an
 * `instanceof Optional` false on every call with no error at run time, and
 * Larastan is what reports it. The generated DTOs import this one with an
 * explicit `use`, and the golden file pins that.
 *
 * **It is the one class of this package a generated DTO imports**, so its name
 * and namespace are public API surface from the first release that emits a DTO.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "Absent is Optional"
 */
final readonly class Optional {}

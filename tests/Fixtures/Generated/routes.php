<?php

declare(strict_types=1);

/*
 * Stands in for the *loading* surface of the file `spec:build` emits — not for its
 * bytes. `spec:build` writes that file for real now, and what it puts in it is
 * asserted against the emitter in tests/Unit/Generation/RoutesEmitterTest.php:
 * the docblock norm, and the note above the generated imports. Copying either
 * here would be a second place to keep in step, for a test that reads neither.
 *
 * The controllers it points at say the same thing about themselves: they return
 * strings, because what these tests need to observe is which route answered.
 *
 * Two properties are what the tests read from here, and both are decisions
 * documented elsewhere rather than conveniences of the fixture:
 *
 *   - every action is a [class, method] pair of plain strings, which is what
 *     keeps `php artisan route:cache` working;
 *   - `/users/me` is declared before `/users/{id}`, because the specification's
 *     order is the route order and the first match wins.
 *
 * See docs/guide/openapi-support.md — "Route order: the spec file's order is
 * the route order".
 */

use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Controllers\ShowCurrentUserController;
use Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Controllers\ShowUserController;
use Illuminate\Support\Facades\Route;

Route::get('/users/me', [ShowCurrentUserController::class, 'routeAction']);
Route::get('/users/{id}', [ShowUserController::class, 'routeAction']);

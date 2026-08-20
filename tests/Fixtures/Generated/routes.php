<?php

declare(strict_types=1);

/*
 * Stands in for the file `spec:build` will emit, so that loading it can be
 * tested before anything writes it.
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

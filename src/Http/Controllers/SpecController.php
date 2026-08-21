<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Http\Controllers;

use Illuminate\Routing\Controllers\HasMiddleware;

/**
 * The one base class every generated controller extends.
 *
 * Shipped rather than generated, and deliberately empty of per-operation
 * knowledge — it cannot have any, since it is shared by every operation in every
 * contract. Everything an operation needs is generated into its own class,
 * narrowly typed.
 *
 * **Middleware is a method rather than a second mechanism.** This implements
 * Laravel's own `HasMiddleware`, so a project needing middleware on one
 * operation overrides `middleware()` in its own subclass and has no `implements`
 * clause to remember. Two consequences of using the framework's interface as it
 * is: the method is `static`, and being static it cannot reach instance state —
 * so middleware stays declarative, which is the right constraint anyway.
 *
 * **Nothing the specification derives will ever live in `middleware()`.**
 * Anything derived from the contract goes on the route instead, and Laravel
 * combines route middleware with controller middleware rather than replacing one
 * with the other. That is what makes overriding this safe: a child cannot drop
 * its own security by forgetting `parent::middleware()`, because there is
 * nothing of ours in here to preserve.
 *
 * @see docs/guide/controllers.md — "Middleware is a method, not a separate mechanism"
 */
abstract class SpecController implements HasMiddleware
{
    /**
     * @return array<int, mixed>
     */
    public static function middleware(): array
    {
        return [];
    }
}

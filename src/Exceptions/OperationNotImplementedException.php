<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Exceptions;

use Gcob\LaraSpecFirst\Generation\ControllerEmitter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The contract describes this operation and nothing implements it yet.
 *
 * `501 Not Implemented` is the status HTTP already has for exactly this: the
 * server recognizes the request and has not implemented it. The two
 * alternatives are worse and for reasons this package has already committed to.
 * Not registering the route would make a documented endpoint answer `404`,
 * indistinguishable from a typo, so the specification would say the endpoint
 * exists and nothing anywhere would say otherwise. Pointing the route at a class
 * that does not exist would raise a class-not-found at request time, blaming the
 * consumer's application for a state the build created on purpose.
 *
 * **Thrown rather than returned**, so that `routeAction`'s return type stays the
 * operation's own type. A generated method that returned a response here would
 * have to widen that signature, and widening it back once Phase 2 emits DTOs
 * would change it under every child already written against it.
 *
 * It extends Symfony's `HttpException`, which is what makes Laravel render the
 * status without this package registering a handler or a middleware of its own —
 * and which is also what puts this exception's own message, unlike most others, in
 * front of whoever made the request: Laravel's handler treats an `HttpException`'s
 * message as safe to show, in production and in a JSON response alike.
 *
 * **DECISION: the `php artisan spec:make` invocation stays in that message, on
 * purpose, rather than moving to a log line only the team sees.** The operation's
 * identity is already the first thing the message names, so an outside caller
 * learns which endpoint is unimplemented regardless; what the invocation adds on
 * top is that this is a Laravel application built with this package, which is a
 * fact worth weighing but not one worth engineering around here — this package's
 * generated code is written for a person *and* a coding agent to read ({@see
 * ControllerEmitter}), and the same reasoning applies to the one message either of
 * them meets at request time. A project that judges its own threat model
 * differently can render `SpecException` however it prefers; this package does not
 * owe that judgment call a guess.
 *
 * @see docs/guide/code-generation/scaffolding.md — "An unimplemented operation answers 501"
 */
final class OperationNotImplementedException extends HttpException implements SpecException
{
    /**
     * @param  string  $identity  the operation's method and path, which is what
     *                            addresses it and therefore what a reader needs
     *                            to find it in the specification
     * @param  string|null  $name  how `spec:make` would be told which operation this
     *                             is: its `operationId`, or null when it has none
     */
    public static function operation(string $identity, ?string $name = null): self
    {
        return new self(501, sprintf(
            'The operation "%s" is described by the specification and has no implementation. '.
            'This response comes from the generated controller, which is doing the only honest '.
            'thing it can until something answers the operation. Run `php artisan spec:make %s` '.
            'to create the class that will.',
            $identity,
            $name ?? '"'.$identity.'"'
        ));
    }
}

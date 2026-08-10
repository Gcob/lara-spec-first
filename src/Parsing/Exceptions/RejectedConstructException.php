<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Exceptions;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use RuntimeException;

/**
 * The document is valid OpenAPI and uses something this package refuses to serve.
 *
 * A package limit, not a document fault — the distinction the doctor's report
 * is built on, and the reason the message never suggests the author made a
 * mistake. They did not: we are the ones who cannot serve all of it.
 *
 * @see docs/DOCTOR.md — "Two kinds of finding, never mixed"
 */
final class RejectedConstructException extends RuntimeException implements SpecException
{
    public static function componentPathItem(string $path, string $ref): self
    {
        return new self(sprintf(
            'The path "%s" refers to "%s", and this package cannot follow it. The OpenAPI parser does '.
            'not model `components.pathItems`, the field 3.1 added for reusable path items: it '.
            'resolves the reference to a plain value, keeps no operations, and reports nothing — the '.
            'endpoint would simply cease to exist. Refer to another path (`#/paths/~1health`) or to a '.
            'separate file instead; both resolve correctly.',
            $path,
            $ref
        ));
    }

    public static function traceOperation(string $path): self
    {
        return new self(sprintf(
            'The path "%s" declares a `trace` operation. Laravel has no TRACE verb — its router '.
            'knows GET, HEAD, POST, PUT, PATCH, DELETE and OPTIONS — so the operation cannot be '.
            'registered, and loading a contract while silently dropping one of its endpoints is not '.
            'something this package will do.',
            $path
        ));
    }
}

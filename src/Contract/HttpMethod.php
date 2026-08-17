<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * The HTTP methods an operation can be written under and this package can serve.
 *
 * `trace` is deliberately absent. OpenAPI defines it on a Path Item, but Laravel
 * has no TRACE verb, so an operation written under it cannot become a route —
 * and the contract artifact holds only what the package honors, which means a
 * `trace` operation is refused where it is read rather than carried this far.
 *
 * @see docs/guide/openapi-support.md — "`trace` cannot be routed"
 */
enum HttpMethod: string
{
    case Get = 'get';
    case Put = 'put';
    case Post = 'post';
    case Delete = 'delete';
    case Options = 'options';
    case Head = 'head';
    case Patch = 'patch';

    /**
     * The keys a Path Item may carry that are operations rather than metadata.
     *
     * @return non-empty-list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $method): string => $method->value, self::cases());
    }
}

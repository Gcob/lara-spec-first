<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * How strong a promise an operation carries.
 *
 * A binary. `deprecated` is deliberately not a value here — it is native to
 * OpenAPI, independent of this axis, and both can be true at once.
 *
 * @see docs/guide/lifecycle.md — "`deprecated` is native, and stays out of `x-lifecycle`"
 */
enum Lifecycle: string
{
    case Beta = 'beta';
    case Stable = 'stable';

    /**
     * Every value a document may write, for use in error messages.
     *
     * @return non-empty-list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $lifecycle): string => $lifecycle->value, self::cases());
    }
}

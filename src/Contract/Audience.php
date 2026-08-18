<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * Who an operation is promised to, and the discriminator that decides what the
 * other axis defaults to.
 *
 * @see docs/guide/lifecycle.md — "Two keys, one discriminator"
 */
enum Audience: string
{
    case Public = 'public';
    case Internal = 'internal';

    /**
     * What an operation carries when `x-audience` is absent.
     */
    public static function default(): self
    {
        return self::Public;
    }

    /**
     * The promise an operation of this audience carries when `x-lifecycle` is
     * absent. Null is the absence of a claim, not a prohibition.
     */
    public function defaultLifecycle(): ?Lifecycle
    {
        return match ($this) {
            self::Public => Lifecycle::Beta,
            self::Internal => null,
        };
    }

    /**
     * Every value a document may write, for use in error messages.
     *
     * @return non-empty-list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $audience): string => $audience->value, self::cases());
    }
}

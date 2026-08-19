<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * One set of security schemes an operation requires, all of them at once.
 *
 * An operation carries a list of these, and satisfying any one of them is
 * enough — OpenAPI ORs the list and ANDs what is inside each entry.
 */
final readonly class SecurityRequirement
{
    /**
     * @param  array<string, list<string>>  $schemes  scheme name to the scopes it asks
     *                                                for, ordered by name
     */
    private function __construct(public array $schemes) {}

    /**
     * @param  array<string, list<string>>  $schemes
     */
    public static function fromSchemes(array $schemes): self
    {
        // Sorted here rather than by the caller: the schemes are ANDed, so their
        // order carries no meaning and letting it vary would put noise in a diff
        // that is meant to be read.
        ksort($schemes);

        return new self($schemes);
    }
}

<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * An `enum` or a `const`, as the one rule that is not a string.
 *
 * **Emitted as `Rule::in([...])`, never as `in:a,b`.** The string form splits
 * on commas, so a value carrying one becomes two allowed values the contract
 * never named, and the array form is how Laravel itself says "exactly these".
 * A value object rather than a pre-rendered string, so the emitter decides the
 * spelling and a test can compare the values rather than parse PHP.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final readonly class InRule
{
    /**
     * @param  list<scalar>  $values  as the document writes them, `null` already
     *                                removed: nullability is the `nullable`
     *                                rule's to state, and `Rule::in` compares
     *                                strings, so a `null` member would allow
     *                                the empty string instead
     */
    public function __construct(
        public array $values,
    ) {}
}

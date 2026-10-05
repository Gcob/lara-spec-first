<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * What one operation's `rules()` returns, and what the build could not put in
 * it.
 *
 * **The two halves travel together because neither is complete alone.** A rule
 * set on its own says what is enforced and stays silent about the rest, which
 * is precisely the failure this package exists against: a constraint that maps
 * to nothing has to be reported by name, never dropped and never approximated
 * by a looser rule. So every keyword the build did not translate leaves a
 * finding here, and the generated file prints it where the reader already is.
 *
 * @see docs/guide/code-generation/request-validation.md — "Every constraint maps or reports"
 */
final readonly class RuleSet
{
    /**
     * @param  array<string, list<string>>  $rules  one entry per rule key, in the
     *                                              order the document writes the
     *                                              fields: the body's first, then
     *                                              the query parameters
     * @param  list<string>  $findings  what the build read and did not act on,
     *                                  one line each
     */
    public function __construct(
        public array $rules = [],
        public array $findings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }
}

<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * What one walk over an operation's input has produced so far.
 *
 * A rule set is built by descending nested objects and array elements, and
 * every level adds rules and findings. Threading two arrays by reference
 * through every method would say the same thing with more noise, so they
 * travel together here, for the length of one {@see RuleSetBuilder::for()}
 * call and no longer.
 *
 * @internal Not public API — the accumulator of one build of one rule set.
 */
final class RuleWalk
{
    /**
     * @var array<string, list<string|InRule>>
     */
    public array $rules = [];

    /**
     * @var list<string>
     */
    public array $findings = [];

    /**
     * @param  string  $identity  the operation's label, for a refusal's message
     */
    public function __construct(
        public readonly string $identity,
    ) {}
}

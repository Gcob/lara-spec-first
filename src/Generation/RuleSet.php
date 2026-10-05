<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\Schema;

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
     * @param  array<string, list<string|InRule>>  $rules  one entry per rule key,
     *                                                     in the order the
     *                                                     document writes the
     *                                                     fields: the body's
     *                                                     first, then the query
     *                                                     parameters
     * @param  list<string>  $findings  what the build read and did not act on,
     *                                  one line each
     * @param  list<string>|null  $closedKeys  the body's top-level property
     *                                         names when its schema forbids
     *                                         any other, and null when it
     *                                         does not. A root has no field
     *                                         for `array:` to sit on, so the
     *                                         emitter turns these into an
     *                                         `after()` check
     * @param  Schema|null  $body  the body schema the rules were built from, as
     *                             written, so the input DTO is derived from the
     *                             same statement and cannot disagree about which
     *                             media type was read
     * @param  bool  $multipart  whether `multipart/form-data` is the only media
     *                           type read, which is the one body a part can be a
     *                           file in. The DTO asks the same question the
     *                           rules did, and takes the answer from here
     */
    public function __construct(
        public array $rules = [],
        public array $findings = [],
        public ?array $closedKeys = null,
        public ?Schema $body = null,
        public bool $multipart = false,
    ) {}

    /**
     * Whether any rule is an enumeration, which is what decides whether the
     * emitted file imports `Rule`.
     */
    public function usesInRule(): bool
    {
        foreach ($this->rules as $rules) {
            foreach ($rules as $rule) {
                if ($rule instanceof InRule) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }
}

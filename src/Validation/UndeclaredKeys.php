<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Validation;

use Illuminate\Contracts\Validation\CompilableRules;
use Illuminate\Validation\Rule;

/**
 * "These rules, for every key of the map that the object does not declare."
 *
 * A generated request puts it on the wildcard of an object that writes properties and a value schema
 * (`additionalProperties: {type: integer}`): the value schema judges the keys the object does not declare, and a
 * declared key keeps exactly its own rules. A plain wildcard would judge every key at its level, the declared one
 * included, and which rules a key ends up with would depend on the order of the rule array.
 *
 * **It reads the key, never the data.** `$spared` says, for a step of the key (`meta.c` has `meta` at step 0 and `c` at
 * step 1), the names that step must not be: when the key's step is one of them the key gets no rule, and any other key
 * gets all of them. The data is never passed on. `Rule::forEach()` does the same job with a closure, but it hands every
 * key the whole gathered data to undot, which is quadratic and measured at 22 seconds for a map of 10,000 values; this
 * rule is quadratic as well, in Laravel's own expansion of a compiled rule under a wildcard, and 75 times faster there.
 *
 * **Under a wildcard it must be the key's value itself**, never an element of a list: Laravel compiles it only there,
 * and anywhere else it reads the object as a rule name and throws.
 *
 * **It is public API surface**, because a generated request imports it: the name and namespace are frozen with the rest
 * of what a generated file names, and so are the constructor and {@see self::below()}, which a generated file calls.
 *
 * @see docs/guide/code-generation/request-validation.md — "A map's values are validated one by one"
 */
final readonly class UndeclaredKeys implements CompilableRules
{
    /**
     * @param  array<int, list<string>>  $spared  the names a step of the key must not be for the rules to apply, by the
     *                                            step. Strings, since a declared `"2024"` is an integer as an array key
     *                                            and the comparison is strict
     * @param  list<mixed>  $rules  the rules an undeclared key gets
     */
    public function __construct(
        public array $spared,
        public array $rules,
    ) {}

    /**
     * The same rule for a key under an undeclared key of its own step: the guard without that step, which no longer
     * asks anything, or the plain rules when no other step is left. The generated request repeats a root map's rules
     * under the integer-like keys of the body, which are never declared ones.
     *
     * @return list<mixed>|self
     */
    public function below(int $step): array|self
    {
        $spared = array_diff_key($this->spared, [$step => true]);

        return $spared === [] ? $this->rules : new self($spared, $this->rules);
    }

    /**
     * @param  string  $attribute  the data key Laravel expanded the wildcard to
     * @param  mixed  $value
     * @param  mixed  $data
     * @param  mixed  $context
     * @return object
     */
    public function compile($attribute, $value, $data = null, $context = null)
    {
        $steps = explode('.', (string) $attribute);

        foreach ($this->spared as $step => $names) {
            if (in_array($steps[$step] ?? null, $names, true)) {
                return Rule::compile($attribute, [], []);
            }
        }

        return Rule::compile($attribute, $this->rules, []);
    }
}

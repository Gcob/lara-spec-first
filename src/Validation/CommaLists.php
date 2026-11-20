<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Validation;

/**
 * "These keys of the query string are arrays a client wrote as one comma-separated string: make them arrays."
 *
 * A generated request calls it from `prepareForValidation()` when `query.deep_object_arrays` is `comma`, so the rules,
 * `validated()` and the request a controller type-hints all see the list a client meant (`?filter[ids]=1,2` is
 * `['1', '2']`), and the array rules judge it as they judge one written with brackets.
 *
 * **A value is split only when it is a string, or empty.** An array is left exactly as sent, so a client that writes
 * brackets still passes: one rule, "a string where an array belongs is a comma list". `null` (what the empty string
 * becomes under `ConvertEmptyStringsToNull`) and `""` are the empty list, which is how a comma list writes one.
 *
 * **Nothing is trimmed, and an empty item is `null`.** OpenAPI writes no space after a comma, so `1, 2` is
 * `['1', ' 2']` and the item rules judge the space. An item that trims to the empty string (`1,,2`, `1,`, `,`) is
 * `null`, as the empty item of `?filter[ids][]=` is: Laravel skips a rule that is not implicit on a value that trims to
 * `""`, so the item rules would otherwise accept it as no value at all, looser than the contract.
 *
 * **An item cannot hold a comma.** PHP decodes `%2C` before any request sees the string, so a comma in a value and a
 * comma between values cannot be told apart. That is the convention the application declared, not something the package
 * left unchecked.
 *
 * **Each list is a key, by its steps.** `['meta', '*']` is every value of the `meta` map, and `spared` names, by step,
 * the keys a wildcard leaves alone: the names the map declares, which keep their own type. The steps are the keys of
 * the query string as PHP delivers them, never a dotted path: a name that holds a dot is one step. A list below another
 * (an array of arrays) is split first, so a string written for the outer array is never split twice.
 *
 * **It is public API surface**, because a generated request imports it: the name and namespace are frozen with the rest
 * of what a generated file names, and so is {@see self::split()}, which a generated file calls.
 *
 * @see docs/guide/code-generation/request-validation.md — "Arrays inside a deepObject"
 */
final class CommaLists
{
    /**
     * @param  array<array-key, mixed>  $query  the query string as the request holds it
     * @param  list<array{steps: list<string>, spared?: array<int, list<string>>}>  $lists  the arrays to split: the
     *                                                                                      steps of each key (`*` is
     *                                                                                      every key at its step), and
     *                                                                                      the names a wildcard step
     *                                                                                      leaves alone, by step.
     *                                                                                      Strings, since a declared
     *                                                                                      `"2024"` is an integer as an
     *                                                                                      array key
     * @return array<array-key, mixed>
     */
    public static function split(array $query, array $lists): array
    {
        // Why: a list below another is split first. An array of arrays written as one string (`filter[m]=1,2`) has no item
        // that is a list, so splitting the outer one second leaves strings its item rules refuse, where the other way
        // round would split each of them again into a list of one.
        usort($lists, static fn (array $first, array $second): int => count($second['steps']) <=> count($first['steps']));

        foreach ($lists as $list) {
            $query = self::descend($query, $list['steps'], $list['spared'] ?? [], 0);
        }

        return $query;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $steps
     * @param  array<int, list<string>>  $spared
     * @return array<array-key, mixed>
     */
    private static function descend(array $node, array $steps, array $spared, int $at): array
    {
        $last = $at === count($steps) - 1;
        $keys = $steps[$at] === '*'
            ? array_filter(array_keys($node), static fn (int|string $key): bool => ! in_array((string) $key, $spared[$at] ?? [], true))
            : (array_key_exists($steps[$at], $node) ? [$steps[$at]] : []);

        foreach ($keys as $key) {
            if ($last) {
                $node[$key] = self::list($node[$key]);
            } elseif (is_array($node[$key])) {
                $node[$key] = self::descend($node[$key], $steps, $spared, $at + 1);
            }
        }

        return $node;
    }

    private static function list(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_string($value)) {
            return $value;
        }

        return array_map(
            static fn (string $item): ?string => trim($item) === '' ? null : $item,
            explode(',', $value),
        );
    }
}

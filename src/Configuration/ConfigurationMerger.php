<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Configuration;

/**
 * Puts the package's defaults underneath whatever an application published.
 *
 * Laravel's own `mergeConfigFrom()` merges one level, which is right for a flat
 * file and wrong for a nested one: an application that publishes this package's
 * config and edits a single nested value replaces the whole sub-array, so every
 * key added to that section in a later release arrives missing. The failure is
 * silent and shows up a release late, which is why the rules below are a class
 * with its own tests rather than a helper inside the service provider.
 *
 * @internal Not public API.
 */
final readonly class ConfigurationMerger
{
    /**
     * Published values win; defaults fill in what they leave out.
     *
     * Associative arrays are descended into. **Lists are replaced wholesale**,
     * because merging a list element by element would make an entry impossible
     * to remove — and the first list this package has is `allowed_hosts`, where
     * being unable to withdraw trust is the wrong failure to have.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $published
     * @return array<string, mixed>
     */
    public static function defaultsUnder(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $default = $defaults[$key] ?? null;

            $defaults[$key] = is_array($default) && $default !== [] && ! array_is_list($default) && is_array($value)
                ? self::defaultsUnder($default, $value)
                : $value;
        }

        return $defaults;
    }
}

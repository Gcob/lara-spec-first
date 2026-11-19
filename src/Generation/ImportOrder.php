<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * The order a formatter puts a file's `use` statements in.
 *
 * **Sorted the way php-cs-fixer's `ordered_imports` sorts**, which a plain `sort()` is not: that fixer compares with
 * `\` replaced by a space and case folded, so `Gcob\LaraSpecFirst\Http` and `Gcob\LaraSpecFirstX` land on opposite
 * sides of where bytes would put them. The generated tree's own namespace is the consumer's to choose, so this is not a
 * case that can be ruled out by inspection. Written in the order a formatter would write it, a build and the consumer's
 * formatter never rewrite each other.
 *
 * @internal Not public API: shared by the controller emitter and the scaffold.
 */
final readonly class ImportOrder
{
    /**
     * @param  list<string>  $classes  fully-qualified names, with or without a leading separator
     * @return list<string> without the separator, each once
     */
    public static function sorted(array $classes): array
    {
        $imports = array_values(array_unique(array_map(
            static fn (string $class): string => ltrim($class, '\\'),
            $classes,
        )));

        usort($imports, static fn (string $first, string $second): int => strcasecmp(
            str_replace('\\', ' ', $first),
            str_replace('\\', ' ', $second),
        ));

        return $imports;
    }
}

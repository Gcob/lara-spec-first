<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

/**
 * The path from one directory to a file, the way a rewritten `$ref` has to
 * name it.
 *
 * `Generation\ProjectRelativePath` answers a different question — "relative to
 * the project root," for a generated file's own docblock. A rewritten `$ref`
 * has to be relative to the *referencing document's own directory* instead,
 * because that is what `cebe\openapi`'s `ReferenceContext` resolves a relative
 * reference against — the same rule that already lets a local, multi-file
 * specification work today.
 *
 * @internal Not public API — a detail of how a vendored reference is rewritten.
 *
 * @see docs/guide/remote-references.md — "How a vendored copy stays invisible to the parser"
 */
final readonly class RelativeFilePath
{
    public static function from(string $fromDirectory, string $toFile): string
    {
        $from = self::segments($fromDirectory);
        $to = self::segments(dirname($toFile));

        $shared = 0;

        while ($shared < count($from) && $shared < count($to) && $from[$shared] === $to[$shared]) {
            $shared++;
        }

        $segments = [
            ...array_fill(0, count($from) - $shared, '..'),
            ...array_slice($to, $shared),
            basename($toFile),
        ];

        return implode('/', $segments);
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        $normalized = str_replace('\\', '/', rtrim($path, '/\\'));

        return array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
    }
}

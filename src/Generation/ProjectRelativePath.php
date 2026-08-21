<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

/**
 * Renders a path the way a generated file should name it: from the project root.
 *
 * **An absolute path must never reach a generated file.** These files are emitted
 * on one machine and read on others, and whether they are committed is
 * [the consumer's choice](../../docs/guide/code-generation.md), not ours — so an
 * absolute path is a defect waiting for the first project that tracks its
 * generated tree. It would differ between every developer and every CI runner,
 * producing a diff nobody made, and it would publish one machine's directory
 * layout — including a username — into a repository.
 *
 * **The root is the nearest ancestor holding a `composer.json`**, rather than the
 * application's `base_path()`. For an ordinary Laravel application the two are
 * the same directory, so the choice costs nothing there. It earns its keep where
 * they differ: a specification kept outside the application root, or this
 * package's own Workbench, where `base_path()` is a skeleton under `vendor/` and
 * a base-path rule would fall back to the absolute path it was written to avoid.
 *
 * One rule that behaves in both layouts beats two rules that each cover one.
 */
final readonly class ProjectRelativePath
{
    /**
     * The path relative to the project root, or unchanged when there is no root
     * above it.
     *
     * Falling back to the absolute path is deliberate rather than a gap: a file
     * genuinely outside any project has no shorter honest name, and inventing a
     * `../../..` chain would be less readable than the truth.
     */
    public static function from(string $absolute): string
    {
        $root = self::projectRoot(dirname($absolute));

        if ($root === null) {
            return $absolute;
        }

        return substr($absolute, strlen($root) + 1);
    }

    /**
     * Walk up until a directory holds a `composer.json`.
     *
     * Terminates because `dirname()` of a filesystem root returns that root, so
     * the path stops changing and the loop ends whether or not anything is found.
     */
    private static function projectRoot(string $directory): ?string
    {
        while (true) {
            if (is_file($directory.DIRECTORY_SEPARATOR.'composer.json')) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }
    }
}

<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Composer\Autoload\ClassLoader;

/**
 * Answers whether the custom controller a contract names has been written yet.
 *
 * The build needs that answer to decide what a route points at: the child when it
 * exists, the generated parent when it does not. Which makes *how* the question
 * is asked load-bearing.
 *
 * **It asks the autoloader for the file, not PHP for the class.** `class_exists()`
 * would load the child, and a child extending a generated parent the build has
 * not written yet is exactly the state a first build is in — so loading it would
 * raise on the missing parent while planning the file that would have fixed it.
 * `findFile()` applies the same PSR-4 and classmap rules Composer applies at
 * runtime, and applies them without executing a line.
 *
 * **A file rather than a working class is deliberately the bar.** A child that
 * exists and cannot load yet is still the class the route belongs to: it will
 * work the moment this build writes its parent. Answering "not there" would point
 * the route at the parent, and the next build would move it — a build whose output
 * depends on whether a previous one ran is the one thing this package cannot be.
 *
 * @see docs/guide/controllers.md — "Two classes, found by name rather than by a scan"
 */
final readonly class CustomControllerLookup
{
    /**
     * @param  list<ClassLoader>  $loaders  every Composer loader registered, in the
     *                                      order PHP would consult them
     */
    private function __construct(private array $loaders) {}

    /**
     * The loaders the running application actually has.
     */
    public static function fromAutoloader(): self
    {
        $loaders = [];

        foreach (spl_autoload_functions() ?: [] as $autoloader) {
            // Composer registers `[$loader, 'loadClass']`, so the loader is the
            // first element of an array callable. Anything else registered — a
            // closure, another package's loader — cannot be asked for a file
            // without loading, so it is skipped rather than guessed at.
            if (is_array($autoloader) && $autoloader[0] instanceof ClassLoader) {
                $loaders[] = $autoloader[0];
            }
        }

        return new self($loaders);
    }

    /**
     * **Composer's loaders and nothing else, with no fallback to `class_exists`.**
     * An earlier version fell back to loading the class when no Composer loader
     * was registered, and treated a throw during that load as "it exists" —
     * because the missing-parent case throws. Two things were wrong with it. It
     * would call a class that cannot load *for any other reason* — a syntax
     * error, a missing interface — existing, and point a route at it, turning a
     * 501 into a 500 at request time. And it could not be tested from inside a
     * Composer-autoloaded test suite, because the branch only runs where no
     * Composer loader exists: untestable code guarding a case that cannot occur
     * in the only place this runs, which is an Artisan command inside a Laravel
     * application.
     *
     * So the answer where there is no Composer loader is no. The consequence is
     * stated rather than hidden: on such a project every route points at its
     * generated parent, which is the same answer as a custom controller nobody
     * has written yet, and the safe direction of the two.
     */
    public function exists(string $class): bool
    {
        foreach ($this->loaders as $loader) {
            if ($loader->findFile($class) !== false) {
                return true;
            }
        }

        return false;
    }
}

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
     * **Asked as a file on disk rather than through `findFile`, and that is not the
     * same question twice.** Composer's loader memoizes what it failed to find: a
     * class asked about before its file existed is recorded as missing and answers
     * missing for the rest of the process. That used to be harmless, and stopped
     * being so the moment `spec:make` started
     * [running a build after scaffolding](../Console/MakeCommand.php) — two answers
     * about the same class in one process, with a file created between them.
     *
     * So the prefixes are read from the loader, which is not cached, and the
     * filesystem answers whether the file is there. `findFile` is still asked when
     * no PSR-4 prefix matches, because a classmapped class has no prefix to
     * compute a path from — and for that case a stale negative would mean a class
     * this build did not write, which is not a case this command creates.
     *
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
        foreach ($this->candidates($class) as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }

        foreach ($this->loaders as $loader) {
            if ($loader->findFile($class) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a class of this name would have to live for the autoloader to find it.
     *
     * **The inverse of the question above, and it exists for `spec:make`**: a
     * class the specification names and nobody has written has no file to be
     * found, so scaffolding one means working out where PSR-4 says it belongs.
     * Asked of the same loaders rather than of a convention, because a project's
     * own `composer.json` is the only thing that actually decides — and a
     * scaffold written where the autoloader does not look is a file that compiles
     * and never runs.
     *
     * **The longest matching prefix wins**, which is Composer's own rule: with
     * both `App\` and `App\Http\` mapped, a class under the second belongs in
     * its directory rather than in the first one's subtree. The first directory a
     * prefix lists is the one written to, again following Composer, which lists
     * them in the order it searches.
     *
     * Null when no prefix matches at all: the class belongs to a namespace this
     * project does not map, and guessing a path would put a file where nothing
     * will ever look for it.
     */
    public function pathFor(string $class): ?string
    {
        return $this->candidates($class)[0] ?? null;
    }

    /**
     * Every path PSR-4 would accept for this class, the likeliest first.
     *
     * **Ordered by prefix length, which is Composer's own rule:** with both `App\`
     * and `App\Http\` mapped, a class under the second belongs in its directory
     * rather than in the first one's subtree. Within one prefix the directories keep
     * the order the project listed them, again following Composer, which searches
     * them in that order.
     *
     * All of them rather than only the first, because {@see self::exists()} asks
     * whether the class is written *anywhere* the autoloader would look, while
     * {@see self::pathFor()} needs the single place to write one — two questions
     * with one answer each, from one list.
     *
     * @return list<string>
     */
    private function candidates(string $class): array
    {
        $byPrefix = [];

        foreach ($this->loaders as $loader) {
            /** @var array<string, list<string>> $prefixes */
            $prefixes = $loader->getPrefixesPsr4();

            foreach ($prefixes as $prefix => $directories) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));

                foreach ($directories as $directory) {
                    // Resolved rather than concatenated. Composer records PSR-4
                    // directories relative to `vendor/composer/`, so the raw value
                    // produces a working but unreadable
                    // `vendor/composer/../../app/Http/Controllers/UserController.php`
                    // — a path this command prints to a human and compares against
                    // the project root.
                    $resolved = realpath($directory);

                    $byPrefix[$prefix][] = rtrim(
                        $resolved !== false ? $resolved : $directory,
                        '/'.DIRECTORY_SEPARATOR
                    ).DIRECTORY_SEPARATOR.$relative.'.php';
                }
            }
        }

        uksort($byPrefix, static fn (string $first, string $second): int => strlen($second) <=> strlen($first));

        return array_merge(...array_values($byPrefix)) ?: [];
    }
}

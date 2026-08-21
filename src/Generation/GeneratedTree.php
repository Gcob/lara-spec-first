<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Generation\Exceptions\EscapedTreeException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnwritableTreeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The only thing that writes to disk, and the only thing that knows where.
 *
 * Everything above it works on relative paths, which is what makes the one
 * property this class owes testable in one place: **the build never writes
 * outside its own directory.** Stated without a clause on purpose. A rule with an
 * exception erodes, and every later feature would have argued for its own.
 *
 * Two more properties come from here rather than from the emitters:
 *
 * **Idempotent.** A file whose contents already match is left alone, so a second
 * run in a row changes nothing at all — not even a modification time. If a build
 * produces a diff on an unchanged specification, that is a defect.
 *
 * **Pruned.** A controller for an operation the specification no longer has is
 * removed, because "rewritten from scratch" has to mean the tree matches the
 * contract rather than accumulating what it used to say. Only files carrying
 * {@see GeneratedFile::MARKER} are ever deleted, so a file somebody wrote inside
 * the generated tree survives a build even though it should not be there.
 *
 * @see docs/guide/code-generation.md — "The invariant: a build never destroys human work"
 */
final readonly class GeneratedTree
{
    public function __construct(private string $root) {}

    /**
     * @param  list<GeneratedFile>  $files
     *
     * @throws EscapedTreeException
     * @throws UnwritableTreeException
     */
    public function write(array $files): WriteReport
    {
        $written = 0;
        $unchanged = 0;
        $keep = [];

        foreach ($files as $file) {
            $absolute = $this->resolve($file->relativePath);
            $keep[$absolute] = true;

            if (is_file($absolute) && file_get_contents($absolute) === $file->contents) {
                $unchanged++;

                continue;
            }

            $this->ensureDirectory(dirname($absolute));

            // The case a container makes ordinary: the file exists, a previous
            // run created it as another user, and the directory around it is
            // perfectly writable. Checked before the write so the failure is a
            // message naming the path rather than a PHP warning.
            if (is_file($absolute) && ! is_writable($absolute)) {
                throw UnwritableTreeException::file($absolute);
            }

            // Checked rather than trusted even so. `file_put_contents` reports
            // failure by returning false, and counting a file as written that is
            // not on disk is how a build reports success on an application whose
            // routes describe a contract nothing serves.
            if (file_put_contents($absolute, $file->contents) === false) {
                throw UnwritableTreeException::file($absolute);
            }

            $written++;
        }

        return new WriteReport($written, $unchanged, $this->prune($keep));
    }

    /**
     * Join a relative path under the root, and prove it stayed there.
     *
     * The check is on the resolved string rather than on `realpath()`, which
     * returns false for a file that does not exist yet — every file on a first
     * build. Segment-wise resolution is what makes it work before the write
     * rather than after it, which is the only moment it is useful.
     *
     * @throws EscapedTreeException
     */
    private function resolve(string $relative): string
    {
        $root = rtrim($this->root, '/\\');
        $segments = [];

        foreach (preg_split('#[/\\\\]#', $relative) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw EscapedTreeException::relativePath($relative);
            }

            $segments[] = $segment;
        }

        if ($segments === [] || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:/', $relative) === 1) {
            throw EscapedTreeException::relativePath($relative);
        }

        return $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Remove the generated files the plan no longer contains.
     *
     * @param  array<string, true>  $keep  absolute paths the plan just wrote or left alone
     * @return int how many stale files were removed
     */
    private function prune(array $keep): int
    {
        if (! is_dir($this->root)) {
            return 0;
        }

        $removed = 0;

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir()) {
                // Children are visited first, so a directory the plan emptied is
                // empty by now. Checked rather than suppressed: `@rmdir` on a
                // directory that still holds something is a warning we would be
                // hiding, and hiding it here would hide the day it means
                // something.
                if (self::isEmpty($path)) {
                    rmdir($path);
                }

                continue;
            }

            if (isset($keep[$path]) || $entry->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($path);

            if (is_string($contents) && str_contains($contents, GeneratedFile::MARKER)) {
                if (! unlink($path)) {
                    throw UnwritableTreeException::staleFile($path);
                }

                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Create the directory a file is about to go in, and prove it can be used.
     *
     * The mode is `0777` because **the umask decides, not this number**: the
     * process umask is subtracted from it, so a normal `022` yields `0755` and a
     * shared-group `002` yields `0775`. Passing the permissive value is what
     * defers the policy to the operator instead of overriding it, and it is
     * exactly what Laravel's own generators pass — `GeneratorCommand` calls
     * `makeDirectory($path, 0777, true, true)` for every `make:` command. Written
     * out because `0777` reads alarming on its own, and a reader who stops to
     * check should find the answer here rather than in a framework's source.
     *
     * **Nothing is ever `chmod`-ed.** Files land at the umask default, and
     * forcing a mode afterwards would override the same operator policy this
     * defers to.
     *
     * The writability check is what turns a permission problem into a message
     * naming the path. It races, in principle — the directory could become
     * unwritable between here and the write — which is why the caller checks the
     * write's own result as well.
     *
     * @throws UnwritableTreeException
     */
    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            // The nearest ancestor that exists is what `mkdir` will actually
            // write into, so it is what decides whether this can work. Asked
            // first because `mkdir` on an unwritable parent emits a warning on
            // its way to returning false, and a warning is the diagnostic this
            // package is replacing rather than one it wants to add.
            if (! is_writable(self::nearestExistingAncestor($directory))) {
                throw UnwritableTreeException::directory($directory);
            }

            if (! mkdir($directory, 0o777, true)) {
                throw UnwritableTreeException::directory($directory);
            }
        }

        if (! is_writable($directory)) {
            throw UnwritableTreeException::directory($directory);
        }
    }

    /**
     * The closest directory above this path that already exists.
     *
     * Terminates because `dirname()` of a filesystem root is that root, which
     * always exists.
     */
    private static function nearestExistingAncestor(string $directory): string
    {
        while (! is_dir($directory)) {
            $directory = dirname($directory);
        }

        return $directory;
    }

    private static function isEmpty(string $directory): bool
    {
        $entries = scandir($directory);

        return is_array($entries) && count($entries) === 2;
    }
}

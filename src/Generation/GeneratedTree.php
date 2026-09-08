<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Generation\Exceptions\EscapedTreeException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnwritableTreeException;
use Gcob\LaraSpecFirst\Support\Path;
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
 * **Empty directories are the one exception, and it is stated rather than left to
 * be discovered.** A directory carries no marker, so there is no way to tell one
 * the build created from one a person did — and leaving `Controllers/` behind
 * after its last controller was pruned reads as a bug. So an empty directory
 * inside the tree is removed whoever made it. Nothing that holds a file is
 * touched, which is the part that matters.
 *
 * @see docs/guide/code-generation/index.md — "The invariant: a build never destroys human work"
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

        if ($segments === [] || Path::isAbsolute($relative)) {
            throw EscapedTreeException::relativePath($relative);
        }

        return $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Everything this plan would do to the working tree, without touching it —
     * the read-only half of {@see self::write()}, computed the same way so the
     * two can never disagree about what counts as changed or stale.
     *
     * **Empty directories are deliberately not reported.** `write()` removes
     * one left behind by the last file pruned out of it, but that is cleanup
     * with nothing to compare against — there is no "would this directory
     * become empty" question worth a line in a diagnostic, only files.
     *
     * @param  list<GeneratedFile>  $files
     */
    public function diff(array $files): DriftReport
    {
        $toWrite = [];
        $unchanged = [];
        $keep = [];

        foreach ($files as $file) {
            $absolute = $this->resolve($file->relativePath);
            $keep[$absolute] = true;

            if (is_file($absolute) && file_get_contents($absolute) === $file->contents) {
                $unchanged[] = $file->relativePath;
            } else {
                $toWrite[] = $file->relativePath;
            }
        }

        return new DriftReport($toWrite, $unchanged, $this->findPrunable($keep));
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

        $prunable = $this->findPrunable($keep);

        foreach ($prunable as $relative) {
            $absolute = $this->resolve($relative);

            if (! unlink($absolute)) {
                throw UnwritableTreeException::staleFile($absolute);
            }
        }

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        // Children are visited first, so a directory the plan emptied above is
        // empty by now. Checked rather than suppressed: `@rmdir` on a
        // directory that still holds something is a warning we would be
        // hiding, and hiding it here would hide the day it means something.
        // A second walk rather than folded into `findPrunable()`'s: that one
        // has to stay read-only for `diff()`, and this one exists to act.
        foreach ($entries as $entry) {
            if ($entry->isDir() && self::isEmpty($entry->getPathname())) {
                rmdir($entry->getPathname());
            }
        }

        return count($prunable);
    }

    /**
     * Every generated file under this tree that the plan no longer wants,
     * relative to the root — read-only, so both `prune()` and `diff()` can
     * share it.
     *
     * @param  array<string, true>  $keep  absolute paths the plan just wrote or left alone
     * @return list<string>
     */
    private function findPrunable(array $keep): array
    {
        if (! is_dir($this->root)) {
            return [];
        }

        // Normalized the same way `resolve()` already normalizes it for
        // building a path — a root passed in with a trailing separator would
        // otherwise make the prefix strip below one character short, since it
        // assumes exactly one separator between the root and what follows it.
        $root = rtrim($this->root, '/\\');

        $prunable = [];

        /** @var iterable<SplFileInfo> $entries */
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir() || isset($keep[$path]) || $entry->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($path);

            if (is_string($contents) && str_contains($contents, GeneratedFile::MARKER)) {
                // `$path` is always `$root` plus a separator plus the rest,
                // since it came from an iterator rooted there — a plain
                // prefix strip rather than `str_replace()`, which would also
                // rewrite an occurrence of the root's own text appearing
                // again further down the path.
                $prunable[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            }
        }

        return $prunable;
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

<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Generation\CustomControllerLookup;
use Gcob\LaraSpecFirst\Support\Path;

/**
 * Two silent misconfigurations whose symptoms appear far from their cause:
 * the vendored reference directory gitignored out of a commit, and a
 * generated path or namespace that does not agree with what Composer
 * actually autoloads.
 *
 * Both findings are filed as document faults, and docs/guide/doctor.md's own
 * table is what makes that the right class rather than an exemption argued
 * here: a document fault is a fault only the project can fix, which includes
 * a rule this package requires of a promise the project itself made. Neither
 * of these is the document being invalid OpenAPI, and neither is a stance this
 * package takes on an otherwise-correct document — they are the project's own
 * configuration disagreeing with itself, which nobody but the project can
 * settle, and which wants the harder of the two exit codes.
 *
 * **Neither finding is ever raised on an inference this class cannot make.**
 * That rule is the whole design of both methods below, and each names the
 * cases it stays silent about — a false positive in this section sends a
 * developer looking for a misconfiguration that is not there, in a report
 * whose only value is that a reader trusts it.
 *
 * @see docs/guide/doctor.md — "What it checks"
 * @see docs/guide/doctor.md — "Two kinds of finding, never mixed"
 */
final readonly class InstallationCheck
{
    private const SECTION = 'Installation';

    /**
     * @return list<Finding>
     */
    public static function check(
        string $basePath,
        string $vendorPath,
        string $generatedNamespace,
        string $generatedPath,
    ): array {
        return array_values(array_filter([
            self::gitignoreFinding($basePath, $vendorPath),
            self::autoloadFinding($basePath, $generatedNamespace, $generatedPath),
        ]));
    }

    /**
     * Whether `remote_references.vendor_path` is excluded by `.gitignore` —
     * asked of `git` itself rather than a `.gitignore` parser reimplemented
     * here, since git already resolves nested files, `core.excludesFile` and
     * negation correctly and a second implementation would only be a source
     * of false negatives.
     *
     * **Never blocks the report.** Outside a git repository, or without a
     * `git` executable to ask, this is "cannot be determined", not a fault —
     * a deployed application with no `.git` directory is a real, legitimate
     * state, not a misconfiguration.
     *
     * **A known limit, git's own rather than this method's: a directory-only
     * pattern (`vendor-refs/`, trailing slash) can only be resolved once the
     * directory actually exists on disk** — `git check-ignore` has to stat
     * the path to know whether a directory-only rule even applies, so a
     * project that named an allowed host but has not vendored anything yet
     * gets a silent pass here even if its `.gitignore` would have hidden the
     * directory the moment something landed in it. Verified directly against
     * git, not assumed. The realistic case this section exists for — a
     * directory that already holds a vendored copy — is unaffected.
     */
    private static function gitignoreFinding(string $basePath, string $vendorPath): ?Finding
    {
        if (! is_dir($basePath.'/.git')) {
            return null;
        }

        $absoluteVendorPath = Path::isAbsolute($vendorPath) ? $vendorPath : $basePath.'/'.$vendorPath;

        $command = sprintf(
            'git -C %s check-ignore --quiet -- %s',
            escapeshellarg($basePath),
            escapeshellarg($absoluteVendorPath),
        );

        // `exec()`'s own output parameter rather than a `2>/dev/null` appended
        // to the command string: that redirect is not `cmd.exe` syntax, so on
        // Windows the call itself failed and its non-zero exit read as "cannot
        // tell" — a check that silently never fires on a platform this
        // codebase already takes care over elsewhere (see
        // `ReadsTheContract::specPath()` and its `C:\specs\api.yaml` case).
        // Captured into a variable nothing reads, because the point was only
        // ever to keep git's own chatter off the report.
        exec($command, $ignoredOutput, $exitCode);

        // 0 = ignored, 1 = not ignored, anything else (128: not a repository
        // after all; 127: no `git` on the PATH) is "cannot tell" rather than
        // a finding — a false positive here would send a developer looking
        // for a `.gitignore` line that explains nothing.
        if ($exitCode !== 0) {
            return null;
        }

        return new Finding(
            FindingClass::DocumentFault,
            self::SECTION,
            null,
            '',
            sprintf(
                '"%s" is excluded by .gitignore. A remote reference vendored there is a dependency, not a cache '.
                'entry — the next `git clone` gets a document this package refuses to load until '.
                '`spec:build --update-refs` runs again, on a machine that may not have network access to the '.
                'same host. Remove the ignore rule and commit the vendored copies.',
                $vendorPath,
            ),
        );
    }

    /**
     * Whether the configured `generated.path` and `generated.namespace`
     * agree with what Composer's own PSR-4 map says — the same question
     * {@see CustomControllerLookup} answers
     * for a single class, asked here of the generated tree's root instead.
     *
     * **`autoload-dev` counts.** Composer merges both maps into one
     * classloader, so a project whose generated tree lives under a dev-only
     * root — this package's own Workbench is one, and so is any consumer that
     * generates into a test or tooling namespace — autoloads perfectly well
     * while `autoload.psr-4` alone maps nothing. Reading one map and claiming
     * "nothing would autoload the generated tree at all" was wrong about a
     * layout Composer supports, in the loudest class of finding this report
     * has.
     *
     * **"Cannot be determined" is never a finding here either**, the same rule
     * {@see self::gitignoreFinding()} follows and for the same reason. Two
     * cases, and both are real layouts rather than mistakes:
     *
     * - **A `generated.path` that is absolute and outside the application
     *   root.** `GeneratedRoutesLocator::directory()` supports that
     *   deliberately — "a generated tree outside the application root is a
     *   real layout in a monorepo" — and this method has no way to know which
     *   `composer.json` governs a directory outside the one it is reading.
     *   Comparing an out-of-tree absolute path against a relative PSR-4
     *   directory can only ever mismatch, so it said "would not autoload"
     *   about every monorepo that does exactly what the locator documents.
     * - **No `composer.json` at `$basePath`, or one that parses to something
     *   else.** Already handled below, and named here because it is the same
     *   rule.
     *
     * The consequence is stated rather than hidden: on those layouts this
     * check is silent, and silence is not a pass. That is what the section's
     * `[note]` line is for once this method has a way to say it — see
     * docs/guide/doctor.md.
     */
    private static function autoloadFinding(string $basePath, string $namespace, string $path): ?Finding
    {
        // Asked before the map is even read, because the answer does not
        // depend on it: nothing in this `composer.json` describes a directory
        // that is not under it.
        if (Path::isAbsolute($path) && ! self::isUnder($basePath, $path)) {
            return null;
        }

        $composerJsonPath = $basePath.'/composer.json';

        if (! is_file($composerJsonPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($composerJsonPath), true);

        if (! is_array($decoded)) {
            return null;
        }

        $psr4 = self::psr4Map($decoded);

        if ($psr4 === []) {
            return null;
        }

        $normalizedNamespace = rtrim($namespace, '\\').'\\';
        $matched = self::longestPrefix($psr4, $normalizedNamespace);

        if ($matched === null) {
            return new Finding(
                FindingClass::DocumentFault,
                self::SECTION,
                null,
                '',
                sprintf(
                    'generated.namespace is "%s", but no `autoload.psr-4` or `autoload-dev.psr-4` prefix in '.
                    'composer.json maps it — nothing would autoload the generated tree at all.',
                    $namespace,
                ),
            );
        }

        [$prefix, $directory] = $matched;

        $expected = self::normalize(rtrim($directory, '/').'/'.str_replace(
            '\\',
            '/',
            rtrim(substr($normalizedNamespace, strlen($prefix)), '\\'),
        ));
        $configured = self::normalize(Path::isAbsolute($path) ? self::relativeTo($basePath, $path) : $path);

        if ($expected === $configured) {
            return null;
        }

        return new Finding(
            FindingClass::DocumentFault,
            self::SECTION,
            null,
            '',
            sprintf(
                'generated.path is "%s", but composer.json\'s `autoload.psr-4` map puts "%s" at "%s" — '.
                'the generated tree would not autoload.',
                $path,
                $namespace,
                $expected,
            ),
        );
    }

    /**
     * Both PSR-4 maps `composer.json` can carry, merged the way Composer
     * merges them into one classloader.
     *
     * `autoload` wins on a prefix both declare — which is Composer's own
     * order — though a project declaring the same prefix in both is already
     * saying something odd.
     *
     * @param  array<array-key, mixed>  $composerJson
     * @return array<array-key, mixed>
     */
    private static function psr4Map(array $composerJson): array
    {
        /** @var mixed $production */
        $production = $composerJson['autoload']['psr-4'] ?? null;
        /** @var mixed $development */
        $development = $composerJson['autoload-dev']['psr-4'] ?? null;

        return [
            ...is_array($development) ? $development : [],
            ...is_array($production) ? $production : [],
        ];
    }

    /**
     * Whether an absolute path sits inside `$basePath`.
     *
     * Compared as strings after normalizing separators, not through
     * `realpath()`: the generated tree may not exist yet — a fresh clone that
     * has never built is the common case — and `realpath()` answers false for
     * a path that is merely not there, which would read as "outside".
     */
    private static function isUnder(string $basePath, string $path): bool
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';

        return str_starts_with(str_replace('\\', '/', $path), $base);
    }

    /**
     * The PSR-4 entry whose prefix matches the most of `$namespace` —
     * Composer's own rule, so that a project mapping both `App\` and
     * `App\Http\` is judged against the one it would actually use.
     *
     * @param  array<array-key, mixed>  $psr4  keys are almost always strings, but a
     *                                         purely numeric-looking one in
     *                                         composer.json decodes to an int key,
     *                                         same as any other PHP array
     * @return ?array{0: string, 1: string}
     */
    private static function longestPrefix(array $psr4, string $namespace): ?array
    {
        $best = null;

        foreach ($psr4 as $prefix => $directory) {
            if (! is_string($prefix) || ! is_string($directory)) {
                continue;
            }

            $normalizedPrefix = rtrim($prefix, '\\').'\\';

            if (! str_starts_with($namespace, $normalizedPrefix)) {
                continue;
            }

            if ($best === null || strlen($normalizedPrefix) > strlen($best[0])) {
                $best = [$normalizedPrefix, $directory];
            }
        }

        return $best;
    }

    private static function relativeTo(string $basePath, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $basePath), '/').'/';
        $target = str_replace('\\', '/', $path);

        return str_starts_with($target, $base) ? substr($target, strlen($base)) : $target;
    }

    private static function normalize(string $relative): string
    {
        return trim(str_replace('\\', '/', $relative), '/');
    }
}

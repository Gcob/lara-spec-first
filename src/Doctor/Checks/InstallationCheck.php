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
 * Both findings are filed as document faults for the same reason drift is:
 * neither is really the document's fault or a stance this package takes on
 * it, but the two `FindingClass` cases exist to decide how hard the exit
 * code gates a pipeline, and an installation that silently drops files or
 * cannot autoload what it just generated wants the harder of the two.
 *
 * @see docs/guide/doctor.md — "What it checks"
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

        exec($command.' 2>/dev/null', result_code: $exitCode);

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
     */
    private static function autoloadFinding(string $basePath, string $namespace, string $path): ?Finding
    {
        $composerJsonPath = $basePath.'/composer.json';

        if (! is_file($composerJsonPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($composerJsonPath), true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var mixed $psr4 */
        $psr4 = $decoded['autoload']['psr-4'] ?? null;

        if (! is_array($psr4)) {
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
                    'generated.namespace is "%s", but no `autoload.psr-4` prefix in composer.json maps it — '.
                    'nothing would autoload the generated tree at all.',
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

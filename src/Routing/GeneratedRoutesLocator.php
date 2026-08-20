<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Routing;

/**
 * Resolves where the generated route registrations are, and says whether they
 * are there yet. It never opens the file and never holds a route.
 *
 * All of this class is path arithmetic, and that is the design rather than a
 * thin first cut: at boot the package loads generated PHP and nothing else, so
 * everything the runtime needs to know about routing is one filename and
 * whether it exists. Anything more would mean the runtime deciding something
 * the build already decided — which is also why the name says `Locator` rather
 * than naming the routes themselves.
 *
 * Constructed with the configured values rather than reading configuration
 * itself, so it stays a plain object a unit test can build.
 *
 * @see docs/guide/code-generation.md — "The runtime never sees the spec"
 * @see docs/guide/code-generation.md — "Where generated code lives"
 */
final readonly class GeneratedRoutesLocator
{
    /**
     * The one file `spec:build` writes route registrations into.
     *
     * A fixed name inside the configured tree rather than a setting of its own.
     * The build owns every file under that root, so a second key could only ever
     * let the writer and the reader disagree about one filename.
     */
    public const string FILE = 'routes.php';

    public function __construct(
        private string $basePath,
        private string $configuredPath,
    ) {}

    /**
     * The generated routes file, whether it has been written.
     */
    public function path(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.self::FILE;
    }

    /**
     * Whether the build has produced routes for this application yet.
     *
     * False is a legitimate state rather than a fault, and the service
     * provider's own boot method carries why nothing may throw over it.
     *
     * Deliberately not a `{@see}` at that method: Pint resolves a reference
     * like that into a real `use` statement, so a docblock pointing back at the
     * provider would make this class depend on it. Prose costs nothing here.
     */
    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * The configured tree, resolved against the application root.
     *
     * An already-absolute setting is taken as written. The alternative is
     * joining it under the base path anyway, which produces a path that is
     * wrong rather than a path that fails — and a generated tree outside the
     * application root is a real layout in a monorepo, not a misconfiguration
     * to guess at.
     */
    private function directory(): string
    {
        $path = rtrim($this->configuredPath, '/\\');

        if ($this->isAbsolute($path)) {
            return $path;
        }

        return rtrim($this->basePath, '/\\').DIRECTORY_SEPARATOR.$path;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\/\\\\]/', $path);
    }
}

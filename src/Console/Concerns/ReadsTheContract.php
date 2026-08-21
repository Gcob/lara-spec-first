<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console\Concerns;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;
use Gcob\LaraSpecFirst\Support\Path;
use Illuminate\Contracts\Config\Repository;

/**
 * How a command finds the contract and reads it.
 *
 * **Shared because it has to be identical, not because it is convenient.** Two
 * commands that resolved the specification differently would disagree about which
 * document the project has — `spec:build` generating from one file while
 * `spec:make` scaffolds against another is a defect nobody would suspect until
 * the class names stopped matching. The same argument applies to the reading
 * pipeline: every guard runs for every command, or the strict one is the only one
 * that is strict.
 *
 * @see docs/guide/openapi-support.md — "Reading a document"
 */
trait ReadsTheContract
{
    /**
     * The operations the contract describes, in document order.
     *
     * @return list<Operation>
     */
    protected function contractOperations(string $specPath, RemoteReferenceGuard $remote): array
    {
        // The guard is resolved by the caller from the container so that the
        // configured allowlist applies here exactly as it does anywhere else.
        $reader = new SpecDocumentReader(new VersionStrategyFactory, remote: $remote);

        return (new OperationExtractor)->extract($reader->read($specPath));
    }

    /**
     * The flag wins over configuration, and both are resolved against the
     * application root unless they are already absolute.
     */
    protected function specPath(Repository $config): string
    {
        $option = $this->option('spec');

        $path = is_string($option) && $option !== ''
            ? $option
            : $this->requiredString($config, 'lara-spec-first.spec.path');

        // `Path::isAbsolute` rather than a leading-separator check: this used to
        // ask only about `/`, which treats `C:\specs\api.yaml` as relative and
        // joins it under the application root.
        return Path::isAbsolute($path)
            ? $path
            : $this->laravel->basePath($path);
    }

    /**
     * A configured value the command cannot proceed without.
     *
     * Read as `mixed` and checked, rather than annotated as a string: a config
     * repository promises nothing about a key's type, and the values an
     * annotation would have declared impossible are exactly the ones worth a
     * message — a key set to null, to a list, or emptied by hand.
     *
     * @throws UnusableSettingException
     */
    protected function requiredString(Repository $config, string $key): string
    {
        $value = $config->get($key);

        if (! is_string($value) || trim($value) === '') {
            throw UnusableSettingException::setting($key, 'a non-empty string');
        }

        return $value;
    }

    /**
     * Say a specification is not where it was looked for, in the words both
     * commands owe a reader: the path, the setting, and the flag.
     */
    protected function reportMissingSpecification(string $specPath): void
    {
        $this->components->error(sprintf(
            'No specification at %s. Set `%s` in config/lara-spec-first.php, or pass --spec.',
            $specPath,
            'lara-spec-first.spec.path',
        ));
    }
}

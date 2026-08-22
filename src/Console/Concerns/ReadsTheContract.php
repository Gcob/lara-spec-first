<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Console\Concerns;

use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Exceptions\UnusableSettingException;
use Gcob\LaraSpecFirst\Parsing\Guards\RemoteReferenceGuard;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;
use Gcob\LaraSpecFirst\Support\Path;
use Illuminate\Console\Command;
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
     * The contract, read end to end — see {@see ReadOutcome}.
     *
     * **The seam a command that reports rather than refuses reads through.**
     * `operationsOrFail()` below is its only caller today, and every command in
     * this package goes through that one instead, because every command in this
     * package stops at the first fault. This method exists separately for the
     * caller that does not: {@see ReadOutcome}
     * carries the whole fault list and the flag saying whether the document was
     * rewritten, and neither survives `operationsOrFail()`'s narrowing to a
     * list of operations.
     *
     * @param  bool  $updateRefs  fetch and vendor an allowed remote reference —
     *                            `spec:build --update-refs`'s one entry point into
     *                            the reading pipeline. Every other caller leaves it
     *                            false and stays frozen, exactly as today.
     */
    protected function readContract(string $specPath, RemoteReferenceGuard $remote, bool $updateRefs = false): ReadOutcome
    {
        // The guard is resolved by the caller from the container so that the
        // configured allowlist applies here exactly as it does anywhere else.
        $reader = new SpecDocumentReader(new VersionStrategyFactory, remote: $remote);

        return ReadOutcome::read($reader, $specPath, $updateRefs);
    }

    /**
     * The operations the contract describes, in document order — or null,
     * having already reported the first fault, the moment there is one.
     *
     * **This is where `spec:build` and `spec:make` keep today's behaviour**:
     * the reading pipeline itself no longer refuses, so its callers are the
     * ones that decide a single fault is enough to stop — the same message a
     * caught `SpecException` used to carry, from the same exception object,
     * just read off {@see ReadOutcome::$faults} instead of caught off a throw.
     *
     * @return list<Operation>|null
     */
    protected function operationsOrFail(string $specPath, RemoteReferenceGuard $remote, bool $updateRefs = false): ?array
    {
        $outcome = $this->readContract($specPath, $remote, $updateRefs);

        if (! $outcome->isClean()) {
            $this->reportFault($outcome->faults[0], count($outcome->faults) - 1);

            return null;
        }

        return $outcome->operations;
    }

    /**
     * Render a refusal the way this package renders every one of them, in the
     * one place that decides how.
     *
     * **Shared with each command's outer `catch (SpecException)` on purpose.**
     * Printing the message and returning `FAILURE` is the same act whether the
     * fault was read off a {@see ReadOutcome} or caught off a throw, and the
     * two rendering it separately is exactly the divergence
     * {@see ReadOutcome}'s own docblock argues against for the read itself.
     *
     * `$others` is the number of faults this read found beyond the one being
     * printed. **Counted rather than swallowed**: refusing at the first fault is
     * the behaviour `spec:build` and `spec:make` have always had and keep, but
     * the pipeline now knows there are five where it used to know one, and
     * saying nothing at all about the other four reads as a regression rather
     * than as parity.
     */
    protected function reportFault(SpecException $fault, int $others = 0): int
    {
        $this->components->error($fault->getMessage());

        if ($others > 0) {
            // Deliberately not naming a command to run. `spec:doctor` — which
            // reports every fault in one pass and is the whole reason the
            // pipeline stopped throwing — is the next thing to land, and this
            // is the line that will name it. Pointing a developer at a command
            // that does not exist yet would be worse than counting.
            $this->components->warn(sprintf(
                '%d more fault%s in this specification; only the first is shown.',
                $others,
                $others === 1 ? '' : 's',
            ));
        }

        return Command::FAILURE;
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

<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Exceptions\NotImplementedYetException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RemoteReferenceException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

/**
 * Refuses a `$ref` that would reach over the network.
 *
 * The parser resolves such a reference by calling `file_get_contents()` on the
 * URL (`ReferenceContext.php:217`), so without this the first document handed
 * to it decides where the application connects. That is the whole reason the
 * allowlist exists, and until the allowlist is configurable the documented
 * default — empty, meaning no remote references at all — is the behaviour.
 *
 * Runs on the raw document, before the parser sees anything, because after that
 * point the request has already been made.
 *
 * Every `$ref` is examined, including those in positions the cycle detector
 * treats as data: the question here is not what the reference means but whether
 * the parser would dial out for it, and it would.
 *
 * @internal Not public API — a step of the read pipeline.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 * @see docs/REMOTE-REFERENCES.md
 */
final readonly class RemoteReferenceGuard
{
    /**
     * @param  list<string>  $allowedHosts  hosts a project has declared it trusts.
     *                                      Reading it here rather than reaching for
     *                                      Laravel's config keeps this class a plain
     *                                      object that a unit test can construct.
     */
    public function __construct(private array $allowedHosts = []) {}

    /**
     * @param  array<array-key, mixed>  $node
     *
     * @throws RemoteReferenceException
     * @throws NotImplementedYetException a host has been allowed, and allowing one does nothing yet
     */
    public function assertNoRemoteReferences(array $node): void
    {
        if ($this->allowedHosts !== []) {
            throw NotImplementedYetException::setting(
                'lara-spec-first.remote_references.allowed_hosts',
                'fetch the document once, vendor it into the repository, and resolve the reference '.
                'against the committed copy',
                'Remote reference vendoring'
            );
        }

        $this->walk($node);
    }

    /**
     * @param  array<array-key, mixed>  $node
     *
     * @throws RemoteReferenceException
     */
    private function walk(array $node): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && self::reachesOverTheNetwork($value)) {
                throw RemoteReferenceException::notAllowed($value);
            }

            if (is_array($value)) {
                $this->walk($value);
            }
        }
    }

    /**
     * A reference with a scheme leaves the filesystem.
     *
     * Matched on the scheme rather than on a list of protocols: `https` is the
     * one anybody writes, but the parser hands the string to a stream wrapper,
     * and PHP has more of those than a denylist would ever keep up with.
     */
    private static function reachesOverTheNetwork(string $reference): bool
    {
        return preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $reference) === 1;
    }
}

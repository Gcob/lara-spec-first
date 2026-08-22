<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\ReadResult;

/**
 * What RemoteReferenceGuard::resolve() found.
 *
 * `$document` is always safe to hand to the OpenAPI parser, whatever `$faults`
 * holds: a reference this guard could not vendor is never left as the network
 * scheme string it started as, and no rewritten `$ref` ever leads into a
 * vendored file that still holds one, so nothing downstream ever asks
 * `cebe\openapi\` to resolve a URL this package was told not to fetch. See
 * {@see RemoteReferenceGuard} for how that is guaranteed.
 *
 * Safe is not the same as complete, which is what `$neutralized` says.
 */
final readonly class RemoteResolution implements ReadResult
{
    /**
     * @param  array<array-key, mixed>  $document
     * @param  list<SpecException>  $faults
     * @param  bool  $neutralized  whether a refused reference was removed from
     *                             `$document`, which means it describes less
     *                             than the file it was read from does
     */
    public function __construct(
        public array $document,
        public array $faults,
        public bool $neutralized = false,
    ) {}

    public function isClean(): bool
    {
        return $this->faults === [];
    }
}

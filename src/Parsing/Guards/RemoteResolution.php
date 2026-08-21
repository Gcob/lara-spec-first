<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Guards;

use Gcob\LaraSpecFirst\Exceptions\SpecException;

/**
 * What RemoteReferenceGuard::resolve() found.
 *
 * `$document` is always safe to hand to the OpenAPI parser, whatever `$faults`
 * holds: a reference this guard could not vendor is never left as the network
 * scheme string it started as, so nothing downstream ever asks `cebe\openapi\`
 * to resolve a URL this package was told not to fetch. See
 * {@see RemoteReferenceGuard} for how that is guaranteed.
 */
final readonly class RemoteResolution
{
    /**
     * @param  array<array-key, mixed>  $document
     * @param  list<SpecException>  $faults
     */
    public function __construct(
        public array $document,
        public array $faults,
    ) {}
}

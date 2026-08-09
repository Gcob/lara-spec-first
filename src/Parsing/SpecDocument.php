<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

/**
 * A specification file, decoded and vouched for.
 *
 * Holding the version and the strategy beside the decoded document keeps every
 * later step from having to re-derive them, and makes it impossible to hand a
 * document to a strategy that does not match it.
 *
 * Deliberately not a Contract type: this is still the raw shape of somebody's
 * file. Contract types describe the normalized contract, which is what the rest
 * of the package consumes.
 */
final readonly class SpecDocument
{
    /**
     * @param  array<string, mixed>  $data  the decoded document, as written
     */
    public function __construct(
        public string $path,
        public SpecVersion $version,
        public VersionStrategy $strategy,
        public array $data,
    ) {}
}

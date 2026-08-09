<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Parsing\Exceptions\UnsupportedVersionException;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

/**
 * Picks the strategy that interprets a given document.
 *
 * Dispatch happens *after* decoding and never before: the version is a field
 * inside the file, so there is nothing to dispatch on until the bytes have been
 * read. Adding a version means adding a case here and a class beside the two
 * below — not auditing the codebase for version checks.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 */
final class VersionStrategyFactory
{
    /**
     * Select a strategy from a decoded specification document.
     *
     * @param  array<string, mixed>  $document
     *
     * @throws UnsupportedVersionException
     */
    public function forDocument(array $document): VersionStrategy
    {
        return $this->for(SpecVersion::detect($document));
    }

    /**
     * Select a strategy for a version already detected.
     */
    public function for(SpecVersion $version): VersionStrategy
    {
        return match ($version) {
            SpecVersion::V3_0 => new OpenApi30Strategy,
            SpecVersion::V3_1 => new OpenApi31Strategy,
        };
    }
}

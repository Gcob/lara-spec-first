<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\SpecVersion;
use Gcob\LaraSpecFirst\Parsing\VersionStrategy;

/**
 * Interprets OpenAPI 3.1.x.
 */
final class OpenApi31Strategy implements VersionStrategy
{
    /**
     * Root keys an OpenAPI 3.1 document must declare at least one of.
     *
     * 3.1 made `paths` optional, so a document describing only webhooks or only
     * reusable components is valid. It produces zero routes, which is an
     * outcome rather than an error.
     */
    private const REQUIRED_ROOT_KEYS = ['paths', 'webhooks', 'components'];

    public function version(): SpecVersion
    {
        return SpecVersion::V3_1;
    }

    public function assertDocumentShape(array $document): void
    {
        foreach (self::REQUIRED_ROOT_KEYS as $key) {
            if (array_key_exists($key, $document)) {
                return;
            }
        }

        throw InvalidDocumentException::missingRootKey($this->version(), self::REQUIRED_ROOT_KEYS);
    }
}

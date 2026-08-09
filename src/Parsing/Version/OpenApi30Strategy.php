<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;

/**
 * Interprets OpenAPI 3.0.x.
 */
final class OpenApi30Strategy implements VersionStrategy
{
    /**
     * Root keys an OpenAPI 3.0 document must declare.
     *
     * `paths` is required at this version, so a 3.0 document that registers no
     * routes still has to say so with an empty `paths` object.
     */
    private const REQUIRED_ROOT_KEYS = ['paths'];

    public function version(): SpecVersion
    {
        return SpecVersion::V3_0;
    }

    /**
     * @param  array<string, mixed>  $document
     */
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

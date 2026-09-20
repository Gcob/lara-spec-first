<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;

/**
 * Interprets OpenAPI 3.1.x.
 */
final class OpenApi31Strategy implements VersionStrategy
{
    use NormalizesSchemas;

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

    /**
     * `type` is a string or a list of them at 3.1, and `"null"` inside that list
     * is how the version spells nullability.
     *
     * The parser declares this attribute a string and does not enforce it, so a
     * list arrives here unchecked — which is why reading it is our work rather
     * than something that can be assumed done.
     *
     * `nullable` is not read: 3.1 removed it, and a document writing it anyway
     * is writing a 3.0 keyword under a 3.1 header. Honoring it would make this
     * package accept a spelling the version does not have.
     *
     * @param  array<string, mixed>  $keywords
     * @return list<SchemaType>
     */
    protected function types(array $keywords): array
    {
        $written = $keywords['type'] ?? null;

        return $this->knownTypes(is_array($written) ? array_values($written) : [$written]);
    }

    /**
     * At 3.1, `exclusiveMinimum` is a number standing on its own.
     *
     * Already the normal form, so there is nothing to move: a document may
     * write an exclusive bound, an inclusive one, or both, and each is read as
     * written.
     *
     * @param  array<string, mixed>  $keywords
     * @param  'Minimum'|'Maximum'  $side
     * @return array{0: float|null, 1: float|null}
     */
    protected function exclusiveBound(array $keywords, string $side): array
    {
        return [
            $this->number($keywords, 'exclusive'.$side),
            $this->number($keywords, strtolower($side)),
        ];
    }

    /**
     * 3.1 names a file part with `contentMediaType`, having dropped
     * `format: binary` with the rest of the format vocabulary.
     *
     * @param  array<string, mixed>  $keywords
     *
     * @see docs/guide/uploads.md — "The schema names the file part"
     */
    protected function isFilePart(array $keywords): bool
    {
        return is_string($keywords['contentMediaType'] ?? null);
    }
}

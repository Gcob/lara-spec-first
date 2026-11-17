<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing\Version;

use Gcob\LaraSpecFirst\Contract\EncodingDefaults;
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
     * 3.1 made `paths` optional, so a document describing only webhooks or only reusable components is valid. It
     * produces zero routes, which is an outcome rather than an error.
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
     * 3.1.1's table, which 3.1.2 repeats and which a 3.1.0 document is read with: a number, an integer or a boolean is
     * `text/plain`, and so is a `type: string` file part (`contentMediaType` does not move it).
     *
     * **A string holds both types, `format: byte` ones too.** 3.1.1 gives a string `text/plain`, and
     * `application/octet-stream` when it writes a `contentEncoding`, which the schema does not carry yet: until it
     * does, a correct `{type: string, contentEncoding: base64}` part written `application/octet-stream` must not be
     * reported, and nothing tells it from a plain string.
     *
     * TODO (#146): give a string with a `contentEncoding` its own row (`application/octet-stream`), and narrow these
     * two rows to `text/plain`.
     */
    public function encodingDefaults(): EncodingDefaults
    {
        return new EncodingDefaults(
            typedFile: ['text/plain'],
            string: ['text/plain', 'application/octet-stream'],
            byteString: ['text/plain', 'application/octet-stream'],
            scalar: ['text/plain'],
        );
    }

    /**
     * Every key but `$ref`: 2020-12 applies them together with the referenced schema.
     *
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    public function referenceSiblings(array $node): array
    {
        unset($node['$ref']);

        return $node;
    }

    /**
     * `type` is a string or a list of them at 3.1, and `"null"` inside that list is how the version spells nullability.
     *
     * The parser declares this attribute a string and does not enforce it, so a list arrives here unchecked — which is
     * why reading it is our work rather than something that can be assumed done.
     *
     * `nullable` is not read: 3.1 removed it, and a document writing it anyway is writing a 3.0 keyword under a 3.1
     * header. Honoring it would make this package accept a spelling the version does not have.
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
     * Already the normal form, so there is nothing to move: a document may write an exclusive bound, an inclusive one,
     * or both, and each is read as written.
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
     * 3.1 has `contentMediaType`, so it is read here and nowhere else.
     *
     * Kept beside {@see self::isFilePart()} rather than folded into it: the two ask different questions of one keyword.
     * Whether a part is a file is one thing, and which media types it must match is [what `mimetypes:`
     * needs](../../../docs/guide/uploads.md#the-schema-names-the-file-part).
     *
     * @param  array<string, mixed>  $keywords
     */
    protected function contentMediaType(array $keywords): ?string
    {
        $written = $keywords['contentMediaType'] ?? null;

        return is_string($written) && $written !== '' ? $written : null;
    }

    /**
     * `format: binary` left no trace in the normal form at 3.0, and it does not here either, for the same reason: it is
     * how a file part is written, and {@see self::isFilePart()} has already carried it. Kept when a `contentEncoding`
     * says the part is not a file, since then it is a format nothing else reads.
     *
     * @param  array<string, mixed>  $keywords
     */
    protected function format(array $keywords): ?string
    {
        return $this->isFilePart($keywords) ? null : $this->string($keywords, 'format');
    }

    /**
     * 3.1 names a file part with `contentMediaType`, having dropped `format: binary` with the rest of the format
     * vocabulary, and a document that declares 3.1 still writes `format: binary` for an upload: it is what many
     * generators emit whatever version they declare. Both are read, so a part spelled the 3.0 way is not turned into a
     * string every upload refuses.
     *
     * **Not when `contentEncoding` is beside it.** `type: string`, `contentEncoding: base64` and a `contentMediaType`
     * is how 3.1 writes a file carried as text, which is a string with a description of what it decodes to, and `file`
     * would refuse the very text the contract describes. That is `uploads.md`'s "a base64 part is a string", read here
     * at the one place a 3.1 document can say it.
     *
     * @param  array<string, mixed>  $keywords
     *
     * @see docs/guide/uploads.md — "The schema names the file part"
     */
    protected function isFilePart(array $keywords): bool
    {
        return ! is_string($keywords['contentEncoding'] ?? null)
            && (is_string($keywords['contentMediaType'] ?? null) || ($keywords['format'] ?? null) === 'binary');
    }
}

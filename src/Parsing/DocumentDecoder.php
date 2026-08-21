<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Decode YAML or JSON from a file, the one way this package does it.
 *
 * One path for both, because YAML 1.2 is a superset of JSON and the parser
 * accepts either — a branch on the file extension would only add a way for a
 * correctly written document to be rejected for having the wrong suffix.
 *
 * Extracted from `SpecDocumentReader` so vendoring can decode a fetched or
 * already-vendored reference the same way the root document is decoded,
 * rather than a second implementation of the same five checks.
 *
 * @internal Not public API — a step of the read pipeline.
 */
final readonly class DocumentDecoder
{
    /**
     * @return array<string, mixed>
     *
     * @throws UnreadableDocumentException the file is missing, unreadable, malformed, or not a mapping
     */
    public static function decode(string $path): array
    {
        if (! is_file($path)) {
            throw UnreadableDocumentException::missing($path);
        }

        if (! is_readable($path)) {
            throw UnreadableDocumentException::unreadable($path);
        }

        try {
            $decoded = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw UnreadableDocumentException::malformed($path, $e->getMessage());
        }

        // `array_is_list([])` is true, so an empty mapping — `{}` — would other-
        // wise be reported as "not a mapping", sending its author to look for a
        // syntax fault that is not there. An empty document is a mapping; what
        // it lacks is an `openapi` field, and the next step says so precisely.
        if (! is_array($decoded)) {
            throw UnreadableDocumentException::notAMapping($path, get_debug_type($decoded));
        }

        if ($decoded !== [] && array_is_list($decoded)) {
            throw UnreadableDocumentException::notAMapping($path, 'a list');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

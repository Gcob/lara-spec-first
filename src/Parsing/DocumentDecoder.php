<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Decode YAML or JSON from a file, the one way this package does it.
 *
 * One entry point for both, because YAML 1.2 is a superset of JSON and the
 * YAML parser accepts either — a branch on the file extension would only add
 * a way for a correctly written document to be rejected for having the wrong
 * suffix, and a vendored copy's suffix is the upstream author's choice, not
 * this package's.
 *
 * **The YAML parser is skipped when the content is plainly JSON**, sniffed by
 * its first non-whitespace byte the same way `cebe\openapi\`'s own reference
 * resolver already does. That is a different trade for a vendored registry
 * document than for the root specification: the root is small regardless of
 * suffix, but a real schema registry export can be megabytes, and running the
 * slower parser on every one of them for a suffix nobody trusts anyway is a
 * cost paid on every build for no correctness this package needs.
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

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw UnreadableDocumentException::unreadable($path);
        }

        $decoded = self::looksLikeJson($contents)
            ? self::decodeJson($path, $contents)
            : self::decodeYaml($path, $contents);

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

    /**
     * Whether this content should be read as JSON rather than YAML — the same
     * sniff `cebe\openapi\`'s reference resolver uses, so a document this
     * package vendors and one it hands to the parser directly are classified
     * the same way.
     */
    public static function looksLikeJson(string $contents): bool
    {
        return str_starts_with(ltrim($contents), '{');
    }

    /**
     * @throws UnreadableDocumentException
     */
    private static function decodeJson(string $path, string $contents): mixed
    {
        $decoded = json_decode($contents, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw UnreadableDocumentException::malformed($path, json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * @throws UnreadableDocumentException
     */
    private static function decodeYaml(string $path, string $contents): mixed
    {
        try {
            return Yaml::parse($contents);
        } catch (ParseException $e) {
            throw UnreadableDocumentException::malformed($path, $e->getMessage());
        }
    }
}

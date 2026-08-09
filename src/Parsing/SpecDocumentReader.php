<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;
use Gcob\LaraSpecFirst\Parsing\Guards\ReferenceCycleDetector;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategyFactory;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Turns a specification file into a ParsableSpecDocument — a document that has
 * cleared every check which must happen before the OpenAPI parser sees it.
 *
 * The order of the checks is the design, not an implementation detail:
 *
 *   1. decode        — nothing can be decided about bytes
 *   2. detect        — the version is a field inside the file, so dispatch
 *                      cannot happen any earlier
 *   3. shape         — what the version requires at the document root
 *   4. cycles        — before the parser is handed anything, because a pure
 *                      reference cycle exhausts its memory rather than raising
 *
 * Only after all four does anything reach the OpenAPI parser. The guard has to
 * sit here rather than inside a parser wrapper: once cebe has the document, a
 * cyclic one takes the process down and there is no exception left to catch.
 *
 * @see docs/OPENAPI-SUPPORT.md — "Parser caveats"
 */
final readonly class SpecDocumentReader
{
    public function __construct(
        private VersionStrategyFactory $strategies = new VersionStrategyFactory,
        private ReferenceCycleDetector $cycles = new ReferenceCycleDetector,
    ) {}

    /**
     * @throws UnreadableDocumentException the file is missing, unreadable or not a mapping
     * @throws Exceptions\UnsupportedVersionException the document declares a version we do not implement
     * @throws Exceptions\InvalidDocumentException the document lacks what its version requires
     * @throws Exceptions\CyclicReferenceException a reference chain never reaches content
     */
    public function read(string $path): ParsableSpecDocument
    {
        $data = $this->decode($path);
        $strategy = $this->strategies->forDocument($data);

        $strategy->assertDocumentShape($data);
        $this->cycles->assertNoCycles($data);

        return new ParsableSpecDocument($path, $strategy->version(), $strategy, $data);
    }

    /**
     * Decode YAML or JSON.
     *
     * One path for both, because YAML 1.2 is a superset of JSON and the parser
     * accepts either — a branch on the file extension would only add a way for
     * a correctly written document to be rejected for having the wrong suffix.
     *
     * @return array<string, mixed>
     *
     * @throws UnreadableDocumentException
     */
    private function decode(string $path): array
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

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw UnreadableDocumentException::notAMapping($path, get_debug_type($decoded));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

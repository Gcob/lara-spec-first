<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation as ParsedOperation;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\ParserFailedException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Throwable;

/**
 * Turns a read document into the operations of the contract.
 *
 * This is the crossing point. Everything above it works on arrays and our own
 * types; here, and only here, the OpenAPI parser is asked to resolve references
 * — and nothing it returns leaves this class. What comes out is `Contract\`.
 *
 * @internal Not public API — reachable through the reading pipeline.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 * @see docs/internals/contract-artifact.md
 */
final readonly class OperationExtractor
{
    /**
     * @return list<Operation> in the order the document writes them
     *
     * @throws RejectedConstructException the document uses something we refuse to serve
     * @throws InvalidDocumentException two operations address one endpoint
     */
    public function extract(ParsableSpecDocument $document): array
    {
        $this->assertEveryPathItemIsFollowable($document);

        $operations = [];
        $seen = [];
        $index = 0;

        foreach ($this->parse($document)->paths ?? [] as $path => $pathItem) {
            $template = PathTemplate::fromString((string) $path);

            /** @var array<string, ParsedOperation> $parsed */
            $parsed = $pathItem->getOperations();

            foreach ($parsed as $verb => $operation) {
                $method = HttpMethod::tryFrom($verb)
                    ?? throw RejectedConstructException::traceOperation((string) $path);

                $extracted = new Operation($index++, $method, $template, $this->operationId($operation));
                $identity = $extracted->identity();

                if (isset($seen[$identity])) {
                    throw InvalidDocumentException::duplicateEndpoint(
                        $identity,
                        $seen[$identity],
                        $verb.' '.$path
                    );
                }

                $seen[$identity] = $verb.' '.$path;
                $operations[] = $extracted;
            }
        }

        return $operations;
    }

    /**
     * Refuse a Path Item reference the parser will drop instead of resolving.
     *
     * Verified against the vendored parser: a `$ref` into `components.pathItems`
     * resolves to a plain value, leaves the Path Item with no operations, and
     * records no error — the endpoint vanishes without a word. Checked on the
     * raw document because by the time the parser is done the reference is gone
     * and nothing distinguishes this from an empty Path Item.
     *
     * The two other forms are fine and stay supported, which is why this is
     * targeted rather than a blanket refusal of Path Item references.
     *
     * @throws RejectedConstructException
     */
    private function assertEveryPathItemIsFollowable(ParsableSpecDocument $document): void
    {
        $paths = $document->raw['paths'] ?? [];

        if (! is_array($paths)) {
            return;
        }

        foreach ($paths as $path => $pathItem) {
            if (! is_array($pathItem) || ! isset($pathItem['$ref']) || ! is_string($pathItem['$ref'])) {
                continue;
            }

            if (str_starts_with($pathItem['$ref'], '#/components/pathItems/')) {
                throw RejectedConstructException::componentPathItem((string) $path, $pathItem['$ref']);
            }
        }
    }

    /**
     * Hand the already-read document to the parser and resolve its references.
     *
     * Built from the array the pipeline decoded rather than re-read from disk:
     * the file has already been decoded once, and letting the parser read it
     * again would mean the guards ran on one document while the parser worked
     * on whatever it found the second time.
     *
     * The path still matters, because a reference relative to the file can only
     * be resolved against where that file sits.
     */
    private function parse(ParsableSpecDocument $document): OpenApi
    {
        try {
            $parsed = new OpenApi($document->raw);
            $parsed->resolveReferences(new ReferenceContext($parsed, $document->path));
        } catch (Throwable $failure) {
            // Everything `cebe\openapi\` throws extends plain \Exception and
            // implements nothing of ours, so an unwrapped one would travel
            // through a consumer's `catch (SpecException)` untouched. Containing
            // the parser has to cover what it throws, not only what it returns.
            throw ParserFailedException::wrap($document->path, $failure);
        }

        return $parsed;
    }

    /**
     * `operationId` as written, or null.
     *
     * Never derived here: what an unnamed operation should be called is a
     * question about generated code, and answering it in the contract would bake
     * one generator's convention into the thing every generator reads.
     */
    private function operationId(ParsedOperation $operation): ?string
    {
        // The parser's docblock types this as string, but its own reader hands
        // back whatever the document wrote and fills absent attributes with
        // null, so the annotation is a claim rather than a guarantee.
        $id = $operation->operationId;

        return $id === '' ? null : $id;
    }
}

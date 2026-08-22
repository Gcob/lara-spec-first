<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation as ParsedOperation;
use cebe\openapi\spec\SecurityRequirement as ParsedSecurityRequirement;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
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
 * **An operation whose own construct is refused is skipped, not fatal to the
 * rest of the document.** A `trace` operation, a malformed extension, an
 * identity already claimed by an earlier operation — each is collected as a
 * fault and the loop moves on to the next operation, rather than the first one
 * stopping every operation after it. Handing the OpenAPI parser the document
 * at all is still all-or-nothing: if `cebe\openapi\` itself cannot build an
 * object model of the document, there is no per-operation loop to run yet, so
 * that failure is the whole result. See {@see ExtractionResult}.
 *
 * @internal Not public API — reachable through the reading pipeline.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 */
final readonly class OperationExtractor
{
    public function extract(ParsableSpecDocument $document): ExtractionResult
    {
        $faults = $this->unfollowablePathItemFaults($document);

        try {
            $parsed = $this->parse($document);
        } catch (SpecException $fault) {
            // The parser could not build a model of the document at all — there
            // is nothing left to loop over, so this is the whole result.
            return new ExtractionResult([], [...$faults, $fault]);
        }

        $operations = [];
        $seen = [];
        $index = 0;

        foreach ($parsed->paths ?? [] as $path => $pathItem) {
            $template = PathTemplate::fromString((string) $path);

            /** @var array<string, ParsedOperation> $operationsAtPath */
            $operationsAtPath = $pathItem->getOperations();

            foreach ($operationsAtPath as $verb => $operation) {
                $method = HttpMethod::tryFrom($verb);

                if ($method === null) {
                    $faults[] = RejectedConstructException::traceOperation((string) $path);

                    continue;
                }

                $endpoint = $verb.' '.$path;

                try {
                    $extracted = $this->buildOperation($operation, $method, $template, $endpoint, $index);
                } catch (InvalidDocumentException $fault) {
                    $faults[] = $fault;

                    continue;
                }

                $identity = $extracted->identity();

                if (isset($seen[$identity])) {
                    $faults[] = InvalidDocumentException::duplicateEndpoint($identity, $seen[$identity], $endpoint);

                    continue;
                }

                // Only incremented for an operation that actually joins the
                // result: `index` settles which route wins when two match, so
                // it has to describe the order operations are *registered* in,
                // not the order the document happened to attempt them in.
                $index++;
                $seen[$identity] = $endpoint;
                $operations[] = $extracted;
            }
        }

        return new ExtractionResult($operations, $faults);
    }

    /**
     * Every field the document states for one operation, or the first fault
     * that keeps it from being built at all.
     *
     * @throws InvalidDocumentException
     */
    private function buildOperation(
        ParsedOperation $operation,
        HttpMethod $method,
        PathTemplate $template,
        string $endpoint,
        int $index,
    ): Operation {
        $audience = $this->audience($operation, $endpoint);

        return new Operation(
            $index,
            $method,
            $template,
            $this->operationId($operation),
            array_values(array_filter($operation->tags, is_string(...))),
            $audience,
            $this->lifecycle($operation, $audience, $endpoint),
            $operation->deprecated,
            $this->sunset($operation, $endpoint),
            $this->security($operation),
            $this->controller($operation, $endpoint),
        );
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
     * @return list<RejectedConstructException> one per offending path, so a
     *                                          document naming several is
     *                                          reported in one pass
     */
    private function unfollowablePathItemFaults(ParsableSpecDocument $document): array
    {
        $paths = $document->raw['paths'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        $faults = [];

        foreach ($paths as $path => $pathItem) {
            if (! is_array($pathItem) || ! isset($pathItem['$ref']) || ! is_string($pathItem['$ref'])) {
                continue;
            }

            if (str_starts_with($pathItem['$ref'], '#/components/pathItems/')) {
                $faults[] = RejectedConstructException::componentPathItem((string) $path, $pathItem['$ref']);
            }
        }

        return $faults;
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
     *
     * @throws ParserFailedException
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

    /**
     * The custom controller `x-controller` names, as written.
     *
     * **Checked here rather than where the class is generated, because the value
     * is a class name rather than something turned into one.** An `operationId`
     * becomes a class name by a rule this package owns, so a value that cannot
     * survive that rule is a generation refusal; `x-controller` is the name
     * itself, and one that PHP could never carry is a fault in the document —
     * detectable before anything is generated, and reported as what it is.
     *
     * Not resolved, only read: whether a class of that name exists is a question
     * for the build, and the contract says the same thing either way.
     *
     * @throws InvalidDocumentException
     *
     * @see docs/guide/controllers.md — "The specification decides what is customizable"
     */
    private function controller(ParsedOperation $operation, string $endpoint): ?string
    {
        $written = $this->stringExtension($operation, 'x-controller', $endpoint);

        if ($written === null) {
            return null;
        }

        // A leading separator is how PHP itself writes an absolute name, so it is
        // accepted and dropped rather than refused: `\App\…` and `App\…` name one
        // class, and carrying both spellings forward would mean two values that
        // collide without looking alike.
        $normalized = ltrim($written, '\\');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $normalized) !== 1) {
            throw InvalidDocumentException::extensionNotAClassName('x-controller', $written, $endpoint);
        }

        return $normalized;
    }

    /**
     * Who the operation is promised to, resolved rather than as written.
     *
     * @throws InvalidDocumentException
     *
     * @see docs/guide/lifecycle.md — "Two keys, one discriminator"
     */
    private function audience(ParsedOperation $operation, string $endpoint): Audience
    {
        $written = $this->stringExtension($operation, 'x-audience', $endpoint);

        if ($written === null) {
            return Audience::default();
        }

        return Audience::tryFrom($written) ?? throw InvalidDocumentException::unknownExtensionValue(
            'x-audience',
            $written,
            $endpoint,
            Audience::values()
        );
    }

    /**
     * How strong a promise the operation carries, resolved the same way.
     *
     * The audience is passed in rather than read again: it is the discriminator
     * that decides what silence means here.
     *
     * @throws InvalidDocumentException
     */
    private function lifecycle(ParsedOperation $operation, Audience $audience, string $endpoint): ?Lifecycle
    {
        $written = $this->stringExtension($operation, 'x-lifecycle', $endpoint);

        if ($written === null) {
            return $audience->defaultLifecycle();
        }

        return Lifecycle::tryFrom($written) ?? throw InvalidDocumentException::unknownExtensionValue(
            'x-lifecycle',
            $written,
            $endpoint,
            Lifecycle::values()
        );
    }

    /**
     * The date `x-sunset` states, normalized to one spelling and not validated.
     *
     * YAML decodes an unquoted date to a Unix timestamp, so `2026-06-01` and
     * `"2026-06-01"` reach us as an int and as a string while stating one
     * promise. Validating the date itself is a doctor rule, not a reason to
     * refuse a contract.
     *
     * @throws InvalidDocumentException
     *
     * @see docs/guide/lifecycle.md — "The doctor rules that follow"
     */
    private function sunset(ParsedOperation $operation, string $endpoint): ?string
    {
        $value = $operation->getExtensions()['x-sunset'] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $this->asOneSpelling(new DateTimeImmutable('@'.(int) $value));
        }

        if (! is_string($value)) {
            throw InvalidDocumentException::extensionNotAString('x-sunset', get_debug_type($value), $endpoint);
        }

        // Recorded verbatim when it cannot be read as a moment, rather than
        // refused: an unusable date is a doctor finding, and failing the read
        // over one would turn a report into an outage.
        return $this->asMoment($value) ?? $value;
    }

    /**
     * Read a written date, or null when it is not one.
     *
     * Deliberately stricter than PHP's own parsing, which accepts `next tuesday`
     * and would resolve it against the day the build happens to run.
     */
    private function asMoment(string $value): ?string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[Tt ].*)?$/', $value, $written) !== 1) {
            return null;
        }

        try {
            $moment = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }

        // `2026-02-30` parses by rolling into March, and recording the rolled
        // date would answer a claim its author did not make.
        if ($moment->format('Y-m-d') !== $written[1]) {
            return null;
        }

        return $this->asOneSpelling($moment);
    }

    /**
     * The one spelling this package writes for a moment.
     *
     * A date carrying a time is a different promise, so it is not flattened into
     * a plain one.
     */
    private function asOneSpelling(DateTimeImmutable $moment): string
    {
        $utc = $moment->setTimezone(new DateTimeZone('UTC'));

        return $utc->format('H:i:s') === '00:00:00'
            ? $utc->format('Y-m-d')
            : $utc->format(DateTimeInterface::ATOM);
    }

    /**
     * The security requirements as the document states them, in three states:
     * inherited, explicitly none, or a list.
     *
     * @return list<SecurityRequirement>|null
     */
    private function security(ParsedOperation $operation): ?array
    {
        /** @var list<ParsedSecurityRequirement>|null $requirements */
        $requirements = $operation->security;

        if ($requirements === null) {
            return null;
        }

        $normalized = [];

        foreach ($requirements as $requirement) {
            /** @var array<string, mixed> $schemes */
            $schemes = (array) $requirement->getSerializableData();

            $one = [];

            foreach ($schemes as $scheme => $scopes) {
                $one[(string) $scheme] = is_array($scopes)
                    ? array_values(array_filter($scopes, is_string(...)))
                    : [];
            }

            // The requirements themselves are left in the order written.
            $normalized[] = SecurityRequirement::fromSchemes($one);
        }

        return $normalized;
    }

    /**
     * Read an extension this package defines, insisting it is text.
     *
     * @throws InvalidDocumentException
     */
    private function stringExtension(ParsedOperation $operation, string $name, string $endpoint): ?string
    {
        $value = $operation->getExtensions()[$name] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidDocumentException::extensionNotAString($name, get_debug_type($value), $endpoint);
        }

        return $value;
    }
}

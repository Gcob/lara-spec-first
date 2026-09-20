<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use cebe\openapi\json\JsonPointer;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\MediaType as ParsedMediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation as ParsedOperation;
use cebe\openapi\spec\Parameter as ParsedParameter;
use cebe\openapi\spec\RequestBody as ParsedRequestBody;
use cebe\openapi\spec\Schema as ParsedSchema;
use cebe\openapi\spec\SecurityRequirement as ParsedSecurityRequirement;
use cebe\openapi\SpecObjectInterface;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\ParserFailedException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategy;
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
    /**
     * Every schema keyword read into the normal form, flat and in one place.
     *
     * The list is closed on purpose. A keyword the support matrix marks
     * `Ignored` is absent here, so nothing can read it by accident, and the
     * three the parser does not model — `const`, `examples` and
     * `contentMediaType` — are present because they are honored despite coming
     * back as raw values.
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    private const SCHEMA_KEYWORDS = [
        'type',
        'nullable',
        'format',
        'properties',
        'required',
        'items',
        'allOf',
        'enum',
        'const',
        'additionalProperties',
        'minLength',
        'maxLength',
        'pattern',
        'minimum',
        'maximum',
        'exclusiveMinimum',
        'exclusiveMaximum',
        'multipleOf',
        'minItems',
        'maxItems',
        'uniqueItems',
        'readOnly',
        'writeOnly',
        'example',
        'examples',
        'contentMediaType',
    ];

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
                    $extracted = $this->buildOperation(
                        $operation,
                        $method,
                        $template,
                        $endpoint,
                        $index,
                        $document->strategy,
                        $this->listOf($pathItem, 'parameters'),
                    );
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
     * @param  array<array-key, mixed>  $shared  the Path Item's own parameters,
     *                                           which OpenAPI says apply to
     *                                           every operation under it
     *
     * @throws InvalidDocumentException
     */
    private function buildOperation(
        ParsedOperation $operation,
        HttpMethod $method,
        PathTemplate $template,
        string $endpoint,
        int $index,
        VersionStrategy $strategy,
        array $shared,
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
            $this->requestBody($operation, $strategy),
            $this->queryParameters($operation, $strategy, $shared),
        );
    }

    /**
     * What the operation accepts as a body, one schema per media type.
     *
     * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
     */
    private function requestBody(ParsedOperation $operation, VersionStrategy $strategy): ?RequestBody
    {
        $body = $operation->requestBody;

        if (! $body instanceof ParsedRequestBody) {
            return null;
        }

        $content = [];

        foreach ($this->listOf($body, 'content') as $mediaType => $media) {
            if ($media instanceof ParsedMediaType && $media->schema instanceof ParsedSchema) {
                $content[(string) $mediaType] = $this->schema($media->schema, $strategy);
            }
        }

        // A body declaring no readable schema says nothing this package can
        // generate from, and an empty RequestBody would read as "a body with no
        // constraints" rather than as the silence it is.
        return $content === [] ? null : new RequestBody($content, $body->required === true);
    }

    /**
     * The `query` parameters, and only those.
     *
     * A `path` parameter is the router's question, and `header` and `cookie`
     * are an `Ignored` support level: reporting them is the doctor's work, and
     * carrying them here would be offering a value nothing may read.
     *
     * **Path Item parameters merge with the operation's, and the operation wins
     * on a name they share.** That is OpenAPI's own rule rather than one this
     * package invents, which is why it is a merge rather than a collision — and
     * the parser does not apply it, so this is where it happens.
     *
     * @param  array<array-key, mixed>  $shared
     * @return list<QueryParameter>
     */
    private function queryParameters(ParsedOperation $operation, VersionStrategy $strategy, array $shared): array
    {
        $parameters = [];

        // The Path Item's first, so that a name it declares keeps the position
        // it was written at even when the operation redefines it. The override
        // replaces the value and not the order: a reader following the document
        // finds the parameters where the document put them.
        foreach ([...$shared, ...$this->listOf($operation, 'parameters')] as $parameter) {
            if (! $parameter instanceof ParsedParameter || $parameter->in !== 'query') {
                continue;
            }

            $name = $this->parameterName($parameter->name);

            if ($name === null) {
                continue;
            }

            $parameters[$name] = new QueryParameter(
                $name,
                $parameter->schema instanceof ParsedSchema
                    ? $this->schema($parameter->schema, $strategy)
                    : new Schema,
                $parameter->required === true,
            );
        }

        return array_values($parameters);
    }

    /**
     * A parameter's name, or null when the document did not write a usable one.
     *
     * The parser's docblock types `name` as a string, and its own reader hands
     * back whatever the document wrote — the same claim-rather-than-guarantee
     * that {@see self::operationId()} already works around. Taken as `mixed`
     * here so the check is real rather than a formality a static analyser can
     * see through.
     */
    private function parameterName(mixed $written): ?string
    {
        return is_string($written) && $written !== '' ? $written : null;
    }

    /**
     * A keyed node of the parser's model, or nothing when the document wrote
     * something else there.
     *
     * **A document reaching here has been parsed, never validated**, so an
     * attribute the parser declares a list of objects may hold whatever the
     * author wrote — a string, a mapping, a null. The parser records an error
     * and keeps the value; reporting it is
     * [the doctor's work](../../docs/guide/doctor.md), and refusing to read the
     * rest of the contract over it is not this class's call to make.
     *
     * @return array<array-key, mixed>
     */
    private function listOf(SpecObjectInterface $node, string $attribute): array
    {
        $written = $node->$attribute ?? null;

        return is_array($written) ? $written : [];
    }

    /**
     * One schema, walked into the normal form and out of the parser's types.
     *
     * **The walk is this class's and the interpretation is the strategy's**, and
     * the split is not arbitrary: walking means holding `cebe\openapi\` objects,
     * which nothing outside this namespace may do, while deciding what `type` or
     * `exclusiveMinimum` means at a version is exactly what a strategy is for.
     * So each node is flattened here — children first, already normalized — and
     * handed over as a plain keyword map.
     *
     * @param  array<int, true>  $open  the nodes this descent is inside, by
     *                                  object identity. Passed by value rather
     *                                  than held on the instance: two sibling
     *                                  properties may legitimately point at one
     *                                  shared schema, and a set that survived
     *                                  the first of them would report the second
     *                                  as recursion
     */
    private function schema(ParsedSchema $node, VersionStrategy $strategy, array $open = []): Schema
    {
        $identity = spl_object_id($node);

        if (isset($open[$identity])) {
            // A schema pointing back at one of its own ancestors — a tree, a
            // comment thread, nested categories. The contract is supported and
            // the object graph is infinite, so the walk stops here and names
            // where it stopped rather than descending forever.
            return Schema::recursion($this->pointerTo($node));
        }

        $open[$identity] = true;
        $keywords = [];

        foreach (self::SCHEMA_KEYWORDS as $keyword) {
            if (isset($node->$keyword)) {
                $keywords[$keyword] = $node->$keyword;
            }
        }

        foreach (['properties', 'allOf'] as $keyword) {
            if (! is_array($keywords[$keyword] ?? null)) {
                continue;
            }

            $keywords[$keyword] = array_map(
                fn (mixed $child): mixed => $child instanceof ParsedSchema
                    ? $this->schema($child, $strategy, $open)
                    : $child,
                $keywords[$keyword]
            );
        }

        if (($keywords['items'] ?? null) instanceof ParsedSchema) {
            $keywords['items'] = $this->schema($keywords['items'], $strategy, $open);
        }

        return $strategy->normalizeSchema($keywords);
    }

    /**
     * Where a node sits in the document, in this package's one pointer spelling.
     *
     * The parser knows its own position, and a node it cannot place — which is
     * every node of a document built from an array rather than read from a file
     * — gets the empty pointer rather than a made-up one.
     */
    private function pointerTo(ParsedSchema $node): string
    {
        return '#'.($node->getDocumentPosition()?->getPointer() ?? '');
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
            // Built from an array rather than read from a file, so the parser
            // has no document context of its own and every node would answer
            // "I do not know where I am". `Reader` does exactly this before
            // resolving, and without it a schema cannot name its own position —
            // which is what a recursion marker and every pointer in a
            // diagnostic are made of.
            $parsed->setDocumentContext($parsed, new JsonPointer(''));
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
     * @see docs/guide/controllers.md — "The contract decides what is customizable"
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
     * @see docs/guide/lifecycle.md — "The audience sets the default"
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
     * @see docs/guide/lifecycle.md — "What the doctor enforces"
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

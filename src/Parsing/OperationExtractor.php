<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Parsing;

use cebe\openapi\json\JsonPointer;
use cebe\openapi\ReferenceContext;
use cebe\openapi\spec\MediaType as ParsedMediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation as ParsedOperation;
use cebe\openapi\spec\Parameter as ParsedParameter;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\RequestBody as ParsedRequestBody;
use cebe\openapi\spec\Response as ParsedResponse;
use cebe\openapi\spec\Responses as ParsedResponses;
use cebe\openapi\spec\Schema as ParsedSchema;
use cebe\openapi\spec\SecurityRequirement as ParsedSecurityRequirement;
use cebe\openapi\SpecObjectInterface;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SecurityRequirement;
use Gcob\LaraSpecFirst\Exceptions\SpecException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\InvalidDocumentException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\ParserFailedException;
use Gcob\LaraSpecFirst\Parsing\Exceptions\RejectedConstructException;
use Gcob\LaraSpecFirst\Parsing\Version\VersionStrategy;
use Gcob\LaraSpecFirst\Support\DtoShape;
use LogicException;
use Throwable;

/**
 * Turns a read document into the operations of the contract.
 *
 * This is the crossing point. Everything above it works on arrays and our own types; here, and only here, the OpenAPI
 * parser is asked to resolve references — and nothing it returns leaves this class. What comes out is `Contract\`.
 *
 * **An operation whose own construct is refused is skipped, not fatal to the rest of the document.** A `trace`
 * operation, a malformed extension, an identity already claimed by an earlier operation — each is collected as a fault
 * and the loop moves on to the next operation, rather than the first one stopping every operation after it. Handing the
 * OpenAPI parser the document at all is still all-or-nothing: if `cebe\openapi\` itself cannot build an object model of
 * the document, there is no per-operation loop to run yet, so that failure is the whole result. See
 * {@see ExtractionResult}.
 *
 * @internal Not public API — reachable through the reading pipeline.
 *
 * @see SpecDocumentReader for the order of the read pipeline
 */
final readonly class OperationExtractor
{
    /**
     * Keywords a schema may not carry at all, each for its own reason.
     *
     * `$id` rebases how every relative reference under it resolves, and the four dynamic-scope keywords ask for a
     * resolution this package does not do. Both are wrong-resolution risks rather than missing-feature ones, which is
     * what puts them at `Rejected` while their raw neighbours sit at `Ignored`.
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    private const REFUSED_SCHEMA_KEYWORDS = [
        '$id',
        '$dynamicRef',
        '$dynamicAnchor',
        '$recursiveRef',
        '$recursiveAnchor',
    ];

    /**
     * The eleven keywords whose value *is* a schema and which the parser hands back raw, references and all.
     *
     * Ignored while they hold nothing but constraints, and refused the moment one of them holds a `$ref`: the parser
     * leaves that pointer unresolved, so reading it as a schema produces a value that is wrong rather than one that is
     * missing, and nothing downstream fails to say so.
     *
     * `$defs` is not here, and the omission is the matrix's rather than an oversight: it is a container rather than a
     * constraint, so a reference between definitions inside it is how the keyword is used. What is refused is the other
     * direction, a reference aimed *into* one.
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    private const SCHEMA_CARRYING_RAW_KEYWORDS = [
        'prefixItems',
        'contains',
        'unevaluatedItems',
        'patternProperties',
        'propertyNames',
        'dependentSchemas',
        'unevaluatedProperties',
        'contentSchema',
        'if',
        'then',
        'else',
    ];

    /**
     * Keys whose contents are data rather than specification, inside a schema.
     *
     * The same boundary {@see ReferenceCycleDetector} draws, applied one level down: a `$ref` written under one of
     * these is a literal, and an API that itself deals in JSON Schema will have one. Refusing over it would turn away a
     * valid contract, which for a refusal is the worst outcome available.
     *
     * `examples` joins the list here where the cycle detector has to tell two shapes apart by name: inside a schema it
     * is 3.1's list of literal values and never a map of Example Objects, so its position answers the question the name
     * cannot.
     *
     * @see docs/guide/openapi-support.md — "Reading a document"
     */
    private const DATA_KEYS = ['example', 'examples', 'default', 'enum', 'const'];

    /**
     * Every schema keyword read into the normal form, flat and in one place.
     *
     * The list is closed on purpose. A keyword the support matrix marks `Ignored` is absent here, so nothing can read
     * it by accident, and the three the parser does not model — `const`, `examples` and `contentMediaType` — are
     * present because they are honored despite coming back as raw values.
     *
     * `oneOf`, `anyOf` and `not` are the absences worth naming, since a reader checking this list against the matrix
     * will look for them: the parser does model them, so no unresolved pointer can hide in one, and the matrix ignores
     * them because no Laravel rule expresses "exactly one of these shapes".
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    private const SCHEMA_KEYWORDS = [
        'type',
        'nullable',
        'format',
        'properties',
        'required',
        'dependentRequired',
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
        'contentEncoding',
    ];

    /**
     * Every schema keyword the support matrix marks `Ignored`, which is what `spec:doctor` reports per request.
     *
     * The list is closed, and it is the matrix's rows: a keyword absent from here and from {@see self::SCHEMA_KEYWORDS}
     * is `Out of scope`, `Rejected`, or one the matrix has not placed, and none of those is this report's to name.
     *
     * @see docs/guide/openapi-support.md — "Schemas"
     */
    private const IGNORED_SCHEMA_KEYWORDS = [
        'default',
        'deprecated',
        '$defs',
        '$anchor',
        'minProperties',
        'maxProperties',
        'patternProperties',
        'propertyNames',
        'dependentSchemas',
        'unevaluatedProperties',
        'prefixItems',
        'contains',
        'minContains',
        'maxContains',
        'unevaluatedItems',
        'oneOf',
        'anyOf',
        'not',
        'discriminator',
        'if',
        'then',
        'else',
        'contentSchema',
    ];

    public function extract(ParsableSpecDocument $document): ExtractionResult
    {
        $faults = [
            ...$this->unfollowablePathItemFaults($document),
            ...$this->referencesIntoDefinitions($document->raw, '', $document->strategy),
        ];

        try {
            $parsed = $this->parse($document);
        } catch (SpecException $fault) {
            // The parser could not build a model of the document at all — there
            // is nothing left to loop over, so this is the whole result.
            return new ExtractionResult([], [...$faults, $fault]);
        }

        // The parallel descent through the raw document, alongside the one
        // through the parser's resolved graph below. One instance per
        // extraction: it caches every external file it decodes, and nothing
        // about this document may survive into the next.
        $walk = new DocumentWalk($document->path, $document->raw);
        $paths = $walk->child($walk->root(), 'paths');

        $operations = [];
        $seen = [];
        $index = 0;

        foreach ($parsed->paths ?? [] as $path => $pathItem) {
            $template = PathTemplate::fromString((string) $path);
            $pathItemAt = $walk->child($paths, (string) $path);

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
                        $walk,
                        $pathItemAt,
                    );
                } catch (SpecException $fault) {
                    // Widened from InvalidDocumentException when schemas started
                    // being read: a refused construct inside a schema is this
                    // package's limit rather than the document's fault, so it
                    // arrives as a different type and has to be skipped the same
                    // way. Both are one operation failing to join the result,
                    // and neither is a reason to stop reading the ones after it.
                    //
                    // **Caught by the interface rather than by the two types
                    // that reach it today, and the constraint that comes with
                    // that is worth stating.** Narrowing it would let a third
                    // type escape the pipeline, which
                    // {@see ReadOutcome} promises never happens. So anything
                    // thrown from buildOperation() lands in a report, and
                    // {@see \Gcob\LaraSpecFirst\Doctor\FaultClassification::sectionOf()}
                    // has to know its class: it raises rather than guessing, so
                    // a new exception type thrown from here needs a row there
                    // in the same change.
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
     * Every field the document states for one operation, or the first fault that keeps it from being built at all.
     *
     * @param  array<array-key, mixed>  $shared  the Path Item's own parameters, which OpenAPI says apply to every
     *                                           operation under it
     * @param  SchemaPosition  $pathItemAt  where the Path Item is written, which is not always under `/paths` in the
     *                                      root document: a Path Item may itself be a reference into another file
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
        DocumentWalk $walk,
        SchemaPosition $pathItemAt,
    ): Operation {
        $audience = $this->audience($operation, $endpoint);
        $at = $walk->child($pathItemAt, $method->value);

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
            $this->requestBody($operation, $strategy, $walk, $at),
            $this->queryParameters($operation, $strategy, $shared, $walk, $at, $pathItemAt),
            $this->responses($operation, $strategy, $walk, $at),
        );
    }

    /**
     * What the operation accepts as a body, one schema per media type.
     *
     * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
     */
    private function requestBody(
        ParsedOperation $operation,
        VersionStrategy $strategy,
        DocumentWalk $walk,
        SchemaPosition $at,
    ): ?RequestBody {
        $body = $operation->requestBody;

        if (! $body instanceof ParsedRequestBody) {
            return null;
        }

        $content = [];
        $encoding = [];
        $contentAt = $walk->child($walk->child($at, 'requestBody'), 'content');

        foreach ($this->listOf($body, 'content') as $mediaType => $media) {
            if ($media instanceof ParsedMediaType && $media->schema instanceof ParsedSchema) {
                $mediaAt = $walk->child($contentAt, (string) $mediaType);
                $content[(string) $mediaType] = $this->schemaAt(
                    $media->schema,
                    $strategy,
                    $walk,
                    $mediaAt,
                    'schema',
                );

                $contentTypes = $this->encodedContentTypes($walk, $mediaAt);

                if ($contentTypes !== []) {
                    $encoding[(string) $mediaType] = $contentTypes;
                }
            }
        }

        // A body declaring no readable schema says nothing this package can
        // generate from, and an empty RequestBody would read as "a body with no
        // constraints" rather than as the silence it is.
        return $content === [] ? null : new RequestBody($content, $body->required === true, $encoding);
    }

    /**
     * The `contentType` each part of a media type writes under `encoding`, as written.
     *
     * **Read off the raw document, not off the parser's node.** The parser fills in a `contentType` the author never
     * wrote (`application/octet-stream` for a binary, `text/plain` for a primitive, `application/json` for an object),
     * and only when the schema is inline: reading it would put values in the contract that the document does not hold,
     * and read one body two ways depending on whether it sits behind a `$ref`.
     *
     * **A value that is not a string is kept as written.** The specification says one comma-separated string, and a
     * YAML list is the natural mistake. Dropping it here would make the part look as if it wrote no `contentType`, and
     * the request would say nothing about a restriction the author wrote. Only `null` is dropped: a key with nothing in
     * it writes none.
     *
     * The Encoding Object is the same in 3.0 and 3.1, so no {@see VersionStrategy} method is involved.
     *
     * @return array<string, mixed>
     */
    private function encodedContentTypes(DocumentWalk $walk, SchemaPosition $mediaAt): array
    {
        // Built, not walked to: a part named `$ref` is a part, and following it would read somewhere else.
        $raw = $walk->rawAt(new SchemaPosition($mediaAt->file, $mediaAt->pointer.'/encoding')) ?? [];
        $contentTypes = [];

        foreach ($raw as $part => $entry) {
            if (is_array($entry) && ($entry['contentType'] ?? null) !== null) {
                $contentTypes[(string) $part] = $entry['contentType'];
            }
        }

        return $contentTypes;
    }

    /**
     * Every response the operation declares, keyed by status code as written.
     *
     * **A response with no readable schema keeps its entry.** A `204` is a promise the contract makes, and dropping it
     * for want of a schema would leave nothing able to tell "answers with no body" from "never declared".
     *
     * The keys are not interpreted: `200`, the 3.1 range `2XX` and `default` arrive as the strings the document wrote.
     * Which one answers a given request is a question about serving a response, and settling it here would bake one
     * reader's rule into what every reader consults.
     *
     * @return list<Response>
     *
     * @throws RejectedConstructException
     */
    private function responses(
        ParsedOperation $operation,
        VersionStrategy $strategy,
        DocumentWalk $walk,
        SchemaPosition $at,
    ): array {
        $declared = $operation->responses;

        // The one attribute of an Operation the parser wraps in an object of
        // its own rather than handing back as a list, so the generic reader
        // beside this one cannot see into it. Worth the special case: silently
        // finding nothing here is exactly the failure this package refuses.
        //
        // **What the empty list means when it is reached, said out loud because
        // the shape is ambiguous:** an operation whose `responses` the author
        // wrote as a string or a list comes back indistinguishable from one
        // that declared none. It stays a silence rather than becoming a fault
        // because the document is invalid OpenAPI either way, and saying so is
        // [the doctor's work](../../docs/guide/doctor.md) rather than a reason
        // to refuse the contract. What this class refuses is a value that is
        // *wrong*; an absent response is one that is missing, and nothing
        // generates from it yet.
        if (! $declared instanceof ParsedResponses) {
            return [];
        }

        $responses = [];
        $responsesAt = $walk->child($at, 'responses');

        foreach ($declared->getResponses() as $status => $response) {
            if (! $response instanceof ParsedResponse) {
                continue;
            }

            $content = [];
            $contentAt = $walk->child($walk->child($responsesAt, (string) $status), 'content');

            foreach ($this->listOf($response, 'content') as $mediaType => $media) {
                if ($media instanceof ParsedMediaType && $media->schema instanceof ParsedSchema) {
                    $content[(string) $mediaType] = $this->schemaAt(
                        $media->schema,
                        $strategy,
                        $walk,
                        $walk->child($contentAt, (string) $mediaType),
                        'schema',
                    );
                }
            }

            $responses[] = new Response((string) $status, $content);
        }

        return $responses;
    }

    /**
     * The `query` parameters, and only those.
     *
     * A `path` parameter is the router's question, and `header` and `cookie` are an `Ignored` support level: reporting
     * them is the doctor's work, and carrying them here would be offering a value nothing may read.
     *
     * **Path Item parameters merge with the operation's, and the operation wins on a name they share.** That is
     * OpenAPI's own rule rather than one this package invents, which is why it is a merge rather than a collision — and
     * the parser does not apply it, so this is where it happens.
     *
     * @param  array<array-key, mixed>  $shared
     * @return list<QueryParameter>
     */
    private function queryParameters(
        ParsedOperation $operation,
        VersionStrategy $strategy,
        array $shared,
        DocumentWalk $walk,
        SchemaPosition $at,
        SchemaPosition $pathItemAt,
    ): array {
        $parameters = [];

        // The Path Item's first, so that a name it declares keeps the position
        // it was written at even when the operation redefines it. The override
        // replaces the value and not the order: a reader following the document
        // finds the parameters where the document put them.
        //
        // Walked as two lists rather than one concatenation, which the raw
        // position is what forces: the two are written at different places in
        // the document, and a merged list has lost which one each came from.
        $declared = [
            [$shared, $walk->child($pathItemAt, 'parameters')],
            [$this->listOf($operation, 'parameters'), $walk->child($at, 'parameters')],
        ];

        foreach ($declared as [$list, $listAt]) {
            foreach ($list as $index => $parameter) {
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
                        ? $this->schemaAt(
                            $parameter->schema,
                            $strategy,
                            $walk,
                            $walk->child($listAt, (string) $index),
                            'schema',
                        )
                        : new Schema,
                    $parameter->required === true,
                );
            }
        }

        return array_values($parameters);
    }

    /**
     * A parameter's name, or null when the document did not write a usable one.
     *
     * The parser's docblock types `name` as a string, and its own reader hands back whatever the document wrote — the
     * same claim-rather-than-guarantee that {@see self::operationId()} already works around. Taken as `mixed` here so
     * the check is real rather than a formality a static analyser can see through.
     */
    private function parameterName(mixed $written): ?string
    {
        return is_string($written) && $written !== '' ? $written : null;
    }

    /**
     * A keyed node of the parser's model, or nothing when the document wrote something else there.
     *
     * **A document reaching here has been parsed, never validated**, so an attribute the parser declares a list of
     * objects may hold whatever the author wrote — a string, a mapping, a null. The parser records an error and keeps
     * the value; reporting it is [the doctor's work](../../docs/guide/doctor.md), and refusing to read the rest of the
     * contract over it is not this class's call to make.
     *
     * @return array<array-key, mixed>
     */
    private function listOf(SpecObjectInterface $node, string $attribute): array
    {
        $written = $node->$attribute ?? null;

        return is_array($written) ? $written : [];
    }

    /**
     * One schema, entered through the key its parent writes it under.
     *
     * Every schema the extractor builds comes in here, so there is one place that holds, for each of them, the position
     * where the author wrote it (a `$ref`, if there is one) before the references are followed to the position that
     * holds its content. {@see self::schema()} reads the node from the second. The first is what a keyword written
     * beside a `$ref` hangs off, at 3.1: each hop of the chain, from the innermost out, wraps what is inside it
     * ({@see self::besideReference()}).
     *
     * **A hop whose keywords are being built is an ancestor of everything under them.**
     * `Alias: {$ref: Node, properties: {p: {$ref: Alias}}}` reaches `Alias` again from inside its own keywords, and the
     * content it points at is not open at that moment, so only the hop can tell the walk it is going round.
     *
     * **A node that is a `Reference` is one the parser was never asked to resolve**: it is written inside the keywords
     * beside another `$ref`, which the parser dropped, or inside a schema read from the raw document for the same
     * reason. It is followed here, through the walk that knows where each hop was written, and its content is built
     * from the document the way the parser would have built it ({@see self::target()}).
     *
     * @param  ParsedSchema|Reference  $node  the parser's schema at the written position, or the reference it is
     *                                        written as when it was never resolved
     * @param  SchemaPosition  $parent  the position of the node whose key holds the schema, already resolved
     * @param  array<string, array{0: string, 1: string|null}>  $open  see {@see self::schema()}
     *
     * @throws RejectedConstructException
     * @throws InvalidDocumentException
     */
    private function schemaAt(
        ParsedSchema|Reference $node,
        VersionStrategy $strategy,
        DocumentWalk $walk,
        SchemaPosition $parent,
        string|int $segment,
        array $open = [],
    ): Schema {
        $hops = $walk->chain($walk->written($parent, $segment));
        $content = array_pop($hops);

        // Why: only the hops are tested here. The content position is tested, and opened, by `schema()` itself, which
        // is also where its marker is built. A hop has no `schema()` of its own until `besideReference()` builds its
        // keywords, so this is the only place that can tell the walk it is going round through one.
        foreach ($hops as $hop) {
            if (isset($open[$hop->key()])) {
                return Schema::recursion(...$open[$hop->key()]);
            }
        }

        if ($node instanceof Reference) {
            $node = $this->target($walk, $hops === [] ? $content : $hops[array_key_last($hops)], $content);
        }

        $schema = $this->schema($node, $strategy, $walk, $content, $open);

        $entries = array_map(
            static fn (SchemaPosition $hop): array => [$walk->pointer($hop), $walk->name($hop)],
            $hops,
        );

        [$wrapped, $carries, $built] = $this->wrapHops($schema, $strategy, $walk, $hops, $open, $entries);

        // Why: a marker for an outer hop that carries nothing has no ancestor to end on, and everything below it would
        // go unvalidated. Which hops carry something is only known once their keywords are built, and a marker is made
        // while they are, so the build is repeated once with each such hop standing for the nearest one inside it.
        $innermostBuilt = $built === [] ? 0 : max($built);
        $carriesNothing = array_filter($carries, static fn (bool $carried, int $index): bool => ! $carried && $index < $innermostBuilt, ARRAY_FILTER_USE_BOTH);

        if ($carriesNothing === []) {
            return $wrapped;
        }

        $inside = [$walk->pointer($content), $walk->name($content)];

        foreach ($carries as $index => $carried) {
            $entries[$index] = $carried ? $entries[$index] : $inside;
            $inside = $entries[$index];
        }

        return $this->wrapHops($schema, $strategy, $walk, $hops, $open, $entries)[0];
    }

    /**
     * The schema inside the hops, wrapped in the keywords written beside each `$ref`, from the innermost hop out.
     *
     * Every hop outside the one being built is open to its keywords, under the entry given for it: a `$ref` back at an
     * outer hop, written in an inner hop's keywords, ends on that outer hop, which is the schema that carries the outer
     * keywords. Open only the inner one and the marker would end on it, and the outer keywords would be lost at every
     * level.
     *
     * @param  non-empty-list<SchemaPosition>|list<SchemaPosition>  $hops  outermost first
     * @param  array<string, array{0: string, 1: string|null}>  $open  see {@see self::schema()}
     * @param  array<int, array{0: string, 1: string|null}>  $entries  what a marker for each hop reports
     * @return array{0: Schema, 1: array<int, bool>, 2: list<int>} the schema, whether each hop's wrapper carries the
     *                                                             hop's own position (innermost hop first), and the
     *                                                             hops whose keywords were built
     *
     * @throws RejectedConstructException
     * @throws InvalidDocumentException
     */
    private function wrapHops(
        Schema $schema,
        VersionStrategy $strategy,
        DocumentWalk $walk,
        array $hops,
        array $open,
        array $entries,
    ): array {
        $carries = [];
        $built = [];

        foreach (array_reverse(array_keys($hops)) as $index) {
            $outside = $open;

            foreach (array_slice($hops, 0, $index, preserve_keys: true) as $outer => $hop) {
                $outside[$hop->key()] = $entries[$outer];
            }

            $wrapped = $this->besideReference($schema, $strategy, $walk, $hops[$index], $outside);

            $carries[$index] = $wrapped->source === $walk->pointer($hops[$index]);

            if ($wrapped !== $schema) {
                $built[] = $index;
            }

            $schema = $wrapped;
        }

        return [$schema, $carries, $built];
    }

    /**
     * The schema a `$ref` that the parser never resolved points at, built from the raw document.
     *
     * Built the way the parser builds one, so everything after this reads it as it reads any other. What it holds of
     * its own `$ref`s is a `Reference` again, and they are followed by {@see self::schemaAt()} in turn, one level at a
     * time, which is why a target two levels down has its rules.
     *
     * **A target that is not there is the document's fault, never an empty schema.** The same goes for a chain the walk
     * stopped following because it came back to a position it had seen, which leaves a `$ref` where content should be.
     *
     * @param  SchemaPosition  $last  the position the last `$ref` of the chain is written at
     * @param  SchemaPosition  $content  where the chain ends
     *
     * @throws InvalidDocumentException
     */
    private function target(DocumentWalk $walk, SchemaPosition $last, SchemaPosition $content): ParsedSchema
    {
        $reference = (string) ($walk->rawAt($last)['$ref'] ?? '');
        $raw = $walk->rawAt($content);

        if ($raw === null) {
            throw InvalidDocumentException::referenceTargetMissing($walk->pointer($last), $reference);
        }

        if (isset($raw['$ref'])) {
            throw InvalidDocumentException::referenceNeverReachesSchema($walk->pointer($last), $reference);
        }

        return $this->parsed($raw, $walk->pointer($content), behindReference: true);
    }

    /**
     * A schema built from keys the parser did not build, which can fail in a way the pipeline does not expect.
     *
     * cebe's `TypeErrorException` extends `\Exception` and not {@see SpecException}, so one thrown here would pass the
     * catch of {@see self::extract()} and crash the read, against the pipeline's promise that it never throws. Every
     * schema built from the raw document goes through here.
     *
     * @param  array<array-key, mixed>  $written
     * @param  string  $pointer  where the keys are written
     * @param  bool  $behindReference  the keys are the target of a `$ref`, and not the ones written beside one
     *
     * @throws InvalidDocumentException
     */
    private function parsed(array $written, string $pointer, bool $behindReference = false): ParsedSchema
    {
        try {
            return new ParsedSchema($written);
        } catch (Exception $fault) {
            throw $behindReference
                ? InvalidDocumentException::malformedSchemaBehindReference($pointer, $fault->getMessage())
                : InvalidDocumentException::malformedKeywordBesideReference($pointer, $fault->getMessage());
        }
    }

    /**
     * A schema together with the keywords written beside the `$ref` that leads to it.
     *
     * **Two tests decide what comes back, and neither is a policy.** What the keywords add, built and set aside where
     * they were written: when it is nothing a generator reads (an annotation, a description, an `Ignored` keyword) the
     * answer is the target itself, with the positions of the keywords it ignored added to a copy, so a field that only
     * documents itself stays the class it was. Otherwise it is one `allOf` wrapper, the target first and the keywords'
     * own branches after it, which is how 3.0 writes the same thing and what the generators already read. A keyword
     * written both here and on the target gets a wrapper too, so each keeps its own position: a schema carries one
     * position per keyword.
     *
     * **The wrapper takes this hop's name only when what it adds is shape a DTO holds**
     * ({@see DtoShape::isAddedByKeywordsBeside()}, the planner's own question). `Alias: {$ref: Node, maxLength: 3}` is
     * still `Node` to a generated class, and `Alias: {$ref: Node, required: [id]}` is a schema of its own, which the
     * planner could not tell from `Node` by position.
     *
     * @param  array<string, array{0: string, 1: string|null}>  $open  see {@see self::schema()}
     *
     * @throws RejectedConstructException
     * @throws InvalidDocumentException
     */
    private function besideReference(
        Schema $target,
        VersionStrategy $strategy,
        DocumentWalk $walk,
        SchemaPosition $hop,
        array $open,
    ): Schema {
        $written = $walk->rawAt($hop);
        $siblings = $written === null ? [] : $strategy->referenceSiblings($written);

        if ($siblings === []) {
            return $target;
        }

        $pointer = $walk->pointer($hop);

        // Built from the raw keys: the parser dropped them with the `$ref`, so there is no object of its own to read.
        // A `$ref` written inside them is a `Reference` here, and `schemaAt()` follows it.
        $beside = $this->schema($this->parsed($siblings, $pointer), $strategy, $walk, $hop, $open, beside: true);

        if ($beside->shapeKey() === (new Schema)->shapeKey()) {
            if ($beside->ignored === []) {
                return $target;
            }

            if (array_intersect_key($beside->ignored, $target->ignored) === []) {
                return new Schema(...[...get_object_vars($target), 'ignored' => [...$target->ignored, ...$beside->ignored]]);
            }
        }

        $name = DtoShape::isAddedByKeywordsBeside($beside) ? $walk->name($hop) : null;

        return new Schema(...[
            ...get_object_vars($beside),
            'name' => $name,
            'source' => $name === null ? null : $pointer,
            'allOf' => [$target, ...$beside->allOf],
        ]);
    }

    /**
     * One schema, walked into the normal form and out of the parser's types.
     *
     * **The walk is this class's and the interpretation is the strategy's**, and the split is not arbitrary: walking
     * means holding `cebe\openapi\` objects, which nothing outside this namespace may do, while deciding what `type` or
     * `exclusiveMinimum` means at a version is exactly what a strategy is for. So each node is flattened here —
     * children first, already normalized — and handed over as a plain keyword map.
     *
     * @param  SchemaPosition  $position  where this node is written, computed by the raw walk rather than asked of the
     *                                    parser — see {@see DocumentWalk}
     * @param  array<string, array{0: string, 1: string|null}>  $open  the nodes this descent is inside, each keyed both
     *                                                                 by written position and by object identity — two
     *                                                                 keys for one node, see the body — and holding the
     *                                                                 position and name a recursion marker reports.
     *                                                                 Passed by value rather than held on the instance:
     *                                                                 two sibling properties may legitimately point at
     *                                                                 one shared schema, and a set that survived the
     *                                                                 first of them would report the second as
     *                                                                 recursion
     * @param  bool  $beside  `$node` is the keywords written beside the `$ref` at `$position`, built as a schema of
     *                        their own. The position is the one the `$ref` is written at, so it is open to what is
     *                        under it and is not an ancestor of itself. It carries no name and no source, which belong
     *                        to the wrapper, and no `x-max-depth`: a marker reads the depth of the schema it points
     *                        back at, so one written here would be read by nothing and is reported instead
     *
     * @throws RejectedConstructException
     * @throws InvalidDocumentException
     */
    private function schema(
        ParsedSchema $node,
        VersionStrategy $strategy,
        DocumentWalk $walk,
        SchemaPosition $position,
        array $open = [],
        bool $beside = false,
    ): Schema {
        // Two keys for one node, and the pair is the point. The position is
        // what cuts at the right level, since the parser hands back a *copy* of
        // an externally resolved schema and object identity never repeats for
        // one. Object identity is kept beside it as a floor: were the raw walk
        // ever unable to follow a `$ref` the parser did follow, positions would
        // stop repeating too, and an unbounded descent is a hang rather than a
        // message. Neither can fire before the document actually repeats
        // something, so keeping both cannot cut early.
        $keys = [$position->key(), 'object:'.spl_object_id($node)];
        $ancestor = $beside ? null : $open[$keys[0]] ?? $open[$keys[1]] ?? null;

        if ($ancestor !== null) {
            // A schema pointing back at one of its own ancestors — a tree, a
            // comment thread, nested categories. The contract is supported and
            // the object graph is infinite, so the walk stops here and names
            // where it stopped rather than descending forever.
            //
            // **Keyed by written position rather than by object identity**, and
            // that is the fix rather than a preference: the parser hands back a
            // *copy* of a schema resolved out of another file, so the first node
            // and the one it points back at are two objects and the walk only
            // notices one level too late. Two visits to one `(file, pointer)`
            // are the same definition however many copies of it exist.
            // The ancestor's own position, taken from the set rather than
            // recomputed from this node: the two are the same position when
            // the position key matched, and only the stored one is right when
            // the object-identity floor is what fired.
            return Schema::recursion(...$ancestor);
        }

        $this->assertSchemaIsServable($node, $walk->pointer($position));

        $raw = $walk->rawAt($position);

        // Refused on every node, at both versions: the parser reads `allOf: {$ref: X}` as a reference to a schema and
        // not as a list, and what it hands back is not a list of branches, so the composition would vanish unsaid.
        if (is_array($raw['allOf'] ?? null) && ! array_is_list($raw['allOf'])) {
            throw InvalidDocumentException::allOfNotAList($walk->pointer($position));
        }

        $name = $beside ? null : $walk->name($position);
        $open[$keys[0]] = $open[$keys[1]] = [$walk->pointer($position), $walk->name($position)];
        $keywords = [];

        // **Presence is read from the keys the document actually wrote, not
        // from `isset()`**, and the difference is a value rather than a
        // nicety: `isset()` is false for a keyword written as `null`, so
        // `const: null` — a valid JSON Schema saying "this must be null" —
        // would arrive indistinguishable from a schema that constrains
        // nothing. `getSerializableData()` returns what was written, defaults
        // excluded, which is also what makes an absent `additionalProperties`
        // say nothing instead of claiming the parser's `true`.
        $written = (array) $node->getSerializableData();

        foreach (self::SCHEMA_KEYWORDS as $keyword) {
            if (! array_key_exists($keyword, $written)) {
                continue;
            }

            // Read back through the parser rather than taken from the array
            // above, because that copy has already turned every nested schema
            // into plain data and the walk below needs the objects. The
            // exception is the value this whole branch exists for: the parser's
            // own accessor throws for a key it finds only among the written
            // ones, which happens exactly when that key was written as `null`,
            // so `isset()` being false here *is* the null.
            $keywords[$keyword] = isset($node->$keyword) ? $node->$keyword : null;

            // `enum`, `example` and `const` are data, and a key spelled `$ref` inside one is part of the value. The
            // parser turns it into a reference on a node built from raw keys (beside a `$ref`, or behind one written in
            // the keywords beside another) and resolves it into a schema on a node it reads itself, so all three are
            // read from the document wherever the raw walk finds the node. Where it finds none (a URL written by hand
            // in a local file, which only the parser followed) the parser's value stays, so no `enum` loses its rule.
            if (in_array($keyword, ['enum', 'example', 'const'], true) && array_key_exists($keyword, $raw ?? [])) {
                $keywords[$keyword] = $raw[$keyword];
            }
        }

        $depth = $beside ? null : $node->getExtensions()['x-max-depth'] ?? null;

        if ($depth !== null) {
            if (! is_int($depth) || $depth < 0) {
                throw InvalidDocumentException::extensionNotADepth('x-max-depth', $depth, $walk->pointer($position));
            }

            $keywords['x-max-depth'] = $depth;
        }

        foreach (['properties', 'allOf'] as $keyword) {
            if (! is_array($keywords[$keyword] ?? null)) {
                continue;
            }

            $keywordAt = $walk->child($position, $keyword);
            $walked = [];

            // A loop rather than `array_map`, because the key is now part of
            // the work: it names the step the raw walk takes beside this one,
            // and the two-array form of `array_map` renumbers what it returns —
            // which would rename every property of every object schema.
            foreach ($keywords[$keyword] as $key => $child) {
                $walked[$key] = $child instanceof ParsedSchema || $child instanceof Reference
                    ? $this->schemaAt($child, $strategy, $walk, $keywordAt, $key, $open)
                    : $child;
            }

            $keywords[$keyword] = $walked;
        }

        if (($keywords['items'] ?? null) instanceof ParsedSchema || ($keywords['items'] ?? null) instanceof Reference) {
            $keywords['items'] = $this->schemaAt($keywords['items'], $strategy, $walk, $position, 'items', $open);
        }

        // The value schema of a map (`Schema::$additionalPropertiesSchema`) is a child like `items`: a `$ref` in it resolves,
        // and a recursion closing through it ends in a marker.
        if (($keywords['additionalProperties'] ?? null) instanceof ParsedSchema || ($keywords['additionalProperties'] ?? null) instanceof Reference) {
            $keywords['additionalProperties'] = $this->schemaAt(
                $keywords['additionalProperties'],
                $strategy,
                $walk,
                $position,
                'additionalProperties',
                $open,
            );
        }

        $this->assertNoReferenceLeft($keywords);

        return $strategy->normalizeSchema(
            $keywords,
            $name,
            $name === null ? null : $walk->pointer($position),
            $this->ignoredKeywords($walk, $position, $beside),
        );
    }

    /**
     * A reference the walk was meant to follow and did not.
     *
     * `normalizeSchema()` filters what is not a schema out of `properties`, `items`, `allOf` and `additionalProperties`
     * without a word, so a `Reference` left in one would be a field that validates against nothing.
     * {@see self::schemaAt()} follows every one of them, and no document reaches this: it is the line between a bug
     * here and a silent hole in a contract.
     *
     * @param  array<string, mixed>  $keywords
     *
     * @throws LogicException
     */
    private function assertNoReferenceLeft(array $keywords): void
    {
        foreach (['properties', 'allOf'] as $keyword) {
            foreach (is_array($keywords[$keyword] ?? null) ? $keywords[$keyword] : [] as $child) {
                if ($child instanceof Reference) {
                    throw new LogicException("A reference under `{$keyword}` was not followed.");
                }
            }
        }

        foreach (['items', 'additionalProperties'] as $keyword) {
            if (($keywords[$keyword] ?? null) instanceof Reference) {
                throw new LogicException("A reference under `{$keyword}` was not followed.");
            }
        }
    }

    /**
     * The `Ignored` keywords written at one schema, each with the position of the keyword itself.
     *
     * **Read off the raw document, not off the parser's node.** The parser models some of these (`oneOf`, `default`)
     * and hands back others as raw values, and one rule covering both is a read of what was written, which is also how
     * {@see self::schema()} tells a keyword written as `null` from one not written.
     *
     * **A keyword's own position is built, not walked to.** {@see DocumentWalk::child()} follows a `$ref`, and a
     * `default` holding `{$ref: ...}` is data: following it would report the keyword at a place the author never wrote
     * it.
     *
     * **Beside a `$ref`, `x-max-depth` is one of them.** It is honored on the schema a marker points back at, and a
     * depth written beside the `$ref` is read by nothing, which is a thing to say rather than to drop (D2).
     *
     * @param  bool  $beside  `$position` is where a `$ref` is written, and the keywords are the ones beside it
     * @return array<string, string>
     */
    private function ignoredKeywords(DocumentWalk $walk, SchemaPosition $position, bool $beside = false): array
    {
        $raw = $walk->rawAt($position) ?? [];
        $ignored = [];

        foreach (self::IGNORED_SCHEMA_KEYWORDS as $keyword) {
            if (! array_key_exists($keyword, $raw)) {
                continue;
            }

            $ignored[$keyword] = $walk->pointer(new SchemaPosition(
                $position->file,
                $position->pointer.'/'.DocumentPointer::escape($keyword),
            ));
        }

        if ($beside && array_key_exists('x-max-depth', $raw)) {
            $ignored['x-max-depth'] = $walk->pointer(new SchemaPosition(
                $position->file,
                $position->pointer.'/x-max-depth',
            ));
        }

        return $ignored;
    }

    /**
     * Refuse a schema this package would otherwise read wrongly.
     *
     * **Every refusal here is about a wrong value rather than a missing one**, which is the line the support matrix
     * draws between `Rejected` and `Ignored`. A keyword that simply goes unread costs a feature; one of these would
     * produce a schema that looks complete and is not, and nothing later fails to announce it.
     *
     * Checked per node during the walk rather than once over the raw document, which has one consequence worth stating:
     * a schema under `components.schemas` that no operation reaches is never examined. That is the right scope. A
     * definition nothing references is not part of the contract this package serves, and refusing a document over it
     * would be refusing something nobody reads.
     *
     * @throws RejectedConstructException
     */
    private function assertSchemaIsServable(ParsedSchema $node, string $pointer): void
    {
        foreach (self::REFUSED_SCHEMA_KEYWORDS as $keyword) {
            if (! isset($node->$keyword)) {
                continue;
            }

            throw $keyword === '$id'
                ? RejectedConstructException::rebasedSchemaIdentifier($pointer)
                : RejectedConstructException::dynamicReference($keyword, $pointer);
        }

        foreach (self::SCHEMA_CARRYING_RAW_KEYWORDS as $keyword) {
            if (! isset($node->$keyword)) {
                continue;
            }

            $target = $this->referenceInside($node->$keyword);

            if ($target !== null) {
                throw RejectedConstructException::unresolvedReferenceInSchemaKeyword(
                    $keyword,
                    $pointer,
                    $target
                );
            }
        }
    }

    /**
     * The first reference written anywhere inside a raw keyword's value, or null when it holds none.
     *
     * The first rather than all of them on purpose: one is enough to refuse the construct, and a document naming
     * several has one problem rather than several. Data positions are stepped over, so the literal `$ref` an API about
     * JSON Schema writes in an `example` is left alone.
     */
    private function referenceInside(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        if (isset($value['$ref']) && is_string($value['$ref'])) {
            return $value['$ref'];
        }

        foreach ($value as $key => $child) {
            if (in_array((string) $key, self::DATA_KEYS, true)) {
                continue;
            }

            $found = $this->referenceInside($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Refuse a Path Item reference the parser will drop instead of resolving.
     *
     * Verified against the vendored parser: a `$ref` into `components.pathItems` resolves to a plain value, leaves the
     * Path Item with no operations, and records no error — the endpoint vanishes without a word. Checked on the raw
     * document because by the time the parser is done the reference is gone and nothing distinguishes this from an
     * empty Path Item.
     *
     * The two other forms are fine and stay supported, which is why this is targeted rather than a blanket refusal of
     * Path Item references.
     *
     * @return list<RejectedConstructException> one per offending path, so a document naming several is reported in one
     *                                          pass
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
     * Refuse every reference aimed at a position inside a `$defs`.
     *
     * **Read on the raw document, for the same reason the check above is**: by the time the parser is done the
     * reference is gone, and so is the property that carried it. Nothing distinguishes what is left from a schema that
     * never declared the property.
     *
     * Document-wide rather than per schema node, which is the opposite scope from {@see self::assertSchemaIsServable()}
     * and deliberately so: that one refuses a *schema* this package would misread, so it only has to look at the
     * schemas the contract actually reaches, while this one refuses a *reference*, and a reference is written wherever
     * its author put it.
     *
     * **What is written beside a `$ref` is walked at 3.1 and not at 3.0**, the same line the cycle check draws: it is
     * read at 3.1, so a `$ref` among its keywords is a reference this package follows, and a `$ref` into a `$defs`
     * there is as silent a loss as anywhere else.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<RejectedConstructException> one per offending reference, so a document writing several is reported
     *                                          in one pass
     */
    private function referencesIntoDefinitions(array $node, string $pointer, VersionStrategy $strategy): array
    {
        $faults = [];

        if (isset($node['$ref'])) {
            if (is_string($node['$ref']) && $this->aimsIntoDefinitions($node['$ref'])) {
                $faults[] = RejectedConstructException::referenceIntoDefinitions('#'.$pointer, $node['$ref']);
            }

            // A Reference Object carries `summary` and `description` beside it, and neither holds a target. A Schema
            // Object at 3.1 carries keywords, and they may.
            $node = $strategy->referenceSiblings($node);
        }

        foreach ($node as $key => $child) {
            // A `$ref` under one of these is a literal, which is the boundary
            // ReferenceCycleDetector draws and the reason a valid contract about
            // JSON Schema is not refused here.
            if (! is_array($child) || in_array((string) $key, self::DATA_KEYS, true)) {
                continue;
            }

            $faults = [
                ...$faults,
                ...$this->referencesIntoDefinitions($child, $pointer.'/'.DocumentPointer::escape((string) $key), $strategy),
            ];
        }

        return $faults;
    }

    /**
     * Whether a reference points at a position inside a `$defs`.
     *
     * **Read on the fragment only, never on the whole reference.** A file path may legitimately contain a `$defs`
     * segment, and `schemas/$defs/thing.yaml#/Foo` points at `Foo` in a file whose directory happens to be named that.
     * Refusing it would name a reason that is not the one, which is a worse failure than not refusing at all: the
     * author is told to move a definition that is not where the message says it is.
     *
     * **The container counts as much as what is inside it**, so the test is two clauses rather than one.
     * `#/…/Money/$defs` resolves to a plain array exactly as `#/…/Money/$defs/Amount` does, and it fails the same
     * silent way: verified end to end, the read comes back with no fault and the property holding the reference simply
     * gone. A refusal that missed the container would be one that misses the shape it advertises.
     *
     * **Known and accepted: a segment literally named `$defs` is read as the keyword wherever it sits.** A component
     * called `$defs`, or a property of that name, is refused although neither is the keyword. Telling them apart means
     * knowing where a pointer lands in the document, which is a walk this check would have to grow to do, for an input
     * nobody writes. The message names the pointer, so an author who meets it can see what was read and rename.
     */
    private function aimsIntoDefinitions(string $reference): bool
    {
        $fragment = strstr($reference, '#');

        if ($fragment === false) {
            return false;
        }

        return str_contains($fragment, '/$defs/') || str_ends_with($fragment, '/$defs');
    }

    /**
     * Hand the already-read document to the parser and resolve its references.
     *
     * Built from the array the pipeline decoded rather than re-read from disk: the file has already been decoded once,
     * and letting the parser read it again would mean the guards ran on one document while the parser worked on
     * whatever it found the second time.
     *
     * The path still matters, because a reference relative to the file can only be resolved against where that file
     * sits.
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
     * Never derived here: what an unnamed operation should be called is a question about generated code, and answering
     * it in the contract would bake one generator's convention into the thing every generator reads.
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
     * **Checked here rather than where the class is generated, because the value is a class name rather than something
     * turned into one.** An `operationId` becomes a class name by a rule this package owns, so a value that cannot
     * survive that rule is a generation refusal; `x-controller` is the name itself, and one that PHP could never carry
     * is a fault in the document — detectable before anything is generated, and reported as what it is.
     *
     * Not resolved, only read: whether a class of that name exists is a question for the build, and the contract says
     * the same thing either way.
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
     * The audience is passed in rather than read again: it is the discriminator that decides what silence means here.
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
     * YAML decodes an unquoted date to a Unix timestamp, so `2026-06-01` and `"2026-06-01"` reach us as an int and as a
     * string while stating one promise. Validating the date itself is a doctor rule, not a reason to refuse a contract.
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
     * Deliberately stricter than PHP's own parsing, which accepts `next tuesday` and would resolve it against the day
     * the build happens to run.
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
     * A date carrying a time is a different promise, so it is not flattened into a plain one.
     */
    private function asOneSpelling(DateTimeImmutable $moment): string
    {
        $utc = $moment->setTimezone(new DateTimeZone('UTC'));

        return $utc->format('H:i:s') === '00:00:00'
            ? $utc->format('Y-m-d')
            : $utc->format(DateTimeInterface::ATOM);
    }

    /**
     * The security requirements as the document states them, in three states: inherited, explicitly none, or a list.
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

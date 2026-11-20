<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Doctor\Checks;

use Gcob\LaraSpecFirst\Contract\Audience;
use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\Lifecycle;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Doctor\FaultClassification;
use Gcob\LaraSpecFirst\Doctor\Finding;
use Gcob\LaraSpecFirst\Doctor\FindingClass;
use Gcob\LaraSpecFirst\Doctor\SupportLevel;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\InputDtoType;
use Gcob\LaraSpecFirst\Generation\PlannedResponseDto;
use Gcob\LaraSpecFirst\Generation\ResponseDtoPlanner;
use Gcob\LaraSpecFirst\Generation\ResponsePlan;
use Gcob\LaraSpecFirst\Generation\RuleSet;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Generation\RuleSetOptions;
use Gcob\LaraSpecFirst\Generation\UntypedResponseCause;
use Gcob\LaraSpecFirst\Parsing\ParsableSpecDocument;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;

/**
 * Every `Partial`, `Ignored` and `Rejected` construct in the document, with its position. Each method answers one kind:
 *
 *   1. **Rejected, already detected by the reading pipeline.** `trace`, a `$ref` into `components.pathItems`, a
 *      `$dynamicRef`/`$dynamicAnchor`. Already collected as faults tagged for this section by
 *      {@see FaultClassification::sectionOf()}, so there is nothing left to detect, only to surface. See
 *      {@see self::rejected()}.
 *   2. **Partial, `operationId`.** Required on an operation that is both `public` and `stable`. See
 *      {@see self::missingOperationId()}.
 *   3. **Partial and Ignored, in a request schema and in a response schema.** What the body and the `query` parameters
 *      write and the generated request does not enforce, what a response writes that its DTO cannot type, and the
 *      properties a response DTO types `mixed`, one finding per keyword (or per cause) per operation. See
 *      {@see self::schemas()}.
 *   4. **Ignored, one line per construct rather than per occurrence**, a count and not a list, for what a count can
 *      say: the `header` and `cookie` parameters. See {@see self::unreadParameters()}.
 *
 * `Open` constructs are not reported: the package has taken no position on them yet, so there is nothing to honor or
 * refuse that a finding could point at. A finding a reader cannot act on is what doctor.md calls a rumor.
 *
 * @see docs/guide/doctor.md — "What it checks"
 * @see docs/guide/openapi-support.md — "The support matrix"
 */
final readonly class SupportMatrixCheck
{
    private const SECTION = 'Support findings';

    /**
     * The section of docs/guide/openapi-support.md a keyword the rule set left unenforced is rated in, when it is not a
     * schema keyword: `encoding` belongs to the request body, not to its schema.
     */
    private const UNENFORCED_SECTIONS = [
        'encoding' => 'Parameters, bodies, responses',
    ];

    /**
     * The keywords the doctor names in a response schema: the ones that change the type of a value and that a response
     * DTO cannot say.
     *
     * **Every other keyword the reading marks `Ignored` stays silent in a response.** `default`, `deprecated` and the
     * other annotations change nothing a DTO holds, and a value constraint (`minProperties`, `contains`,
     * `propertyNames` and the rest) is a check on the response's values, which nothing here makes and a contract test
     * is for. A keyword the doctor named there would be a line its author could not act on.
     */
    private const RESPONSE_KEYWORDS = [
        'oneOf',
        'anyOf',
        'not',
        'if',
        'then',
        'else',
        'discriminator',
        'patternProperties',
        'prefixItems',
        'unevaluatedProperties',
        'unevaluatedItems',
        'dependentSchemas',
    ];

    /**
     * Constructs the package reads and will not act on, ever.
     *
     * `header` and `cookie` parameters are the whole list. They were counted beside `query` under one `Deferred` label
     * until a parameter first became a rule, because an `Ignored` finding needs its non-zero exit only once there is
     * behavior to be absent from — and now there is. What a validator would answer for a missing credential is a 401
     * and not a 422, so this is a position rather than a gap.
     *
     * @see docs/guide/code-generation/request-validation.md — "One rule set, body and query"
     */
    private const IGNORED_LABELS = [
        'parameters' => 'header or cookie parameter declaration(s)',
    ];

    /**
     * The verbs a Path Item Object may carry an Operation Object under. Everything else at that level — `parameters`,
     * `summary`, `$ref`, `servers` — belongs to the path rather than to one operation.
     */
    private const VERBS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * @return list<Finding>
     */
    public static function rejected(ReadOutcome $outcome): array
    {
        $findings = [];

        foreach ($outcome->faults as $fault) {
            if (FaultClassification::sectionOf($fault) === self::SECTION) {
                $findings[] = FaultClassification::fromFault($fault);
            }
        }

        return $findings;
    }

    /**
     * `operationId` is `Partial`: honored when present, and required once an operation promises `public` and `stable`
     * at once — elsewhere the method and path stand in for it. Nothing in the reading pipeline refuses its absence
     * today, so this is genuinely new logic rather than a translated fault, unlike {@see self::rejected()}.
     *
     * @param  list<Operation>  $operations
     * @return list<Finding>
     */
    public static function missingOperationId(array $operations): array
    {
        $findings = [];

        foreach ($operations as $operation) {
            if (
                $operation->operationId === null
                && $operation->audience === Audience::Public
                && $operation->lifecycle === Lifecycle::Stable
            ) {
                $findings[] = new Finding(
                    FindingClass::DocumentFault,
                    self::SECTION,
                    SupportLevel::Partial,
                    // This section knows the position exactly — the operation
                    // it is reading is right here — so doctor.md's "every
                    // finding names the document position" is answerable in
                    // structure rather than only in the prose of the message.
                    // Built through {@see DocumentPointer} rather than
                    // assembled here, so this pointer and the one the
                    // generated file's source map carries are the same string
                    // for the same operation.
                    DocumentPointer::forOperation($operation),
                    sprintf(
                        '%s promises public and stable but declares no operationId. That promise is the one '.
                        'case this package requires one for — elsewhere the method and path stand in for it.',
                        $operation->label(),
                    ),
                );
            }
        }

        return $findings;
    }

    /**
     * What each operation's schemas write and the build does not read: the request's `Ignored` keywords and the part of
     * a `Partial` keyword a rule set leaves out, and the keywords a response DTO does not type.
     *
     * **One finding per keyword and per operation**, never per occurrence. A `default` on every property of a body is
     * one line that lists each position, and its pointer is the first of them: the structured field names one place,
     * the message names the rest. A keyword written in the request and in a response of one operation is still one
     * finding, listing every position with the request's first, and saying what each direction does with it. A
     * `Partial` keyword has no position the contract states beyond the field it is written on, so its message lists the
     * fields and its pointer is the operation's, as {@see self::missingOperationId()}'s is. Its level is `Partial` for
     * all of them, which is the level of the gap rather than of the keyword: the matrix rates `enum` as honored, and an
     * `enum` on an object is a field it was not read for. Taking each keyword's level from the matrix would need the
     * matrix in code, and nothing but this report would read it, so the message says it instead.
     *
     * **The body and the `query` parameters, and the JSON schemas of each response that got a DTO.** A status with no
     * DTO is left out: its schema is not read into any type, so a keyword in it is not one a DTO leaves behind. The
     * response walk reads each operation's own schemas, and a keyword of a component carries the component's position,
     * so every operation that reaches the component names it. Only the keywords of {@see self::RESPONSE_KEYWORDS} are
     * named there.
     *
     * **The response half comes from the plan the build made, and never from a second planning.** With no plan (the
     * planner refused the document, or the generated tree's settings were refused) the response keywords are left out
     * and the request half stands: `Routing outcome` already says why, and a plan is not guessed.
     *
     * **A property a response DTO types `mixed` is a finding too**, one per cause and per operation, under every
     * operation whose response reaches the DTO that holds it: the plan records each, and the DTO is shared by every
     * operation that returns its schema or holds it in a property. See {@see self::untypedValues()}.
     *
     * **A package limit, always.** The contract is correct and this package does not enforce it, so these exit `2` and
     * never `1`. That is a gate a first run on a published contract will trip, and docs/guide/openapi-support.md says
     * why it is a position rather than a gap.
     *
     * **The `Ignored` half does not need a rule set, and the `Partial` half does.** The keywords were collected where
     * the schema was read, so they are reportable for an operation the build plan would refuse. The rule set is built
     * here rather than taken from the build plan, which may not exist: when it cannot be built the operation's conflict
     * is `Routing outcome`'s to report, and what a rule set would have left unenforced is left out rather than guessed.
     *
     * @param  list<Operation>  $operations
     * @param  ResponsePlan|null  $responses  what the build made of the responses, null when there is no build plan
     * @param  RuleSetOptions  $options  the configuration's reading of the input, which the rule sets are built with
     * @return list<Finding>
     */
    public static function schemas(array $operations, ?ResponsePlan $responses, RuleSetOptions $options): array
    {
        $findings = [];

        foreach ($operations as $operation) {
            array_push(
                $findings,
                ...self::operationFindings(
                    $operation,
                    $responses === null ? [] : self::responseKeywords($operation, $responses),
                    $responses === null ? [] : self::untypedValues($operation, $responses),
                    $options,
                ),
            );
        }

        return $findings;
    }

    /**
     * What each operation's request writes and the generated request does not enforce, and nothing about a response.
     *
     * The request half of {@see self::schemas()}, and what it says is unchanged by the response half.
     *
     * @param  list<Operation>  $operations
     * @return list<Finding>
     */
    public static function requestSchemas(array $operations, RuleSetOptions $options): array
    {
        return self::schemas($operations, null, $options);
    }

    /**
     * @param  array<string, list<string>>  $responseKeywords  each keyword the operation's responses write, by position
     * @param  list<Finding>  $untyped  what the DTOs of its responses type `mixed`, which goes after the rest
     * @return list<Finding>
     */
    private static function operationFindings(Operation $operation, array $responseKeywords, array $untyped, RuleSetOptions $options): array
    {
        $findings = [];
        $request = self::ignoredKeywords($operation);
        $keywords = $request;

        foreach ($responseKeywords as $keyword => $positions) {
            foreach ($positions as $position) {
                if (! in_array($position, $keywords[$keyword] ?? [], true)) {
                    $keywords[$keyword][] = $position;
                }
            }
        }

        foreach ($keywords as $keyword => $positions) {
            $findings[] = new Finding(
                FindingClass::PackageLimit,
                self::SECTION,
                SupportLevel::Ignored,
                $positions[0],
                match (true) {
                    isset($request[$keyword], $responseKeywords[$keyword]) => sprintf(
                        '%s writes `%s` at %s, in its request and in its responses. This package reads past it: the '.
                        'generated request enforces nothing for it and the response DTO reads past it. See '.
                        'docs/guide/openapi-support.md, "Schemas". This is a fact about this package, not a defect in '.
                        'your contract.',
                        $operation->label(),
                        $keyword,
                        implode(', ', $positions),
                    ),
                    isset($responseKeywords[$keyword]) => sprintf(
                        '%s writes `%s` at %s, in its responses. The response DTO reads past it. See '.
                        'docs/guide/openapi-support.md, "Schemas". This is a fact about this package, not a defect in '.
                        'your contract.',
                        $operation->label(),
                        $keyword,
                        implode(', ', $positions),
                    ),
                    $keyword === 'x-max-depth' => sprintf(
                        '%s writes `x-max-depth` at %s, beside a `$ref`. The depth of a recursive schema is read '.
                        'from the schema a recursion points back at, so one written beside the `$ref` is read by '.
                        'nothing: write it on the recursive schema itself. See docs/guide/openapi-support.md, '.
                        '"Schemas". This is a fact about this package, not a defect in your contract.',
                        $operation->label(),
                        implode(', ', $positions),
                    ),
                    default => sprintf(
                        '%s writes `%s` at %s. This package reads past it and the generated request enforces nothing '.
                        'for it. See docs/guide/openapi-support.md, "Schemas". This is a fact about this package, '.
                        'not a defect in your contract.',
                        $operation->label(),
                        $keyword,
                        implode(', ', $positions),
                    ),
                },
            );
        }

        $ruleSet = self::ruleSet($operation, $options);

        // A fault in the document the rule set could read nothing from, and so is looser than the contract for:
        // the author's to fix, which is what separates it from the package limits around it.
        foreach ($ruleSet->documentFaults ?? [] as $fault) {
            $findings[] = new Finding(
                FindingClass::DocumentFault,
                self::SECTION,
                SupportLevel::Partial,
                DocumentPointer::forOperation($operation),
                sprintf('%s: %s', $operation->label(), $fault),
            );
        }

        foreach ($ruleSet->unenforced ?? [] as $keyword => $fields) {
            $findings[] = new Finding(
                FindingClass::PackageLimit,
                self::SECTION,
                SupportLevel::Partial,
                DocumentPointer::forOperation($operation),
                sprintf(
                    '%s writes `%s` where the generated request enforces nothing for it: %s. The support matrix '.
                    'may rate `%s` as honored in general, and on these fields it is left out of the rules. See '.
                    'docs/guide/openapi-support.md, "%s". This is a fact about this package, not a defect '.
                    'in your contract.',
                    $operation->label(),
                    $keyword,
                    implode(', ', $fields),
                    $keyword,
                    self::UNENFORCED_SECTIONS[$keyword] ?? 'Schemas',
                ),
            );
        }

        return [...$findings, ...$untyped];
    }

    /**
     * What the DTOs the operation's responses reach type `mixed`, or do not carry, for what the contract says: one
     * `Partial` finding per cause, listing every position, in the order the plan met them.
     *
     * **Reached** is every DTO of a status that got one, and every DTO a property of a reached DTO holds, directly, as
     * a list's items or as a map's values. **A value that writes a keyword the doctor names is left out**: that
     * keyword's finding says it, and a second one for the property would be the same line twice. One that writes only
     * an annotation, or nothing, is named. A schema at fault is not here at all: it is a fallback, which the doctor
     * never reads.
     *
     * @return list<Finding>
     */
    private static function untypedValues(Operation $operation, ResponsePlan $responses): array
    {
        $dtos = [];

        foreach ($responses->dtos as $dto) {
            $dtos[$dto->shortName] = $dto;
        }

        $reached = [];

        foreach ($responses->statuses[$operation->label()] ?? [] as $status) {
            if ($status->dto !== null) {
                self::reachDto($status->dto, $dtos, $reached);
            }
        }

        $positions = [];

        foreach ($responses->untyped as $value) {
            if (! isset($reached[$value->owner]) || array_intersect_key($value->keywords, array_flip(self::RESPONSE_KEYWORDS)) !== []) {
                continue;
            }

            if (! in_array($value->position, $positions[$value->cause->name]['positions'] ?? [], true)) {
                $positions[$value->cause->name]['positions'][] = $value->position;
            }

            $positions[$value->cause->name]['cause'] = $value->cause;
        }

        $findings = [];

        foreach ($positions as $found) {
            $findings[] = new Finding(
                FindingClass::PackageLimit,
                self::SECTION,
                SupportLevel::Partial,
                $found['positions'][0],
                sprintf(
                    '%s answers with a DTO that %s. See docs/guide/code-generation/response-dtos.md, "What the build '.
                    'writes for a response". This is a fact about this package, not a defect in your contract.',
                    $operation->label(),
                    self::untypedSentence($found['cause'], implode(', ', $found['positions'])),
                ),
            );
        }

        return $findings;
    }

    /**
     * What a DTO does with a value, and why, for one cause.
     */
    private static function untypedSentence(UntypedResponseCause $cause, string $positions): string
    {
        return match ($cause) {
            UntypedResponseCause::NoSingleType => sprintf(
                'types `mixed` what is written at %s, because it states no single type (a union of types, no type at '.
                'all, or only `null`). State one type to have it typed',
                $positions,
            ),
            UntypedResponseCause::UnbuiltRecursion => sprintf(
                'types `mixed` what is written at %s, because it points back at a schema that is not a single named '.
                'schema, so there is no class to build it into. Give the schema it points back at a component name to '.
                'have it typed',
                $positions,
            ),
            UntypedResponseCause::PropertiesBesideReference => sprintf(
                'types `mixed` what is written at %s, because it writes properties beside a reference to a schema it '.
                'points back at, and the class of that schema does not carry them. Move the properties into that '.
                'schema, or drop them',
                $positions,
            ),
            UntypedResponseCause::AncestorNotAnObject => sprintf(
                'types `mixed` what is written at %s, because it points back at a schema that is not an object with '.
                'properties, so there is no class to read it into',
                $positions,
            ),
            UntypedResponseCause::ValuesBesideProperties => sprintf(
                'does not carry the values `additionalProperties` types at %s, because it carries only the declared '.
                'properties beside them. Declare the keys you want typed, or leave `additionalProperties` out',
                $positions,
            ),
        };
    }

    /**
     * @param  array<string, PlannedResponseDto>  $dtos
     * @param  array<string, true>  $reached
     */
    private static function reachDto(string $class, array $dtos, array &$reached): void
    {
        if (isset($reached[$class]) || ! isset($dtos[$class])) {
            return;
        }

        $reached[$class] = true;

        foreach ($dtos[$class]->properties as $property) {
            self::reachType($property->type, $dtos, $reached);
        }
    }

    /**
     * @param  array<string, PlannedResponseDto>  $dtos
     * @param  array<string, true>  $reached
     */
    private static function reachType(InputDtoType $type, array $dtos, array &$reached): void
    {
        if ($type->kind === InputDtoType::DTO && $type->class !== null) {
            self::reachDto($type->class, $dtos, $reached);
        }

        if ($type->item !== null) {
            self::reachType($type->item, $dtos, $reached);
        }
    }

    /**
     * Each keyword of {@see self::RESPONSE_KEYWORDS} the operation's responses write, by every position it is written
     * at, in the order a walk of the responses meets them: the schemas of the statuses that got a DTO, in each of their
     * JSON media types.
     *
     * @return array<string, list<string>>
     */
    private static function responseKeywords(Operation $operation, ResponsePlan $responses): array
    {
        $statuses = $responses->statuses[$operation->label()] ?? [];
        $found = [];

        foreach ($operation->responses as $index => $response) {
            if (($statuses[$index] ?? null)?->dto === null) {
                continue;
            }

            foreach (ResponseDtoPlanner::jsonSchemas($response) as [, $schema]) {
                self::collectIgnored($schema, $found);
            }
        }

        return array_intersect_key($found, array_flip(self::RESPONSE_KEYWORDS));
    }

    /**
     * Each `Ignored` keyword one operation's request writes, by every position it is written at, in the order a walk of
     * the body and then the `query` parameters meets them.
     *
     * @return array<string, list<string>>
     */
    private static function ignoredKeywords(Operation $operation): array
    {
        $schemas = [];

        if ($operation->requestBody !== null) {
            foreach ($operation->requestBody->content as $mediaType => $schema) {
                if (in_array($mediaType, RuleSetBuilder::READ_MEDIA_TYPES, true)) {
                    $schemas[] = $schema;
                }
            }
        }

        foreach ($operation->queryParameters as $parameter) {
            $schemas[] = $parameter->schema;
        }

        $found = [];

        foreach ($schemas as $schema) {
            self::collectIgnored($schema, $found);
        }

        return $found;
    }

    /**
     * A schema's own ignored keywords, then its children's: properties, an array's items, `allOf` branches and a map's
     * value schema, which is every place the reading walks into. A recursion marker has nothing under it, and a
     * position is listed once.
     *
     * @param  array<string, list<string>>  $found
     */
    private static function collectIgnored(Schema $schema, array &$found): void
    {
        foreach ($schema->ignored as $keyword => $position) {
            if (! in_array($position, $found[$keyword] ?? [], true)) {
                $found[$keyword][] = $position;
            }
        }

        foreach ([
            ...array_values($schema->properties),
            ...($schema->items === null ? [] : [$schema->items]),
            ...$schema->allOf,
            ...($schema->additionalPropertiesSchema === null ? [] : [$schema->additionalPropertiesSchema]),
        ] as $child) {
            self::collectIgnored($child, $found);
        }
    }

    /**
     * The operation's rule set, or null when it cannot be built: its conflict is `Routing outcome`'s to report, and
     * what a rule set would have said is left out rather than guessed.
     */
    private static function ruleSet(Operation $operation, RuleSetOptions $options): ?RuleSet
    {
        try {
            return RuleSetBuilder::for($operation, $options);
        } catch (ConflictingInputException) {
            return null;
        }
    }

    /**
     * One line for the `header` and `cookie` parameters, naming how many, never one per occurrence: this package reads
     * them and will not turn one into a rule. A document whose parameter nodes are malformed is counted for what is
     * readable.
     *
     * **A response is not counted here any more.** It was the one `Deferred` count until the doctor named the keywords
     * a response DTO does not type, and {@see self::schemas()} says those per operation. No construct is `Deferred` in
     * a document today, which is why the method says what it counts rather than a level.
     *
     * **No pointer, and that is a property of the finding rather than a gap.** A count spans every position that
     * contributed to it; naming one of them would point a reader at an arbitrary occurrence and imply the other
     * nineteen are elsewhere. `{@see self::missingOperationId()}` and `RoutingOutcomeCheck`'s shadowing findings each
     * name one position and carry one — see docs/guide/doctor.md for what the report promises per finding.
     *
     * **Every node is read as `mixed` and checked.** This walks the raw document, which means a document the parser
     * already faulted on is still walked: `parameters: oops` is a real thing a person writes, and this section running
     * after Document validity has to survive it and say what it can, not take the process down with a `foreach` over a
     * string. The fault is already in the report by the time this runs.
     *
     * @return list<Finding>
     */
    public static function unreadParameters(?ParsableSpecDocument $document): array
    {
        if ($document === null) {
            return [];
        }

        $unread = self::countUnreadParameters($document->raw);

        if ($unread === 0) {
            return [];
        }

        return [new Finding(
            FindingClass::PackageLimit,
            self::SECTION,
            SupportLevel::Ignored,
            '',
            sprintf(
                '%d %s found. This package reads them and will not turn one into a rule: what a missing '.
                'credential or an unreadable media type deserves is a 401 or a 415, and a rule set answers '.
                'with a 422. See https://github.com/Gcob/lara-spec-first#roadmap.',
                $unread,
                self::IGNORED_LABELS['parameters'],
            ),
        )];
    }

    /**
     * Every `header` and `cookie` parameter the document declares, from both levels OpenAPI allows one at.
     *
     * **A Path Item's own `parameters` count.** OpenAPI lets a path declare parameters shared by every operation under
     * it, and a specification that puts all of them there — a perfectly ordinary style — used to report zero, which is
     * rule 2 defeated for a construct this package genuinely does not honor. Counted once per path rather than once per
     * operation beneath it, because one declaration is what is written and what a reader would count.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private static function countUnreadParameters(array $raw): int
    {
        $count = 0;

        foreach (self::pathItems($raw) as $pathItem) {
            foreach (self::parameterNodes($pathItem) as $parameter) {
                if (self::isUnreadParameter($parameter, $raw)) {
                    $count++;
                }
            }

            foreach (self::VERBS as $verb) {
                if (is_array($pathItem[$verb] ?? null)) {
                    foreach (self::parameterNodes($pathItem[$verb]) as $parameter) {
                        if (self::isUnreadParameter($parameter, $raw)) {
                            $count++;
                        }
                    }
                }
            }
        }

        return $count;
    }

    /**
     * The entries of one node's `parameters`, or nothing at all when it does not hold a list.
     *
     * @param  array<array-key, mixed>  $node
     * @return list<mixed>
     */
    private static function parameterNodes(array $node): array
    {
        $parameters = $node['parameters'] ?? null;

        return is_array($parameters) ? array_values($parameters) : [];
    }

    /**
     * Whether one entry of a `parameters` list is a parameter this package reads and will not act on: a `header` or a
     * `cookie`.
     *
     * Named rather than defined by exclusion. "Not a path parameter" was the right rule while nothing read a parameter
     * at all; now `query` becomes a rule, so the two locations that stay unread are the two this names.
     *
     * **A `$ref`'d parameter is resolved, not assumed.** `{$ref: '#/components/parameters/UserId'}` carries no `in` of
     * its own, so reading `in` off the reference counted a referenced *path* parameter toward the non-path total.
     * Resolved against the same document, which is where a local pointer points; a reference this method cannot follow
     * — into another file, or at a position the document does not hold — is skipped rather than guessed at, on the same
     * rule the rest of this class follows: a number that might be wrong is worse than a number that is short by a case
     * it says it cannot see.
     *
     * @param  array<array-key, mixed>  $raw
     */
    private static function isUnreadParameter(mixed $parameter, array $raw): bool
    {
        if (! is_array($parameter)) {
            return false;
        }

        $reference = $parameter['$ref'] ?? null;

        if (is_string($reference)) {
            $resolved = self::resolveLocalPointer($raw, $reference);

            if ($resolved === null) {
                return false;
            }

            $parameter = $resolved;
        }

        $in = $parameter['in'] ?? null;

        // Matched against the two locations rather than against everything
        // that is not `path`: a Parameter Object with no `in` at all, or with
        // one nobody recognizes, is a document the parser will refuse, and
        // counting it would put a wrong number on the one line whose whole
        // content is a number.
        return $in === 'header' || $in === 'cookie';
    }

    /**
     * The node a `#/...` JSON Pointer names inside this same document, or null for anything this method cannot follow —
     * a reference into another file, a pointer at a position the document does not hold, or a target that is not a
     * node.
     *
     * Deliberately not a general `$ref` resolver: `Parsing\` owns resolution, and this is one lookup a count needs
     * rather than a second resolver for the package to keep in step with the first.
     *
     * The walk is this method's own; the *spelling* of a segment is not, and comes from
     * {@see DocumentPointer::unescape()}. That split is the one `DocumentPointer` exists to enforce — a pointer is an
     * identity, so a second escaping of `~` and `/` would be a second answer waiting to disagree with the first — and
     * it costs this method nothing to honor.
     *
     * @param  array<array-key, mixed>  $raw
     * @return ?array<array-key, mixed>
     */
    private static function resolveLocalPointer(array $raw, string $reference): ?array
    {
        if (! str_starts_with($reference, '#/')) {
            return null;
        }

        $node = $raw;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            $key = DocumentPointer::unescape($segment);

            if (! array_key_exists($key, $node)) {
                return null;
            }

            /** @var mixed $next */
            $next = $node[$key];

            if (! is_array($next)) {
                return null;
            }

            $node = $next;
        }

        return $node;
    }

    /**
     * Every Path Item Object in the document, or nothing when `paths` is not a map of them.
     *
     * @param  array<array-key, mixed>  $raw
     * @return list<array<array-key, mixed>>
     */
    private static function pathItems(array $raw): array
    {
        $paths = $raw['paths'] ?? null;

        if (! is_array($paths)) {
            return [];
        }

        $items = [];

        foreach ($paths as $pathItem) {
            if (is_array($pathItem)) {
                $items[] = $pathItem;
            }
        }

        return $items;
    }
}

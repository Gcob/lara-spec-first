<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Generation;

use Gcob\LaraSpecFirst\Contract\DocumentPointer;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\Response;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\Exceptions\ConflictingInputException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UntypeableResponseException;
use Gcob\LaraSpecFirst\Generation\Exceptions\UnusableNameException;

/**
 * Works out every response DTO of a build: its name, its properties, and which operation answers with it.
 *
 * **A response DTO mirrors its schema.** The request side decides what a request can promise and builds two types per
 * body; a response has no such question, so a DTO has the properties the schema states, a required one first and an
 * optional one `Optional`, and the type mapping is the one the input side uses ({@see DtoPlanner}).
 *
 * **A DTO is named after the schema it describes, without `Input`**, so `$ref: User` is `UserDto` and the request that
 * reads it is `UserInputDto`.
 *
 * **A schema with a fault of its own is not typed, and the build goes on.** A name that leaves no class name, two keys
 * that derive one property, `allOf` branches that disagree: the request side refuses them, because a rule set built
 * from half of a contract enforces half of it. A response enforces nothing, so a status with such a schema has no DTO
 * and says why, and a property with one is `mixed` and says why, while everything else is typed as written. Each is
 * also recorded ({@see ResponseFallback}), for whoever must tell it from a keyword a DTO cannot type. **What the
 * contract itself leaves `mixed`** (a union, no type, a recursion with no class) is recorded in a list of its own
 * ({@see UntypedResponseValue}), never as a fallback: it is not a fault of the schema.
 *
 * **A class name two schemas derive is decided before anything is typed.** The plan walks the response schemas twice.
 * The first walk, the survey, gives every schema the class name it derives and records who claims each one; the second
 * is the walk that builds, and a schema that does not keep its name gives way like any other fault
 * ({@see ResponseNameContest}). So the answer is the contract's, and reading the document backwards gives the same
 * files.
 *
 * **Built before it is written**, for the reason the input planner gives: nothing is written until the whole plan
 * stands.
 *
 * @internal Not public API.
 *
 * @see docs/guide/code-generation/dto-anatomy.md — "What each schema type becomes"
 */
final class ResponseDtoPlanner extends DtoPlanner
{
    /**
     * The operations that answer with each DTO, by DTO and in document order.
     *
     * @var array<string, list<array{label: string, status: string}>>
     */
    private array $readers = [];

    /**
     * @var list<ResponseFallback>
     */
    private array $fallbacks = [];

    /**
     * @var list<UntypedResponseValue>
     */
    private array $untyped = [];

    private ResponseNameContest $contest;

    /**
     * Whether the walk in progress is the survey, which only records who claims each class name.
     */
    private bool $surveying = false;

    /**
     * @param  list<Operation>  $operations  in document order
     * @param  array<string, string>  $inputDtoNames  the class name of each input DTO the build writes, with where its
     *                                                request schema is written
     */
    public function plan(array $operations, array $inputDtoNames): ResponsePlan
    {
        $this->contest = new ResponseNameContest;

        // An input DTO outranks every response schema, so it is a claim like the others.
        foreach ($inputDtoNames as $class => $position) {
            $this->contest->claim((string) $class, 'input:'.$position, ResponseNameContest::INPUT_DTO, $position);
        }

        $this->surveying = true;
        $this->walk($operations);
        $this->surveying = false;

        $statuses = $this->walk($operations);
        $dtos = [];

        foreach ($this->registry as $shortName => $entry) {
            $dtos[] = new PlannedResponseDto(
                $shortName,
                $entry['position'],
                $entry['properties'],
                $this->readers[$shortName] ?? [],
                $entry['naming'],
                $entry['findings'],
            );
        }

        return new ResponsePlan($dtos, $statuses, $this->fallbacks, $this->untyped);
    }

    /**
     * Every declared status of every operation, from nothing: what a walk builds is its own.
     *
     * @param  list<Operation>  $operations
     * @return array<string, list<StatusResponse>>
     */
    private function walk(array $operations): array
    {
        $this->registry = [];
        $this->readers = [];
        $this->fallbacks = [];
        $this->untyped = [];
        $this->ancestors = [];
        $this->identity = '';

        $statuses = [];

        foreach ($operations as $operation) {
            $statuses[$operation->label()] = [];

            foreach ($operation->responses as $response) {
                $statuses[$operation->label()][] = $this->status($operation, $response);
            }
        }

        return $statuses;
    }

    protected function suffix(): string
    {
        return 'Dto';
    }

    protected function isFile(Schema $schema): bool
    {
        return false;
    }

    protected function unrolls(Schema $site, ?Schema $ancestor): bool
    {
        return $site->recursesTo !== null && $ancestor !== null && $site->allOf === [];
    }

    protected function mixedNote(): string
    {
        return '';
    }

    protected function unbuiltRecursionFinding(string $segment, string $position): string
    {
        return sprintf(
            '`%s` points back at `%s`, which is not a single named schema, so there is no class to build it into '
                .'and it is `mixed`.',
            $segment,
            $position,
        );
    }

    protected function propertiesBesideFinding(string $segment, string $ancestor): string
    {
        return sprintf(
            '`%s` writes properties beside the reference, which the class of `%s` does not carry, so it is `mixed`.',
            $segment,
            $ancestor,
        );
    }

    protected function ancestorNotAnObjectFinding(string $segment, string $position): string
    {
        return sprintf(
            '`%s` points back at `%s`, which is not an object with properties, so it is `mixed`: there is no class '
                .'to read it into.',
            $segment,
            $position,
        );
    }

    protected function recordUntyped(UntypedResponseCause $cause, string $owner, string $position, array $keywords): void
    {
        $this->untyped[] = new UntypedResponseValue($owner, $position, $cause, $keywords);
    }

    protected function registerNested(
        string $shortName,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
    ): string {
        return $this->enter($shortName, $written, $merged, $position, $base, $origin, $naming, $named, 'its schema');
    }

    /**
     * Take a class name for a schema that would be a DTO, or give way to whoever claims it.
     *
     * **The survey grants every name** and records the claim, under a key of its own for each claimant, so a schema
     * whose name another holds still has its properties walked and what it claims is counted. **The walk that builds**
     * asks the contest, and a claimant that does not keep the name gives way before its keys are read, as a fault of
     * its own does.
     *
     * @param  string  $lead  how the schema is named in the reason, "its schema" or "the schema of its items"
     *
     * @throws UntypeableResponseException another schema keeps the class name, or the keys derive one property name
     */
    private function enter(
        string $shortName,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
        string $lead,
    ): string {
        [$schemaName] = $this->nameOf($written, $merged);
        $identity = $named ? 'named:'.$position : 'inline:'.$merged->shapeKey();

        if ($this->surveying) {
            // TODO (#191): this claims a name for a schema the build may never reach, because it sits below a schema that
            // gave way; claim only what the building walk reaches.
            $this->contest->claim($shortName, $identity, $named ? ResponseNameContest::COMPONENT : ResponseNameContest::INLINE, $position, $named ? $schemaName : null);

            try {
                return $this->register($shortName."\0".$identity, $written, $merged, $position, $base, $origin, $naming, $named);
            } catch (UntypeableResponseException $thrown) {
                $this->contest->gaveWay($shortName, $identity);

                throw $thrown;
            }
        }

        $reason = $this->contest->explain($shortName, $identity, $this->subject($lead, $named ? $schemaName : null, $position));

        if ($reason !== null) {
            throw new UntypeableResponseException(ResponseFallbackCause::NameTaken, $position, $reason);
        }

        return $this->register($shortName, $written, $merged, $position, $base, $origin, $naming, $named);
    }

    /**
     * A schema as the reason that it gave way names it: by its name and position when it has a name, and by where it is
     * written when it has not.
     */
    private function subject(string $lead, ?string $name, string $position): string
    {
        return $name === null
            ? sprintf('%s (written inline at `%s`)', $lead, CommentText::safe($position))
            : sprintf('%s `%s` (`%s`)', $lead, CommentText::safe($name), CommentText::safe($position));
    }

    /**
     * What one property of a schema becomes, or `mixed` when its schema is at fault.
     *
     * **The innermost value that cannot be typed is the one that is `mixed`**: a list whose items disagree is a
     * `list<mixed>`, a map whose values disagree a map of `mixed`, because each value is typed by a call of its own.
     * What no DTO of ours describes is said in the file, so a reader who finds `mixed` also finds why.
     */
    protected function type(Schema $written, string $base, string $segment, string $pointer, string $owner): InputDtoType
    {
        try {
            return parent::type($written, $base, $segment, $pointer, $owner);
        } catch (UntypeableResponseException|ConflictingInputException $thrown) {
            $fault = $this->faultOf($thrown, $written, $pointer, '`'.$segment.'`');
            $this->registry[$owner]['findings'][] = sprintf('`%s` is `mixed`: %s.', CommentText::safe($segment), $fault->reason);
            $this->fallbacks[] = new ResponseFallback($pointer, $fault->position, $fault->cause);

            return new InputDtoType(InputDtoType::MIXED);
        }
    }

    /**
     * @throws UntypeableResponseException the name leaves no class name
     */
    protected function className(string $schemaName, string $position): string
    {
        try {
            return parent::className($schemaName, $position);
        } catch (UnusableNameException) {
            throw new UntypeableResponseException(
                ResponseFallbackCause::NameUnusable,
                $position,
                sprintf(
                    'the schema name `%s` leaves no class name. Rename it to start with a letter or an underscore',
                    CommentText::safe($schemaName),
                ),
            );
        }
    }

    /**
     * What is wrong with a schema that gave way, as the reason its reader gets.
     *
     * **A schema that would be a DTO is judged in this order:** the name it derives is usable, then no other schema
     * keeps that name, then its branches agree, then its keys. The class name is the first thing a DTO needs, and the
     * rename that gives it one comes before the branches that need to agree. **A named schema whose branches disagree
     * still claims its name** (the survey records it here), because a class that carried that name would describe
     * something else. A schema written inline whose branches disagree claims nothing: there is no merged schema to
     * name.
     *
     * @param  string  $pointer  where the schema is written, for a schema with no position of its own
     * @param  string  $root  the label the merge was asked to name the schema by, which the position of a disagreement
     *                        starts from
     * @param  string  $lead  how the schema is named in a reason, "its schema" or "the schema of its items"
     */
    private function faultOf(UntypeableResponseException|ConflictingInputException $thrown, Schema $written, string $pointer, string $root, string $lead = 'its schema'): UntypeableResponseException
    {
        if ($thrown instanceof UntypeableResponseException) {
            return $thrown;
        }

        $position = $written->source ?? $pointer;

        if ($written->name !== null && self::declaresProperties($written)) {
            try {
                $shortName = $this->className($written->name, $position).$this->suffix();
            } catch (UntypeableResponseException $nameless) {
                return $nameless;
            }

            $identity = 'named:'.$position;

            if ($this->surveying) {
                $this->contest->claim($shortName, $identity, ResponseNameContest::COMPONENT, $position, $written->name);
                $this->contest->gaveWay($shortName, $identity);
            } else {
                $reason = $this->contest->explain($shortName, $identity, $this->subject($lead, $written->name, $position));

                if ($reason !== null) {
                    return new UntypeableResponseException(ResponseFallbackCause::NameTaken, $position, $reason);
                }
            }
        }

        $path = ltrim(substr((string) $thrown->where, strlen($root)), '.');

        return new UntypeableResponseException(
            ResponseFallbackCause::AllOfDisagrees,
            $position,
            sprintf(
                'its `allOf` branches disagree%s: %s. Make them agree',
                $path === '' ? '' : ' at `'.CommentText::safe($path).'`',
                CommentText::safe((string) $thrown->what),
            ),
        );
    }

    /**
     * Whether a schema, or one branch of its `allOf`, states properties: what makes it a DTO once it has a class name.
     */
    private static function declaresProperties(Schema $schema): bool
    {
        if ($schema->properties !== []) {
            return true;
        }

        foreach ($schema->allOf as $branch) {
            if ($branch->properties !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The PHP property each key of a schema becomes, without the keys a response never sends.
     *
     * Found before the schema is registered, so two keys that derive one name leave no half-built class behind. A
     * property whose own `allOf` disagrees cannot say whether it is `writeOnly`, so it is kept: it is typed `mixed`
     * where it is built, and its name still counts here.
     *
     * @return array<string, string> the property name, by contract key
     *
     * @throws UntypeableResponseException two keys derive one property name
     */
    private function keptProperties(Schema $merged, string $position): array
    {
        $kept = [];
        $claimed = [];

        foreach ($merged->properties as $key => $property) {
            $key = (string) $key;

            try {
                $writeOnly = AllOfMerger::merge($property, $this->identity, '`'.$key.'`')->writeOnly;
            } catch (ConflictingInputException) {
                $writeOnly = false;
            }

            // Nothing a response sends is `writeOnly`, so the type has no property for it.
            if ($writeOnly) {
                continue;
            }

            $name = self::propertyName($key);

            if (isset($claimed[$name])) {
                throw new UntypeableResponseException(
                    ResponseFallbackCause::KeysCollide,
                    $position,
                    sprintf(
                        'its keys `%s` and `%s` both derive the property `$%s`. Rename one of them',
                        CommentText::safe($claimed[$name]),
                        CommentText::safe($key),
                        $name,
                    ),
                );
            }

            $claimed[$name] = $key;
            $kept[$key] = $name;
        }

        return $kept;
    }

    /**
     * What one declared status answers with: the DTO of its JSON body, or the reason it has none.
     *
     * **A list has no class of its own** ({@see StatusResponse}): the body is an array and its items are the DTO, one
     * level at a time for a list of lists.
     */
    private function status(Operation $operation, Response $response): StatusResponse
    {
        $status = $response->status;

        if ($response->content === []) {
            return StatusResponse::without($status, 'it declares no body');
        }

        $json = self::jsonSchemas($response);

        if ($json === []) {
            return StatusResponse::without($status, 'it declares no JSON media type');
        }

        [$mediaType, $written] = $json[0];

        foreach ($json as [, $other]) {
            if (! $other->sameShapeAs($written)) {
                return StatusResponse::without($status, sprintf(
                    'it declares two JSON media types with different schemas (%s)',
                    implode(' and ', array_map(
                        static fn (array $entry): string => '`'.CommentText::safe($entry[0]).'`',
                        array_slice($json, 0, 2),
                    )),
                ));
            }
        }

        $this->identity = $operation->label();
        $label = 'the `'.$status.'` response';
        $stem = self::stem($operation);
        $base = $stem.self::statusName($status).'Response';
        $root = DocumentPointer::forOperation($operation).'/responses/'.DocumentPointer::escape($status)
            .'/content/'.DocumentPointer::escape($mediaType).'/schema';

        $inline = $root;
        $lead = 'its schema';

        try {
            $merged = AllOfMerger::merge($written, $this->identity, $label);
            $depth = 0;
            $nullable = $merged->isNullable();
            $itemsNullable = false;

            while ($merged->soleType() === SchemaType::Array) {
                if ($merged->items === null) {
                    return StatusResponse::without($status, 'its body is a list with no `items`');
                }

                $written = $merged->items;
                $lead = 'the schema of its items';
                $merged = AllOfMerger::merge($written, $this->identity, $label);
                $itemsNullable = $itemsNullable || $merged->isNullable();
                $inline = self::within($inline, '/items');
                $base .= 'Item';
                $depth++;
            }

            if (! RuleSetBuilder::isObjectBody($merged)) {
                return StatusResponse::without(
                    $status,
                    $depth > 0 ? 'its body is a list of values that are not objects' : 'its body is not an object or a list',
                );
            }

            [$name, $source] = $this->nameOf($written, $merged);

            $this->ancestors = RuleSetBuilder::definitions($written, $merged, $this->identity, $label);

            if ($name === null || $source === null) {
                $shortName = $this->enter(
                    $base.$this->suffix(),
                    $written,
                    $merged,
                    $inline,
                    $base,
                    sprintf('derived from the operation "%s" and the status "%s"', $operation->label(), $status),
                    sprintf(
                        'Class name derived from the operation\'s name, `%s`, and the status `%s`, because the %s is '
                            .'written inline and has no name of its own.',
                        $stem,
                        $status,
                        $depth > 0 ? 'list\'s item' : 'response',
                    ),
                    false,
                    $lead,
                );
            } else {
                $named = $this->className($name, $source);
                $shortName = $this->enter(
                    $named.$this->suffix(),
                    $written,
                    $merged,
                    $source,
                    $named,
                    'the schema\'s name',
                    self::namedAfter($name, $source),
                    true,
                    $lead,
                );
            }
        } catch (UntypeableResponseException|ConflictingInputException $thrown) {
            $fault = $this->faultOf($thrown, $written, $inline, $label, $lead);
            $this->fallbacks[] = new ResponseFallback($root, $fault->position, $fault->cause);

            return StatusResponse::without($status, $fault->reason);
        }

        $reader = ['label' => $this->identity, 'status' => $status];

        if (! in_array($reader, $this->readers[$shortName] ?? [], true)) {
            $this->readers[$shortName][] = $reader;
        }

        return StatusResponse::of($status, $shortName, $depth, $nullable, $itemsNullable);
    }

    /**
     * The operation's name as a class-name stem: its `operationId`, or its method and path when it has none.
     *
     * The same derivation the generated request takes its name from, so `CreateUserRequest` and
     * `CreateUser201ResponseDto` read as one endpoint.
     */
    private static function stem(Operation $operation): string
    {
        $declared = $operation->operationId === null ? null : DerivedName::fromOperationId($operation->operationId);

        return $declared ?? DerivedName::fromMethodAndPath($operation);
    }

    /**
     * A status as the part of a class name that follows the operation: `201`, `2XX`, `Default`.
     */
    private static function statusName(string $status): string
    {
        return strtolower($status) === 'default'
            ? 'Default'
            : strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $status));
    }

    /**
     * The media type and schema of each JSON body a response writes, in document order: none when it has no body, or
     * only media types that are not JSON.
     *
     * Public for the doctor, which reads the same schemas to name the keywords a response DTO does not type.
     *
     * @return list<array{0: string, 1: Schema}>
     */
    public static function jsonSchemas(Response $response): array
    {
        $found = [];

        foreach ($response->content as $mediaType => $schema) {
            $lower = strtolower((string) $mediaType);

            if ($lower === 'application/json' || str_ends_with($lower, '+json')) {
                $found[] = [(string) $mediaType, $schema];
            }
        }

        return $found;
    }

    /**
     * Put one DTO in the registry and build its properties, or recognize it as one already there.
     *
     * Registered *before* its properties are built, so a node that recurses back to it finds it instead of building it
     * again. **Which is why its own faults are found first:** a schema whose keys collide has no entry, so what
     * recurses back to it finds no class it will not have. After the entry exists a fault in one property costs that
     * property.
     *
     * @return string the short name
     *
     * @throws UntypeableResponseException the keys of the schema derive one property name
     */
    private function register(
        string $shortName,
        Schema $written,
        Schema $merged,
        string $position,
        string $base,
        string $origin,
        string $naming,
        bool $named,
    ): string {
        // Whatever claims this name here is the claimant the contest let keep it, so it is the one already registered.
        if (isset($this->registry[$shortName])) {
            return $shortName;
        }

        $kept = $this->keptProperties($merged, $position);

        // Why: the registry's shape is shared with the input side, which reads `partial` and `multipart`.
        $this->registry[$shortName] = [
            'partial' => false,
            'named' => $named,
            'multipart' => false,
            'schema' => $merged,
            'position' => $position,
            'base' => $base,
            'origin' => $origin,
            'naming' => $naming,
            'properties' => [],
            'findings' => [],
        ];

        $required = [];
        $optional = [];

        foreach ($merged->properties as $key => $property) {
            $key = (string) $key;

            if (! isset($kept[$key])) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is `writeOnly`, so a response never sends it and this type has no property for it.',
                    $key,
                );

                continue;
            }

            $name = $kept[$key];

            if ($name !== $key) {
                $this->registry[$shortName]['findings'][] = sprintf(
                    '`%s` is not a PHP identifier, so it is read into `$%s`. `from()` and `toArray()` keep the key as written.',
                    $key,
                    $name,
                );
            }

            $type = $this->type(
                $property,
                $base,
                $key,
                self::within($position, '/properties/'.DocumentPointer::escape($key)),
                $shortName,
            );

            if (in_array($key, $merged->required, true)) {
                $required[] = new InputDtoProperty($key, $name, $type, false);
            } else {
                $optional[] = new InputDtoProperty($key, $name, $type, true);
            }
        }

        // An object with no properties is a map: its keys are the data, so the one property holds them all. Typed by
        // its value schema, always, since a response shares its payload with no `query` parameter.
        if ($merged->properties === []) {
            $required[] = new InputDtoProperty(
                'additionalProperties',
                'additionalProperties',
                $this->map($merged, $base, 'additionalProperties', $position, false, $shortName),
                false,
                wholePayload: true,
            );
        }

        $values = $merged->properties === [] ? null : $merged->additionalPropertiesSchema;

        if ($values !== null && ! RuleSetBuilder::allowsAnyValue($values)) {
            $this->registry[$shortName]['findings'][] = '`additionalProperties` types the values of the keys that are not '
                .'declared, but this type carries only the declared properties, so those values are not read.';
            $this->recordUntyped(
                UntypedResponseCause::ValuesBesideProperties,
                $shortName,
                self::within($position, '/additionalProperties'),
                self::writtenKeywords($values),
            );
        }

        $this->registry[$shortName]['properties'] = [...$required, ...$optional];

        return $shortName;
    }
}

<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
use Gcob\LaraSpecFirst\Contract\QueryParameter;
use Gcob\LaraSpecFirst\Contract\RequestBody;
use Gcob\LaraSpecFirst\Contract\Schema;
use Gcob\LaraSpecFirst\Contract\SchemaType;
use Gcob\LaraSpecFirst\Generation\InputDtoPlan;
use Gcob\LaraSpecFirst\Generation\InputDtoPlanner;
use Gcob\LaraSpecFirst\Generation\PlannedRequest;
use Gcob\LaraSpecFirst\Generation\RequestName;
use Gcob\LaraSpecFirst\Generation\RuleSetBuilder;
use Gcob\LaraSpecFirst\Parsing\ReadOutcome;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;
use Gcob\LaraSpecFirst\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

// Feature tests run inside a booted Laravel application; unit tests do not, so
// they stay fast and framework-free.
uses(TestCase::class)->in('Feature');

/**
 * Decode a specification fixture from tests/Fixtures.
 *
 * Fixtures are read as plain arrays rather than through any loader: everything
 * under Parsing works on the decoded document, and a test that had to boot a
 * loader to reach it would be testing two things at once.
 *
 * @return array<string, mixed>
 */
function specFixture(string $name): array
{
    /** @var array<string, mixed> $document */
    $document = Yaml::parseFile(__DIR__.'/Fixtures/'.$name);

    return $document;
}

/**
 * The path of a specification fixture, for the code that reads files itself.
 *
 * Beside specFixture() on purpose: the pair makes the choice visible — a test
 * working on a decoded document takes the array, a test exercising the reader
 * takes the path.
 */
function specFixturePath(string $name): string
{
    return __DIR__.'/Fixtures/'.$name;
}

/**
 * Read a specification fixture through the whole reading engine — the pair
 * beside it above stop at the decoded array; this one goes all the way to the
 * operations a real build would see, references resolved included.
 *
 * **Throws the first fault the read collected, rather than returning it in a
 * list.** The reading pipeline itself no longer throws — see
 * {@see ReadOutcome} — but the conformance suite that is this helper's only
 * caller exists to pin what a given *input* produces, one fixture at a time,
 * not how the pipeline reports it. Every conformance fixture is designed to
 * carry exactly one problem, so re-throwing the first fault keeps every
 * `toThrow()` assertion across that suite reading exactly as it did before
 * the pipeline changed underneath it.
 *
 * @return list<Operation>
 */
function extractFixture(string $name): array
{
    return extractDocumentAt(specFixturePath($name));
}

/**
 * The same read, for a document that has no fixture file of its own.
 *
 * A conformance case that varies one keyword across a dozen datasets is better
 * built in memory than committed a dozen times, and it still has to report
 * faults the way every `toThrow()` in that suite expects. One copy of the rule
 * lives here so the two cannot drift.
 *
 * @return list<Operation>
 */
function extractDocumentAt(string $path): array
{
    $outcome = ReadOutcome::read(new SpecDocumentReader, $path);

    if (! $outcome->isClean()) {
        throw $outcome->faults[0];
    }

    return $outcome->operations;
}

/**
 * The operation the golden request is generated from, and the one most cases of
 * the request and the DTO emitters use: enough shapes to exercise presence,
 * nullability and a finding. Here rather than in either test because both read it.
 */
function goldenOperation(): Operation
{
    return new Operation(
        index: 0,
        method: HttpMethod::Post,
        path: PathTemplate::fromString('/users'),
        operationId: 'createUser',
        requestBody: new RequestBody(['application/json' => new Schema(
            types: [SchemaType::Object],
            properties: [
                'email' => new Schema(types: [SchemaType::String], format: 'email', maxLength: 255),
                'age' => new Schema(types: [SchemaType::Integer], minimum: 18.0),
                'nickname' => new Schema(types: [SchemaType::String, SchemaType::Null]),
                'role' => new Schema(types: [SchemaType::String], enum: ['admin', 'member']),
                'tags' => new Schema(
                    types: [SchemaType::Array],
                    items: new Schema(types: [SchemaType::String]),
                    uniqueItems: true,
                ),
                'address' => new Schema(
                    types: [SchemaType::Object],
                    properties: ['city' => new Schema(types: [SchemaType::String])],
                    required: ['city'],
                ),
                'website' => new Schema(types: [SchemaType::String], format: 'uri'),
            ],
            required: ['email'],
            additionalProperties: false,
        )], true),
        queryParameters: [new QueryParameter('notify', new Schema(types: [SchemaType::Boolean]))],
    );
}

/**
 * The requests of these operations, each knowing the input DTO its `dto()`
 * returns, and the plan of every DTO.
 *
 * What the build does in two steps: plan the DTOs from the requests, then tell
 * each request which one is its own. An operation with nothing to validate has
 * no request and is left out, as it is in a build.
 *
 * @param  list<Operation>  $operations
 * @return array{requests: list<PlannedRequest>, plan: InputDtoPlan}
 */
function plannedRequests(array $operations, string $controllerShortName = 'Controller'): array
{
    $requests = [];

    foreach ($operations as $operation) {
        $name = RequestName::for($operation);

        if ($name !== null) {
            $requests[] = new PlannedRequest($operation, $name, RuleSetBuilder::for($operation), $controllerShortName);
        }
    }

    $plan = (new InputDtoPlanner)->plan($requests);

    return [
        'requests' => array_map(
            static fn (PlannedRequest $request): PlannedRequest => $request->withDto($plan->readBy[$request->operation->label()] ?? null),
            $requests,
        ),
        'plan' => $plan,
    ];
}

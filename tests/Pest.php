<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;
use Gcob\LaraSpecFirst\Contract\Operation;
use Gcob\LaraSpecFirst\Contract\PathTemplate;
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
 * An operation with a recognisable operationId, for tests about how operations
 * are held rather than about what they contain.
 *
 * Beside the fixture helpers rather than in a test file: Pest loads every test
 * into one process, so a global function declared in one of them is a fatal
 * error waiting for the second file that wants the same name.
 */
function operationNamed(string $method, string $path, int $index = 0): Operation
{
    return new Operation(
        $index,
        HttpMethod::from($method),
        PathTemplate::fromString($path),
        $method.'-'.$path,
    );
}

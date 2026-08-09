<?php

declare(strict_types=1);

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

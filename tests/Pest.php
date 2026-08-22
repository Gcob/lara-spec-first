<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\Operation;
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
    $outcome = ReadOutcome::read(new SpecDocumentReader, specFixturePath($name));

    if (! $outcome->isClean()) {
        throw $outcome->faults[0];
    }

    return $outcome->operations;
}

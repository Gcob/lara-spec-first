<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Support\Path;

/*
 * Held in one place because it was answered in three, and one of the three got
 * Windows wrong: a check for a leading separator alone treats `C:\specs\api.yaml`
 * as relative and joins it under the application root, producing a path nothing
 * is at. The drive-letter cases below are the ones that motivated extracting it.
 */

it('recognizes an absolute path', function (string $path): void {
    expect(Path::isAbsolute($path))->toBeTrue();
})->with([
    'unix' => '/srv/app/openapi.yaml',
    'unix root' => '/',
    'windows backslash' => 'C:\\specs\\api.yaml',
    'windows forward slash' => 'C:/specs/api.yaml',
    'windows lowercase drive' => 'd:/specs/api.yaml',
    'UNC-style' => '\\\\server\\share\\api.yaml',
]);

it('recognizes a relative path', function (string $path): void {
    expect(Path::isAbsolute($path))->toBeFalse();
})->with([
    'plain' => 'openapi.yaml',
    'nested' => 'app/Http/Generated',
    'explicitly relative' => './openapi.yaml',
    'climbing' => '../openapi.yaml',
    // A drive letter with nothing after it is not a rooted path, and treating it
    // as one would be a guess rather than a reading.
    'bare drive letter' => 'C:openapi.yaml',
    'empty' => '',
]);

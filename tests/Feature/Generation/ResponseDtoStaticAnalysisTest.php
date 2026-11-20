<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Generation\BuildPlanner;
use Gcob\LaraSpecFirst\Generation\RuleSetOptions;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
 * The reason a response DTO exists, run: an application that reads a response property the contract no longer states is
 * told by static analysis, before it is told by a customer.
 *
 * PHPStan is the real one, at level 8, in a process of its own: its result is an exit code and a message, and the only
 * honest way to ask it is to run it. It gets its own configuration and cache directory in a scratch tree, because the
 * repository's `build/phpstan` is shared with a `composer analyse` that may be running at the same time.
 */

/** A class that reads `nickname` the way an application would, with nothing to say about it being optional. */
const RESPONSE_READER = <<<'PHP'
<?php

declare(strict_types=1);

use App\Http\Generated\Data\UserDto;

final class NicknameReader
{
    public function nickname(UserDto $user): mixed
    {
        return $user->nickname;
    }
}
PHP;

/**
 * Build `response-property-read.yaml`, optionally without the `nickname` property of `User`, write its DTOs and the
 * reader to a scratch tree, and run PHPStan at level 8 over the reader.
 *
 * The tree lives under the system's temporary directory and is removed whatever happens, so a failed run leaves nothing
 * behind. PHPStan is the project's own binary, called by absolute path with the project's autoloader, so nothing is
 * downloaded and nothing depends on where the test is run from.
 *
 * @return array{exit: int|null, messages: list<string>, output: string}
 */
function analyseResponseReader(bool $withoutNickname): array
{
    $root = dirname(__DIR__, 3);
    $tree = realpath(sys_get_temp_dir()).'/lsf-phpstan-'.bin2hex(random_bytes(6));

    try {
        $contract = specFixture('response-property-read.yaml');

        if ($withoutNickname) {
            /** @var array<string, mixed> $user */
            $user = $contract['components']['schemas']['User'];
            unset($user['properties']['nickname']);
            $contract['components']['schemas']['User'] = $user;
        }

        mkdir($tree.'/generated/Data', 0777, true);
        file_put_contents($tree.'/openapi.yaml', Yaml::dump($contract, 10, 2));

        $plan = (new BuildPlanner('App\\Http\\Generated', 'openapi.yaml', new RuleSetOptions))->plan(extractDocumentAt($tree.'/openapi.yaml'));

        foreach ($plan->files as $file) {
            if (str_starts_with($file->relativePath, 'Data/')) {
                file_put_contents($tree.'/generated/'.$file->relativePath, $file->contents);
            }
        }

        file_put_contents($tree.'/Reader.php', RESPONSE_READER);
        file_put_contents($tree.'/phpstan.neon', <<<NEON
            parameters:
                level: 8
                paths:
                    - {$tree}/Reader.php
                scanDirectories:
                    - {$tree}/generated
                tmpDir: {$tree}/cache
            NEON);

        $process = new Process([
            PHP_BINARY,
            $root.'/vendor/bin/phpstan',
            'analyse',
            '--configuration='.$tree.'/phpstan.neon',
            '--autoload-file='.$root.'/vendor/autoload.php',
            '--error-format=json',
            '--no-progress',
            '--no-interaction',
            '--memory-limit=512M',
        ], $tree, null, null, 120);

        $process->run();

        /** @var array{files?: array<string, array{messages: list<array{message: string}>}>} $report */
        $report = json_decode($process->getOutput(), true) ?? [];
        $messages = [];

        foreach ($report['files'] ?? [] as $file) {
            foreach ($file['messages'] as $message) {
                $messages[] = $message['message'];
            }
        }

        return ['exit' => $process->getExitCode(), 'messages' => $messages, 'output' => $process->getOutput().$process->getErrorOutput()];
    } finally {
        removeResponseTree($tree);
    }
}

function removeResponseTree(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

it('a removed response property fails static analysis in the code that reads it', function (): void {
    // The control: while the contract states `nickname`, the very same reader passes, so the failure below is the
    // property's and not the scratch setup's.
    $before = analyseResponseReader(withoutNickname: false);

    expect($before['output'])->toBeString()
        ->and($before['messages'])->toBe([])
        ->and($before['exit'])->toBe(0);

    $after = analyseResponseReader(withoutNickname: true);

    expect($after['exit'])->toBe(1)
        ->and($after['messages'])->toHaveCount(1)
        ->and($after['messages'][0])->toContain('undefined property')
        ->toContain('UserDto::$nickname');
});

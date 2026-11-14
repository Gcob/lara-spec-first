<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * The two hooks that run the formatters for a contributor: the Claude Code
 * PostToolUse hook, which formats the file an edit has just written, and the git
 * pre-commit hook, which refuses a commit whose staged files are not formatted.
 *
 * Both are shell scripts, so both are run as they are: the first with the JSON
 * payload Claude Code hands it on stdin, the second through a real `git commit`
 * in a scratch repository. The scratch project holds a copy of the repository's
 * own configuration, a copy of its scripts and a link to its vendor directory, so
 * the hooks run the same Pint, the same docblock formatter and the same Prettier
 * as a contributor's checkout does.
 *
 * @see CONTRIBUTING.md — "Formatting hooks"
 */

/**
 * The scratch projects of this file, removed by the hook below. Asking for them with `$forget` hands them over and
 * empties the list.
 *
 * @return array<int, string>
 */
function hookScratchDirectories(?string $created = null, bool $forget = false): array
{
    static $directories = [];

    if ($created !== null) {
        $directories[] = $created;
    }

    $held = $directories;

    if ($forget) {
        $directories = [];
    }

    return $held;
}

afterEach(function (): void {
    foreach (hookScratchDirectories(forget: true) as $directory) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
});

function hookRepository(): string
{
    return dirname(__DIR__, 2);
}

function hookHasNode(): bool
{
    return trim((string) shell_exec('command -v npx')) !== '';
}

/**
 * A project with the repository's formatting configuration and tools, and these files.
 *
 * @param  array<string, string>  $files  path relative to the project, then contents
 */
function hookProject(array $files = []): string
{
    $directory = realpath(sys_get_temp_dir()).'/lsf-hooks-'.bin2hex(random_bytes(6));
    mkdir($directory);
    hookScratchDirectories($directory);

    foreach (['.editorconfig', 'pint.json', '.prettierrc.json', 'composer.json'] as $config) {
        copy(hookRepository().'/'.$config, $directory.'/'.$config);
    }

    // Copied rather than linked: the formatter takes the project root from where
    // its script lives, and the scratch project has to be that root.
    mkdir($directory.'/scripts');

    foreach (glob(hookRepository().'/scripts/*') ?: [] as $script) {
        if (is_file($script)) {
            copy($script, $directory.'/scripts/'.basename($script));
            chmod($directory.'/scripts/'.basename($script), 0755);
        }
    }

    symlink(hookRepository().'/vendor', $directory.'/vendor');
    mkdir($directory.'/.githooks');
    copy(hookRepository().'/.githooks/pre-commit', $directory.'/.githooks/pre-commit');
    chmod($directory.'/.githooks/pre-commit', 0755);

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($directory.'/'.$path))) {
            mkdir(dirname($directory.'/'.$path), recursive: true);
        }

        file_put_contents($directory.'/'.$path, $contents);
    }

    return $directory;
}

/**
 * The PostToolUse script, given this payload the way Claude Code gives it.
 *
 * @return array{exit: int, out: string, err: string}
 */
function runEditHook(string $project, string $payload): array
{
    $process = new Process(
        ['sh', hookRepository().'/scripts/format-edited-file.sh'],
        env: ['CLAUDE_PROJECT_DIR' => $project],
        input: $payload,
    );
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
}

/**
 * The payload of an `Edit` or `Write` that touched this file.
 */
function editPayload(string $path): string
{
    return json_encode(['tool_name' => 'Edit', 'tool_input' => ['file_path' => $path]], JSON_THROW_ON_ERROR);
}

/**
 * Run `git` in a project, as a hook would see it.
 *
 * @param  list<string>  $arguments
 * @return array{exit: int, out: string, err: string}
 */
function hookGit(string $project, array $arguments): array
{
    $process = new Process(
        ['git', '-c', 'core.hooksPath=.githooks', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$arguments],
        $project,
    );
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
}

/**
 * A repository whose working tree holds these files, all staged.
 *
 * @param  array<string, string>  $files
 */
function stagedProject(array $files): string
{
    $project = hookProject($files);

    hookGit($project, ['init', '-q']);
    hookGit($project, ['add', '--', ...array_keys($files)]);

    return $project;
}

// A file the editor left messy: Pint's part is the class layout and the short
// array, the docblock formatter's part is the paragraph, wrapped far too early for
// the 120 columns of the repository's .editorconfig.
const MESSY_PHP = <<<'PHP'
<?php
namespace X;
final class A {
    /**
     * A paragraph that is wrapped
     * far too early, and ought to be joined into one line by the formatter at one hundred and twenty columns wide.
     */
    public function f( ) { return array( 1 ); } }
PHP;

const FORMATTED_PHP = <<<'PHP'
<?php

namespace X;

final class A
{
    /**
     * A paragraph that is wrapped far too early, and ought to be joined into one line by the formatter at one hundred
     * and twenty columns wide.
     */
    public function f()
    {
        return [1];
    }
}

PHP;

const LONG_MARKDOWN = <<<'MD'
# A title

A paragraph wrapped far too early, which Prettier joins and wraps again at the one hundred and twenty columns the
repository's .editorconfig sets for Markdown, and so it
ends up on two lines.

MD;

const FORMATTED_MARKDOWN = <<<'MD'
# A title

A paragraph wrapped far too early, which Prettier joins and wraps again at the one hundred and twenty columns the
repository's .editorconfig sets for Markdown, and so it ends up on two lines.

MD;

describe('the PostToolUse hook script', function (): void {
    it('formats an edited PHP file with Pint, then the docblock formatter', function (): void {
        $project = hookProject(['src/A.php' => MESSY_PHP]);

        $run = runEditHook($project, editPayload($project.'/src/A.php'));

        expect($run['exit'])->toBe(0, $run['err'])
            ->and(file_get_contents($project.'/src/A.php'))->toBe(FORMATTED_PHP);
    })->skip(fn (): bool => ! is_executable(hookRepository().'/vendor/bin/pint'), 'Pint is not installed');

    it('formats an edited Markdown file with Prettier', function (): void {
        $project = hookProject(['docs/page.md' => LONG_MARKDOWN]);

        $run = runEditHook($project, editPayload($project.'/docs/page.md'));

        expect($run['exit'])->toBe(0, $run['err'])
            ->and(file_get_contents($project.'/docs/page.md'))->toBe(FORMATTED_MARKDOWN);
    })->skip(fn (): bool => ! hookHasNode(), 'Node is not installed');

    it('leaves a file of any other kind alone', function (): void {
        $project = hookProject(['notes.txt' => MESSY_PHP]);

        $run = runEditHook($project, editPayload($project.'/notes.txt'));

        expect($run['exit'])->toBe(0)
            ->and(file_get_contents($project.'/notes.txt'))->toBe(MESSY_PHP);
    });

    it('leaves a file outside the project alone', function (): void {
        $project = hookProject();
        $elsewhere = hookProject(['src/A.php' => MESSY_PHP]);

        $run = runEditHook($project, editPayload($elsewhere.'/src/A.php'));

        expect($run['exit'])->toBe(0)
            ->and(file_get_contents($elsewhere.'/src/A.php'))->toBe(MESSY_PHP);
    });

    it('leaves the Workbench generated tree to the build', function (): void {
        $project = hookProject(['workbench/app/Http/Generated/Request.php' => MESSY_PHP]);

        $run = runEditHook($project, editPayload($project.'/workbench/app/Http/Generated/Request.php'));

        expect($run['exit'])->toBe(0)
            ->and(file_get_contents($project.'/workbench/app/Http/Generated/Request.php'))->toBe(MESSY_PHP);
    });

    // The hook must never fail an edit. A file caught half-written does not parse,
    // and Pint says so; the editor needs to hear it, and nothing more.
    it('reports a file that does not parse and still exits 0', function (): void {
        $broken = "<?php\nclass A { public function f( ) {";
        $project = hookProject(['src/Broken.php' => $broken]);

        $run = runEditHook($project, editPayload($project.'/src/Broken.php'));

        expect($run['exit'])->toBe(0)
            ->and($run['err'])->toContain('Pint failed on src/Broken.php')
            ->and(file_get_contents($project.'/src/Broken.php'))->toBe($broken);
    })->skip(fn (): bool => ! is_executable(hookRepository().'/vendor/bin/pint'), 'Pint is not installed');

    it('exits 0 whatever it is given', function (string $payload): void {
        $run = runEditHook(hookProject(), $payload);

        expect($run['exit'])->toBe(0);
    })->with([
        'an empty payload' => [''],
        'something that is not JSON' => ['not json'],
        'JSON without a file path' => ['{"tool_name":"Edit","tool_input":{}}'],
        'a file that does not exist' => ['{"tool_input":{"file_path":"/nowhere/at/all.php"}}'],
        'a path that is not a string' => ['{"tool_input":{"file_path":42}}'],
    ]);
});

describe('the pre-commit hook', function (): void {
    it('refuses a commit with an unformatted PHP file, names it and prints the fix', function (): void {
        $project = stagedProject(['src/Long.php' => "<?php\n\n/**\n * Short.\n * Lines.\n */\nfinal class A {}\n"]);

        $commit = hookGit($project, ['commit', '-q', '-m', 'x']);

        expect($commit['exit'])->not->toBe(0)
            ->and($commit['err'])->toContain("  src/Long.php\n")
            ->and($commit['err'])->toContain("\n  composer format\n")
            ->and($commit['err'])->not->toContain('format:md')
            ->and(hookGit($project, ['rev-parse', '--verify', '-q', 'HEAD'])['exit'])->not->toBe(0)
            ->and(file_get_contents($project.'/src/Long.php'))->toBe("<?php\n\n/**\n * Short.\n * Lines.\n */\nfinal class A {}\n");
    });

    it('lets a commit through when every staged PHP file is formatted', function (): void {
        $project = stagedProject(['src/Fine.php' => "<?php\n\n/**\n * Short. Lines.\n */\nfinal class A {}\n"]);

        expect(hookGit($project, ['commit', '-q', '-m', 'x'])['exit'])->toBe(0);
    });

    it('lets a commit through when the unformatted file is not staged', function (): void {
        $project = stagedProject(['src/Fine.php' => "<?php\n\nfinal class A {}\n"]);
        file_put_contents($project.'/src/Other.php', "<?php\n\n/**\n * Short.\n * Lines.\n */\nfinal class B {}\n");

        expect(hookGit($project, ['commit', '-q', '-m', 'x'])['exit'])->toBe(0);
    });

    it('lets a commit through when a file the formatter never reads is staged', function (): void {
        $project = stagedProject(['tests/Fixtures/Golden.php' => "<?php\n\n/**\n * Short.\n * Lines.\n */\nfinal class A {}\n"]);

        expect(hookGit($project, ['commit', '-q', '-m', 'x'])['exit'])->toBe(0);
    });

    it('names a path with a space as one file', function (): void {
        $project = stagedProject(['src/Two Words.php' => "<?php\n\n/**\n * Short.\n * Lines.\n */\nfinal class A {}\n"]);

        $commit = hookGit($project, ['commit', '-q', '-m', 'x']);

        expect($commit['exit'])->not->toBe(0)
            ->and($commit['err'])->toContain("  src/Two Words.php\n");
    });

    // A `@param` whose type is 63 columns wide leaves 34 columns for its description at the repository's width of 120,
    // under the 40 of its `.editorconfig`.
    it('shows a cramped tag as a warning and lets the commit through', function (): void {
        $type = 'array<string, array<string, array<string, array<string, int>>>>';
        $project = stagedProject(['src/Cramped.php' => "<?php\n\n/**\n * @param  {$type}  \$content  content\n */\nfunction f(array \$content): void {}\n"]);

        $commit = hookGit($project, ['commit', '-q', '-m', 'x']);

        expect($commit['exit'])->toBe(0, $commit['err'])
            ->and($commit['err'])->toContain("  src/Cramped.php:4, 34 columns left (@param)\n")
            ->and($commit['err'])->not->toContain('not formatted');
    });

    it('shows the warnings along with the files it refuses', function (): void {
        $type = 'array<string, array<string, array<string, array<string, int>>>>';
        $project = stagedProject([
            'src/Cramped.php' => "<?php\n\n/**\n * @param  {$type}  \$content  content\n */\nfunction f(array \$content): void {}\n",
            'src/Long.php' => "<?php\n\n/**\n * Short.\n * Lines.\n */\nfinal class A {}\n",
        ]);

        $commit = hookGit($project, ['commit', '-q', '-m', 'x']);

        expect($commit['exit'])->not->toBe(0)
            ->and($commit['err'])->toContain("  src/Cramped.php:4, 34 columns left (@param)\n")
            ->and($commit['err'])->toContain("  src/Long.php\n");
    });

    it('refuses an unformatted Markdown file and prints its fix', function (): void {
        $project = stagedProject(['docs/page.md' => LONG_MARKDOWN]);

        $commit = hookGit($project, ['commit', '-q', '-m', 'x']);

        expect($commit['exit'])->not->toBe(0)
            ->and($commit['err'])->toContain("  docs/page.md\n")
            ->and($commit['err'])->toContain("\n  composer format:md\n")
            ->and(file_get_contents($project.'/docs/page.md'))->toBe(LONG_MARKDOWN);
    })->skip(fn (): bool => ! hookHasNode(), 'Node is not installed');

    it('prints one command that fixes both kinds when both are unformatted', function (): void {
        $project = stagedProject([
            'src/Long.php' => "<?php\n\n/**\n * Short.\n * Lines.\n */\nfinal class A {}\n",
            'docs/page.md' => LONG_MARKDOWN,
        ]);

        $commit = hookGit($project, ['commit', '-q', '-m', 'x']);

        expect($commit['exit'])->not->toBe(0)
            ->and($commit['err'])->toContain("\n  composer format && composer format:md\n");
    })->skip(fn (): bool => ! hookHasNode(), 'Node is not installed');

    it('lets a formatted Markdown file through', function (): void {
        $project = stagedProject(['docs/page.md' => FORMATTED_MARKDOWN]);

        expect(hookGit($project, ['commit', '-q', '-m', 'x'])['exit'])->toBe(0);
    })->skip(fn (): bool => ! hookHasNode(), 'Node is not installed');
});

// The Claude Code settings are shared at the root and personal everywhere else: a contributor who starts Claude Code in
// `workbench/` and clicks "always allow" must not be one `git add -A` from publishing the result.
describe('.gitignore', function (): void {
    it('tracks the root .claude/settings.json and nothing else under a .claude folder', function (): void {
        $paths = [
            '.claude/settings.json',
            '.claude/settings.local.json',
            '.claude/agents/card-reviewer.md',
            'workbench/.claude/settings.local.json',
            'workbench/.claude/settings.json',
            'src/Deep/.claude/settings.local.json',
        ];
        $project = hookProject(array_fill_keys($paths, "{}\n") + ['.gitignore' => (string) file_get_contents(hookRepository().'/.gitignore')]);

        hookGit($project, ['init', '-q']);

        // No global ignore file, which would hide a folder on one machine and not on the next.
        $status = hookGit($project, ['-c', 'core.excludesFile=/dev/null', 'status', '--porcelain', '--untracked-files=all']);
        $untracked = array_map(static fn (string $line): string => substr($line, 3), array_filter(explode("\n", $status['out'])));

        expect(array_intersect($untracked, $paths))->toBe(['.claude/settings.json']);
    });
});

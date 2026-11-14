<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * scripts/format-docblocks.php, through its command line: a file in, a file out.
 * That is the only seam it has, and the one a contributor, the pre-commit hook and
 * the editor hook all use.
 *
 * Every case runs at 40 columns, from a scratch project with its own
 * `.editorconfig`, so an expected docblock is short enough to be read and checked
 * by hand. The expected text is written out, never computed: a formatter tested
 * against its own wrapping rule only proves it agrees with itself. One test reads
 * the repository's real `.editorconfig` to pin the 120 the card asks for.
 *
 * What the formatter must leave alone is as much of the contract as what it
 * rewraps, so those cases carry the same weight.
 *
 * @see CONTRIBUTING.md — "Docblock width"
 */

/**
 * The scratch projects of this test, removed by the hook below. Asking for them with `$forget` hands them over and
 * empties the list.
 *
 * @return array<int, string>
 */
function docblockScratchDirectories(?string $created = null, bool $forget = false): array
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
    foreach (docblockScratchDirectories(forget: true) as $directory) {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
});

/**
 * A project holding these files, with a PHP width of `$width` in its `.editorconfig`.
 *
 * @param  array<string, string>  $files  path relative to the project, then contents
 */
function docblockProject(array $files = [], ?int $width = 40): string
{
    $directory = sys_get_temp_dir().'/lsf-docblocks-'.bin2hex(random_bytes(6));
    mkdir($directory);
    docblockScratchDirectories($directory);

    // The Markdown section carries a width of its own, so a formatter that read
    // the wrong section would show it.
    $editorConfig = "root = true\n\n[*]\nindent_size = 4\n\n[*.md]\nmax_line_length = 80\n";

    if ($width !== null) {
        $editorConfig .= "\n[*.php]\nmax_line_length = {$width}\n";
    }

    $files = ['.editorconfig' => $editorConfig] + $files;

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($directory.'/'.$path))) {
            mkdir(dirname($directory.'/'.$path), recursive: true);
        }

        file_put_contents($directory.'/'.$path, $contents);
    }

    return $directory;
}

/**
 * The formatter run with these arguments.
 *
 * @param  list<string>  $arguments
 * @return array{exit: int, out: string, err: string}
 */
function runDocblockFormatter(array $arguments): array
{
    $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/scripts/format-docblocks.php', ...$arguments]);
    $process->run();

    return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput()];
}

/**
 * A PHP file, written to a scratch project and formatted in place at `$width`.
 */
function reflowedFile(string $source, int $width = 40): string
{
    $directory = docblockProject(['File.php' => $source], $width);
    $run = runDocblockFormatter(['--root='.$directory, $directory.'/File.php']);

    expect($run['exit'])->toBe(0, $run['err']);

    return (string) file_get_contents($directory.'/File.php');
}

/**
 * A docblock, written at the column of a method inside a class and formatted. The docblock goes in and comes out at
 * column 0, so a case reads without the indent.
 */
function reflowedDocblock(string $docblock, int $width = 40): string
{
    $indented = implode("\n", array_map(
        static fn (string $line): string => $line === '' ? '' : '    '.$line,
        explode("\n", $docblock),
    ));

    $lines = explode("\n", reflowedFile("<?php\n\nfinal class A\n{\n{$indented}\n    public function f(): void {}\n}\n", $width));

    // Four lines of class opening above the docblock, three of method and end below.
    return implode("\n", array_map(
        static fn (string $line): string => str_starts_with($line, '    ') ? substr($line, 4) : $line,
        array_slice($lines, 4, count($lines) - 7),
    ));
}

/**
 * Docblocks the formatter rewraps, at 40 columns: the text a paragraph, a list item or a prose tag is written as, then
 * what it becomes.
 *
 * @return array<string, array{string, string}>
 */
function rewrappedDocblocks(): array
{
    return [
        'a paragraph is unwrapped, then wrapped to the width' => [
            <<<'DOC'
            /**
             * The quick brown fox
             * jumps over the lazy dog and keeps
             * running far away. It never
             * stops.
             */
            DOC,
            <<<'DOC'
            /**
             * The quick brown fox jumps over
             * the lazy dog and keeps running
             * far away. It never stops.
             */
            DOC,
        ],
        'short lines are joined' => [
            <<<'DOC'
            /**
             * Every
             * line
             * is
             * short.
             */
            DOC,
            <<<'DOC'
            /**
             * Every line is short.
             */
            DOC,
        ],
        'blank lines between paragraphs stay' => [
            <<<'DOC'
            /**
             * First paragraph that is long enough to wrap.
             *
             * Second paragraph that is long enough to wrap too.
             */
            DOC,
            <<<'DOC'
            /**
             * First paragraph that is long
             * enough to wrap.
             *
             * Second paragraph that is long
             * enough to wrap too.
             */
            DOC,
        ],
        'a list item keeps its marker and hangs under its text' => [
            <<<'DOC'
            /**
             * - first item is long enough to wrap
             * - second
             *   continues
             * 1. a numbered item long enough to wrap
             *    around
             */
            DOC,
            <<<'DOC'
            /**
             * - first item is long enough to
             *   wrap
             * - second continues
             * 1. a numbered item long enough to
             *    wrap around
             */
            DOC,
        ],
        'a nested list item hangs under its own text' => [
            <<<'DOC'
            /**
             * - outer item
             *   - inner item that is long enough to wrap
             */
            DOC,
            <<<'DOC'
            /**
             * - outer item
             *   - inner item that is long
             *     enough to wrap
             */
            DOC,
        ],
        'a paragraph under a list item keeps its indent' => [
            <<<'DOC'
            /**
             * - an item
             *
             *   a continuation paragraph that is long enough to wrap
             */
            DOC,
            <<<'DOC'
            /**
             * - an item
             *
             *   a continuation paragraph that
             *   is long enough to wrap
             */
            DOC,
        ],
        'a backtick span is never split' => [
            <<<'DOC'
            /**
             * Always use `a span that stays whole` here.
             */
            DOC,
            <<<'DOC'
            /**
             * Always use
             * `a span that stays whole` here.
             */
            DOC,
        ],
        'a backtick span written over two lines is rejoined whole' => [
            <<<'DOC'
            /**
             * Always use `a span
             * that stays whole` here.
             */
            DOC,
            <<<'DOC'
            /**
             * Always use
             * `a span that stays whole` here.
             */
            DOC,
        ],
        'an inline tag is never split' => [
            <<<'DOC'
            /**
             * Read the docs, {@see Some\Class::method()} please.
             */
            DOC,
            <<<'DOC'
            /**
             * Read the docs,
             * {@see Some\Class::method()}
             * please.
             */
            DOC,
        ],
        'a generic type is never split' => [
            <<<'DOC'
            /**
             * Returns a list of array<string, Schema> keyed by name.
             */
            DOC,
            <<<'DOC'
            /**
             * Returns a list of
             * array<string, Schema> keyed by
             * name.
             */
            DOC,
        ],
        'a URL longer than the line sits on a line of its own' => [
            <<<'DOC'
            /**
             * See https://example.com/a/very/long/url/that/passes/the/width now.
             */
            DOC,
            <<<'DOC'
            /**
             * See
             * https://example.com/a/very/long/url/that/passes/the/width
             * now.
             */
            DOC,
        ],
        'a dash that would open a line stays on the line before' => [
            <<<'DOC'
            /**
             * Choose one of the options beyond - or none.
             */
            DOC,
            <<<'DOC'
            /**
             * Choose one of the options
             * beyond - or none.
             */
            DOC,
        ],
        'a number that would open a list stays on the line before' => [
            <<<'DOC'
            /**
             * Choose one of the options beyond 2024. Fine.
             */
            DOC,
            <<<'DOC'
            /**
             * Choose one of the options
             * beyond 2024. Fine.
             */
            DOC,
        ],
        'a mention that would open a tag stays on the line before' => [
            <<<'DOC'
            /**
             * Ask the maintainers of this repo @mona now.
             */
            DOC,
            <<<'DOC'
            /**
             * Ask the maintainers of this
             * repo @mona now.
             */
            DOC,
        ],
        'an internal tag hangs under its text' => [
            <<<'DOC'
            /**
             * @internal Not public API, a detail of how the thing works.
             */
            DOC,
            <<<'DOC'
            /**
             * @internal Not public API, a
             *           detail of how the thing
             *           works.
             */
            DOC,
        ],
        'a deprecated tag hangs under its text' => [
            <<<'DOC'
            /**
             * @deprecated Use the other thing instead.
             */
            DOC,
            <<<'DOC'
            /**
             * @deprecated Use the other thing
             *             instead.
             */
            DOC,
        ],
    ];
}

/**
 * Tag descriptions the formatter rewraps, at 60 columns: a `@param`, `@return`, `@throws` or `@var` continues under the
 * start of its description, which is where Pint's `phpdoc_align` hangs it.
 *
 * @return array<string, array{string, string}>
 */
function rewrappedTagDocblocks(): array
{
    return [
        'a param hangs under the start of its description' => [
            <<<'DOC'
            /**
             * @param  string  $name  the name of the thing that is far too long for one line
             */
            DOC,
            <<<'DOC'
            /**
             * @param  string  $name  the name of the thing that is
             *                        far too long for one line
             */
            DOC,
        ],
        'a param continuation written anywhere is joined and hung' => [
            <<<'DOC'
            /**
             * @param  string  $name  the name of
             *     the thing that is far too long for one line
             * @param  int  $age  the age
             */
            DOC,
            <<<'DOC'
            /**
             * @param  string  $name  the name of the thing that is
             *                        far too long for one line
             * @param  int  $age  the age
             */
            DOC,
        ],
        'a return hangs under the start of its description' => [
            <<<'DOC'
            /**
             * @return list<string> the names, in the order the document writes them down
             */
            DOC,
            <<<'DOC'
            /**
             * @return list<string> the names, in the order the
             *                      document writes them down
             */
            DOC,
        ],
        'a throws hangs under the start of its description' => [
            <<<'DOC'
            /**
             * @throws \RuntimeException when the thing is far past the limit of sixty columns
             */
            DOC,
            <<<'DOC'
            /**
             * @throws \RuntimeException when the thing is far past
             *                           the limit of sixty columns
             */
            DOC,
        ],
        'a var hangs under the start of its description' => [
            <<<'DOC'
            /**
             * @var array<string, Schema> $schemas keyed by name, in the order the document writes them
             */
            DOC,
            <<<'DOC'
            /**
             * @var array<string, Schema> $schemas keyed by name, in
             *                                     the order the
             *                                     document writes
             *                                     them
             */
            DOC,
        ],
        'a var without a name hangs under the description' => [
            <<<'DOC'
            /**
             * @var list<string> the names, in the order the document writes them down
             */
            DOC,
            <<<'DOC'
            /**
             * @var list<string> the names, in the order the
             *                   document writes them down
             */
            DOC,
        ],
        'a variadic param keeps its dots' => [
            <<<'DOC'
            /**
             * @param  string  ...$names  the names, in the order the document writes them down
             */
            DOC,
            <<<'DOC'
            /**
             * @param  string  ...$names  the names, in the order
             *                            the document writes them
             *                            down
             */
            DOC,
        ],
        'a param whose type has spaces in it keeps the type whole' => [
            <<<'DOC'
            /**
             * @param  array{id: int}  $row  the row of the table that was read from the file
             */
            DOC,
            <<<'DOC'
            /**
             * @param  array{id: int}  $row  the row of the table
             *                               that was read from the
             *                               file
             */
            DOC,
        ],
        'a backtick span in a description is never split' => [
            <<<'DOC'
            /**
             * @return string the `components.schemas` key that names the schema in the document
             */
            DOC,
            <<<'DOC'
            /**
             * @return string the `components.schemas` key that
             *                names the schema in the document
             */
            DOC,
        ],
    ];
}

/**
 * Docblocks the formatter must leave exactly as written, long lines and all.
 *
 * @return array<string, array{string}>
 */
function untouchedDocblocks(): array
{
    return [
        'a code fence' => [<<<'DOC'
        /**
         * Before the code.
         *
         * ```php
         * $a = ['a very long line that is far past the width'];
         * ```
         *
         * After.
         */
        DOC],
        'a tilde fence' => [<<<'DOC'
        /**
         * ~~~
         * a very long line that is far past the width
         * ~~~
         */
        DOC],
        'a fence that never closes' => [<<<'DOC'
        /**
         * ```
         * a very long line that is far past the width
         * and another one that is just as long as it
         */
        DOC],
        'an indented code block' => [<<<'DOC'
        /**
         * Before the code.
         *
         *     $a = ['a very long line that is far past the width'];
         *
         * After.
         */
        DOC],
        'a pre block' => [<<<'DOC'
        /**
         * <pre>
         * a very long line that is far past the width
         *
         * and another one that is just as long as it
         * </pre>
         */
        DOC],
        'a table' => [<<<'DOC'
        /**
         * | Column | Another column far past the width |
         * | ------ | --------------------------------- |
         */
        DOC],
        'a heading' => [<<<'DOC'
        /**
         * # A heading that is far past the width of the line
         */
        DOC],
        'a quote' => [<<<'DOC'
        /**
         * > A quote that is far past the width of the line
         * and its lazy continuation.
         */
        DOC],
        'a backslash hard break' => [<<<'DOC'
        /**
         * A line that ends with a backslash, far past\
         * the width.
         */
        DOC],
        'hand-aligned lines' => [<<<'DOC'
        /**
         * Options:
         *     one thing
         *     another thing that is long enough to be past the limit
         */
        DOC],
        'a param whose type cannot be told from its description' => [<<<'DOC'
        /**
         * @param  callable(int, string): void  $callback  far past the limit of forty columns, so it stays
         */
        DOC],
        'a param whose type is written over two lines' => [<<<'DOC'
        /**
         * @param  array{
         *     name: string,
         * }  $row  far past the limit of forty columns, so it stays
         */
        DOC],
        'a param with no description' => [<<<'DOC'
        /**
         * @param  array<string, array<string, Schema>>  $content
         */
        DOC],
        'a see tag' => [<<<'DOC'
        /**
         * @see docs/guide/openapi-support.md — "Where a schema is reported from"
         */
        DOC],
        'a link tag' => [<<<'DOC'
        /**
         * @link https://example.com/a/very/long/url/that/passes/the/width
         */
        DOC],
        'a template tag' => [<<<'DOC'
        /**
         * @template TKey of array-key with a description far past the limit
         */
        DOC],
        'a phpstan tag over two lines' => [<<<'DOC'
        /**
         * @phpstan-return array<string,
         *     int>
         */
        DOC],
        'a psalm tag' => [<<<'DOC'
        /**
         * @psalm-param array<string, Schema> $content far past the limit
         */
        DOC],
        'a tag followed by a blank line and a code block' => [<<<'DOC'
        /**
         * @example
         *
         *     $a = ['a very long line that is far past the width'];
         */
        DOC],
    ];
}

it('rewraps prose to the width', function (string $input, string $expected): void {
    expect(reflowedDocblock($input))->toBe($expected);
})->with(rewrappedDocblocks());

it('rewraps the description of a param, return, throws or var', function (string $input, string $expected): void {
    expect(reflowedDocblock($input, 60))->toBe($expected);
})->with(rewrappedTagDocblocks());

it('changes nothing it has already wrapped under a tag', function (string $input, string $expected): void {
    expect(reflowedDocblock($expected, 60))->toBe($expected);
})->with(rewrappedTagDocblocks());

// Idempotence is the property that lets the formatter run on every edit and
// every commit: a second run finds nothing to do. Asserted on every expected
// output above, which is a docblock the formatter has just produced.
it('changes nothing it has already wrapped', function (string $input, string $expected): void {
    expect(reflowedDocblock($expected))->toBe($expected);
})->with(rewrappedDocblocks());

it('leaves this inside a docblock alone', function (string $docblock): void {
    expect(reflowedDocblock($docblock))->toBe($docblock);
})->with(untouchedDocblocks());

// Only T_DOC_COMMENT tokens are rewritten. A comment that merely resembles
// one, or a docblock that is not shaped like the formatter's, is left alone.
it('leaves everything outside a docblock alone', function (): void {
    $source = <<<'PHP'
    <?php

    // A line comment that is far past the forty columns the width allows.
    # A hash comment that is far past the forty columns the width allows.
    /* A block comment that is far past the forty columns the width allows. */
    $text = '/** A string that is far past the forty columns the width allows. */';
    $note = <<<'NOTE'
    /**
     * A heredoc that is far past the forty columns the width allows.
     */
    NOTE;
    $a = 1; /**
     * A docblock after code, far past the forty columns the width allows.
     */
    /** A one-line docblock that is far past the forty columns the width allows. */
    /**
       A docblock without its gutter, far past the forty columns the width allows.
     */

    PHP;

    expect(reflowedFile($source))->toBe($source);
});

it('wraps a docblock at the width of its own indent', function (): void {
    $source = "<?php\n\n/**\n * Alpha beta gamma delta epsilon zeta eta theta iota kappa.\n */\nfinal class A {}\n";

    // At column 0 the text has 37 columns; at column 4 it would have 33.
    expect(reflowedFile($source))->toBe(
        "<?php\n\n/**\n * Alpha beta gamma delta epsilon zeta\n * eta theta iota kappa.\n */\nfinal class A {}\n",
    );
});

it('reads the width of PHP files from the .editorconfig, and nothing else', function (): void {
    $source = "<?php\n\n/**\n * Alpha beta gamma delta epsilon zeta eta theta iota kappa.\n */\nfinal class A {}\n";

    // 80 is the Markdown section's width, and the whole sentence would fit in it.
    expect(reflowedFile($source, 30))->toBe(
        "<?php\n\n/**\n * Alpha beta gamma delta\n * epsilon zeta eta theta iota\n * kappa.\n */\nfinal class A {}\n",
    );
});

// Run without --root, so the width is the repository's own `.editorconfig`, which
// the file under test sits outside of. Eleven words of ten letters make a line of
// 119 columns of text plus the gutter, one more than a docblock at column 0 holds.
it('wraps at the 120 columns the repository declares', function (): void {
    $word = str_repeat('a', 10);
    $directory = docblockProject(['File.php' => "<?php\n\n/**\n * ".implode(' ', array_fill(0, 11, $word))."\n */\nfinal class A {}\n"]);

    $run = runDocblockFormatter([$directory.'/File.php']);

    expect($run['exit'])->toBe(0, $run['err'])
        ->and(file_get_contents($directory.'/File.php'))->toBe(
            "<?php\n\n/**\n * ".implode(' ', array_fill(0, 10, $word))."\n * {$word}\n */\nfinal class A {}\n",
        );
});

describe('the command line', function (): void {
    $long = "<?php\n\n/**\n * Alpha beta gamma delta epsilon zeta eta theta iota kappa.\n */\nfinal class A {}\n";
    $wrapped = "<?php\n\n/**\n * Alpha beta gamma delta epsilon zeta\n * eta theta iota kappa.\n */\nfinal class A {}\n";

    it('lists a file that needs reflowing in --check mode, writes nothing and fails', function () use ($long): void {
        $directory = docblockProject(['src/Long.php' => $long]);

        $run = runDocblockFormatter(['--check', '--root='.$directory]);

        expect($run['exit'])->toBe(1)
            ->and($run['out'])->toBe("src/Long.php\n")
            ->and($run['err'])->toContain('composer format')
            ->and(file_get_contents($directory.'/src/Long.php'))->toBe($long);
    });

    it('passes --check when nothing needs reflowing', function () use ($wrapped): void {
        $directory = docblockProject(['src/Wrapped.php' => $wrapped]);

        $run = runDocblockFormatter(['--check', '--root='.$directory]);

        expect($run)->toBe(['exit' => 0, 'out' => '', 'err' => '']);
    });

    it('rewrites the file in place, names it, then finds nothing on a second run', function () use ($long, $wrapped): void {
        $directory = docblockProject(['src/Long.php' => $long]);

        $first = runDocblockFormatter(['--root='.$directory]);
        $second = runDocblockFormatter(['--check', '--root='.$directory]);

        expect($first['exit'])->toBe(0)
            ->and($first['out'])->toBe("src/Long.php\n")
            ->and(file_get_contents($directory.'/src/Long.php'))->toBe($wrapped)
            ->and($second['exit'])->toBe(0);
    });

    it('lists only the files that need it, and none of those it never reads', function () use ($long, $wrapped): void {
        $directory = docblockProject([
            'src/B.php' => $long,
            'src/A.php' => $long,
            'src/Done.php' => $wrapped,
            'src/notes.md' => $long,
            'tests/Fixtures/Golden.php' => $long,
            'workbench/app/Http/Generated/Request.php' => $long,
            'vendor/package/File.php' => $long,
            'node_modules/package/File.php' => $long,
            '.hidden/File.php' => $long,
        ]);

        $run = runDocblockFormatter(['--check', '--root='.$directory]);

        expect($run['out'])->toBe("src/A.php\nsrc/B.php\n");
    });

    it('formats only the paths it is given', function () use ($long, $wrapped): void {
        $directory = docblockProject(['src/A.php' => $long, 'src/B.php' => $long]);

        $run = runDocblockFormatter(['--root='.$directory, $directory.'/src/A.php']);

        expect($run['out'])->toBe("src/A.php\n")
            ->and(file_get_contents($directory.'/src/A.php'))->toBe($wrapped)
            ->and(file_get_contents($directory.'/src/B.php'))->toBe($long);
    });

    it('skips a generated file even when it is named', function () use ($long): void {
        $directory = docblockProject([
            'tests/Fixtures/Golden.php' => $long,
            'workbench/app/Http/Generated/Request.php' => $long,
        ]);

        $run = runDocblockFormatter([
            '--root='.$directory,
            $directory.'/tests/Fixtures/Golden.php',
            $directory.'/workbench/app/Http/Generated/Request.php',
        ]);

        expect($run)->toBe(['exit' => 0, 'out' => '', 'err' => ''])
            ->and(file_get_contents($directory.'/tests/Fixtures/Golden.php'))->toBe($long);
    });

    it('refuses a project whose .editorconfig sets no width for PHP', function (): void {
        $directory = docblockProject(['src/A.php' => "<?php\n"], width: null);

        $run = runDocblockFormatter(['--root='.$directory]);

        expect($run['exit'])->toBe(2)
            ->and($run['err'])->toContain('max_line_length');
    });

    it('refuses a path that does not exist', function (): void {
        $directory = docblockProject();

        $run = runDocblockFormatter(['--root='.$directory, $directory.'/missing.php']);

        expect($run['exit'])->toBe(2)
            ->and($run['err'])->toContain('does not exist');
    });

    it('refuses an option it does not know', function (): void {
        $run = runDocblockFormatter(['--chek']);

        expect($run['exit'])->toBe(2)
            ->and($run['err'])->toContain('Unknown option --chek');
    });
});

// The decision this formatter lives or dies by: its output must be something Pint
// leaves alone, or `composer format` and `composer format:check` would chase each
// other forever. Runs the real Pint with the repository's own configuration.
it('produces docblocks that Pint leaves alone', function (): void {
    $source = <<<'PHP'
    <?php

    declare(strict_types=1);

    final class A
    {
        /**
         * A summary that is quite a bit too long for the width, with `a code span` in it.
         *
         * - a list item that is far too long for the width, which hangs under its text
         * 1. a numbered item that is far too long for the width, which hangs under it
         *
         * @internal Not public API, a detail of how the thing works and why it is not yours.
         *
         * @param  string  $name  the name
         * @return list<string>
         */
        public function f(string $name): array
        {
            return [];
        }
    }

    PHP;

    $directory = docblockProject(['File.php' => $source]);
    $repository = dirname(__DIR__, 2);

    runDocblockFormatter(['--root='.$directory, $directory.'/File.php']);

    $pint = new Process([PHP_BINARY, $repository.'/vendor/bin/pint', '--test', '--config', $repository.'/pint.json', $directory.'/File.php']);
    $pint->run();

    expect(file_get_contents($directory.'/File.php'))->not->toBe($source)
        ->and($pint->getExitCode())->toBe(0, $pint->getOutput());
});

// D4's promise: a `@param`, `@return`, `@throws` or `@var` is wrapped where Pint's `phpdoc_align` hangs it, so the two
// tools agree. The fixture is as Pint writes it, with long descriptions, and comes out reflowed and still untouched by
// Pint.
it('wraps the typed tags where Pint hangs them', function (): void {
    $source = <<<'PHP'
    <?php

    declare(strict_types=1);

    final class A
    {
        /** @var array<string, Schema> keyed by name, in the order the document writes them, which is far too long for one line of a hundred columns */
        private array $schemas = [];

        /**
         * @param  array<string, array<string, Schema>>  $content  keyed by media type, in the order the document writes
         *     them down, which is far too long for one line of a hundred columns
         * @param  string  $name  short
         * @return list<string> the names, in the order the document writes them down, which is far too long for one line
         *     of a hundred columns
         *
         * @throws RuntimeException when the document cannot be read, or when it is far too long for one line of a
         *     hundred columns
         */
        public function f(array $content, string $name): array
        {
            return [];
        }
    }

    PHP;

    $directory = docblockProject(['File.php' => $source], 100);
    $repository = dirname(__DIR__, 2);

    $run = runDocblockFormatter(['--root='.$directory, $directory.'/File.php']);
    $pint = new Process([PHP_BINARY, $repository.'/vendor/bin/pint', '--test', '--config', $repository.'/pint.json', $directory.'/File.php']);
    $pint->run();

    $reflowed = (string) file_get_contents($directory.'/File.php');

    expect($run['exit'])->toBe(0, $run['err'])
        ->and($reflowed)->toContain(<<<'DOC'
                 * @param  array<string, array<string, Schema>>  $content  keyed by media type, in the order the
                 *                                                         document writes them down, which is
                 *                                                         far too long for one line of a
                 *                                                         hundred columns
                 * @param  string  $name  short
                 * @return list<string> the names, in the order the document writes them down, which is far too
                 *                      long for one line of a hundred columns
                 *
                 * @throws RuntimeException when the document cannot be read, or when it is far too long for one
                 *                          line of a hundred columns
                 */
            DOC)
        ->and($pint->getExitCode())->toBe(0, $pint->getOutput());
});

#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Reflow the prose of every PHP docblock to the column limit in .editorconfig.
 *
 * Pint (PHP-CS-Fixer) aligns tags and orders them, but nothing in it rewraps a
 * paragraph, and no mainstream PHP tool does. This is the PHP counterpart of
 * scripts/format-markdown.sh: one job, the same shape. It unwraps each
 * paragraph and wraps it again, the way Prettier's `proseWrap: always` does,
 * so a one-word edit in the middle of a paragraph stops leaving the lines
 * ragged.
 *
 * Only T_DOC_COMMENT tokens are rewritten, found through token_get_all(), so
 * code is never touched. Inside a docblock, only prose is: a paragraph, a
 * list item (marker and hanging indent kept) and the description of the tags
 * listed in PROSE_TAGS and TYPED_TAGS. Everything else is copied byte for byte:
 * code fences and indented code blocks, tables, headings, quotes, HTML, every
 * other tag line and its continuation. Inside prose, a backtick span, an inline
 * `{@tag}` and a generic type such as `array<string, Schema>` are never split,
 * and neither is a URL, which has no space to split at.
 *
 * A `@param`, `@return`, `@throws` or `@var` continues under the start of its
 * description, which is where Pint's `phpdoc_align` hangs it, so the two agree.
 * What comes before the description (the tag, its type, its name) is kept as
 * written and never split. A tag whose type cannot be told from its description
 * (`callable(int): void`, a type written over two lines) is left as it is.
 *
 * What it does not touch, on purpose:
 *
 *   - `@type`, `@property*`, `@method`, `@see`, `@link`, `@template`,
 *     `@phpstan-*`, `@psalm-*` and any tag not listed above: they carry paths,
 *     URLs and type expressions that a rewrap could break.
 *   - The generated goldens (tests/Fixtures, workbench/app/Http/Generated):
 *     they come from the emitters, which own their wrap width.
 *
 * Usage:
 *
 *   php scripts/format-docblocks.php [--check | --report] [--root=DIR] [path ...]
 *
 * With no path, every PHP file under the root. `--check` writes nothing, lists
 * the files that would change, and exits 1 when there are any. `--root` is for
 * the tests; it defaults to the repository this script lives in. The width is
 * `max_line_length` from the `[*.php]` section of the root's .editorconfig, so
 * it is never configured twice. Exit codes: 0 clean, 1 files differ (check
 * mode), 2 usage or configuration error.
 *
 * Cramped tags. A `@param`, `@return`, `@throws` or `@var` hangs its
 * continuation under the start of its description, so a long type and name
 * leave the description little room. A tag with fewer columns left on its first
 * line than `docblock_min_description_width` in the same .editorconfig section
 * is "cramped". That is watched, never enforced: `--check` prints one
 * `warning: file:line, N columns left (@tag)` line per cramped tag on stderr
 * and a warning alone does not change the exit code; `--report` prints them all
 * on stdout, the worst first, with a total and the share of described tags, and
 * exits 0. Without the key there is nothing to compare to: `--check` stays
 * silent and `--report` is a configuration error.
 */

/** Tags that are all prose after the tag: the description hangs under the text that follows the tag. */
const PROSE_TAGS = ['internal', 'deprecated', 'since', 'todo'];

/** Tags with a type, and for two of them a name, before the description: it hangs under the description's start. */
const TYPED_TAGS = ['param', 'return', 'throws', 'var'];

/** The `.editorconfig` key, beside `max_line_length`, for the room a tag's description needs on its first line. */
const MIN_DESCRIPTION_KEY = 'docblock_min_description_width';

/** Directories that are never read, relative to the root. */
const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'build', 'worktrees', 'tests/Fixtures', 'workbench/app/Http/Generated'];

/**
 * @param  list<string>  $argv
 */
function main(array $argv): int
{
    $check = false;
    $report = false;
    $root = dirname(__DIR__);
    $paths = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--check') {
            $check = true;
        } elseif ($argument === '--report') {
            $report = true;
        } elseif (str_starts_with($argument, '--root=')) {
            $root = substr($argument, strlen('--root='));
        } elseif (str_starts_with($argument, '--')) {
            fwrite(STDERR, "Unknown option {$argument}. Usage: format-docblocks.php [--check | --report] [--root=DIR] [path ...]\n");

            return 2;
        } else {
            $paths[] = $argument;
        }
    }

    $resolvedRoot = realpath($root);

    if ($resolvedRoot === false) {
        fwrite(STDERR, "The root {$root} does not exist.\n");

        return 2;
    }

    $editorConfig = $resolvedRoot.'/.editorconfig';
    $width = editorConfigSetting($editorConfig, 'max_line_length');
    $minimum = editorConfigSetting($editorConfig, MIN_DESCRIPTION_KEY);

    if ($width === null) {
        fwrite(STDERR, "No max_line_length for PHP files in {$resolvedRoot}/.editorconfig. Add it under [*.php].\n");

        return 2;
    }

    $files = $paths === [] ? phpFilesUnder($resolvedRoot, $resolvedRoot) : explicitFiles($paths, $resolvedRoot);

    if ($files === null) {
        return 2;
    }

    if ($report) {
        return reportCrampedTags($files, $resolvedRoot, $width, $minimum);
    }

    $changed = 0;
    $warnings = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            fwrite(STDERR, "Cannot read {$file}.\n");

            return 2;
        }

        if ($check && $minimum !== null) {
            foreach (describedTags($source, $width) as $tag) {
                if ($tag['left'] < $minimum) {
                    $warnings[] = crampedLine(displayPath($file, $resolvedRoot), $tag);
                }
            }
        }

        $reflowed = reflowSource($source, $width);

        if ($reflowed === $source) {
            continue;
        }

        $changed++;
        echo displayPath($file, $resolvedRoot), "\n";

        if (! $check) {
            file_put_contents($file, $reflowed);
        }
    }

    foreach ($warnings as $warning) {
        fwrite(STDERR, "warning: {$warning}\n");
    }

    if ($check && $changed > 0) {
        fwrite(STDERR, "{$changed} file(s) have docblocks that need reflowing. Run `composer format` to fix them.\n");

        return 1;
    }

    return 0;
}

/**
 * What `--report` prints for a tag: where it is and how much room its description has.
 *
 * @param  array{line: int, left: int, tag: string}  $tag
 */
function crampedLine(string $file, array $tag): string
{
    return "{$file}:{$tag['line']}, {$tag['left']} columns left (@{$tag['tag']})";
}

/**
 * @param  list<string>  $files
 */
function reportCrampedTags(array $files, string $root, int $width, ?int $minimum): int
{
    if ($minimum === null) {
        fwrite(STDERR, 'No '.MIN_DESCRIPTION_KEY." for PHP files in {$root}/.editorconfig. Add it under [*.php].\n");

        return 2;
    }

    $described = 0;
    $cramped = [];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        if ($source === false) {
            fwrite(STDERR, "Cannot read {$file}.\n");

            return 2;
        }

        foreach (describedTags($source, $width) as $tag) {
            $described++;

            if ($tag['left'] < $minimum) {
                $cramped[] = ['file' => displayPath($file, $root)] + $tag;
            }
        }
    }

    // The worst first; the sort is stable, so ties stay in file and line order.
    usort($cramped, static fn (array $a, array $b): int => $a['left'] <=> $b['left']);

    foreach ($cramped as $tag) {
        echo crampedLine($tag['file'], $tag), "\n";
    }

    $share = $described === 0 ? 0.0 : 100 * count($cramped) / $described;

    echo $cramped === [] ? '' : "\n";
    echo sprintf("%d cramped of %d described tags (%.1f%%), fewer than %d columns left.\n", count($cramped), $described, $share, $minimum);

    return 0;
}

/**
 * Every `@param`, `@return`, `@throws` and `@var` that has a description, with the columns it leaves for that
 * description on its first line. A tag inside a code fence is not a tag.
 *
 * @return list<array{line: int, left: int, tag: string}>
 */
function describedTags(string $source, int $width): array
{
    $found = [];
    // What precedes the current token on its line: the first line of a docblock starts where the token does.
    $sinceNewline = '';

    foreach (token_get_all($source) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $newline = strrpos($text, "\n");
        $before = $sinceNewline;
        $sinceNewline = $newline === false ? $sinceNewline.$text : substr($text, $newline + 1);

        if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
            continue;
        }

        $fence = null;

        foreach (explode("\n", $token[1]) as $offset => $line) {
            // The gutter of a docblock line, or the opening of a one-line docblock, then what is written there.
            if (preg_match('/^(\s*(?:\*|\/\*\*) ?)(.*?)(?:\s*\*\/)?$/', $line, $parts) !== 1) {
                continue;
            }

            if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $parts[2], $opening) === 1) {
                $fence = $fence === null ? $opening[1] : (closesFence($parts[2], $fence) ? null : $fence);

                continue;
            }

            $head = $fence === null ? tagHead($parts[2]) : null;

            if ($head !== null && preg_match('/^@(\w+)/', $head, $name) === 1 && in_array($name[1], TYPED_TAGS, true)) {
                $found[] = [
                    'line' => $token[2] + $offset,
                    'left' => max(0, $width - ($offset === 0 ? textWidth($before) : 0) - textWidth($parts[1]) - textWidth($head)),
                    'tag' => $name[1],
                ];
            }
        }
    }

    return $found;
}

/**
 * The integer `$key` that applies to a PHP file, or null when none does. A later matching section wins, as in
 * EditorConfig.
 */
function editorConfigSetting(string $editorConfig, string $key): ?int
{
    $contents = is_file($editorConfig) ? file_get_contents($editorConfig) : false;

    if ($contents === false) {
        return null;
    }

    $applies = false;
    $value = null;

    foreach (explode("\n", $contents) as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $line, $section) === 1) {
            $applies = preg_match('/^\*{1,2}$|^\*\.php$|^\*\.\{(?:[^}]*,)?php(?:,[^}]*)?\}$/', $section[1]) === 1;

            continue;
        }

        if ($applies && preg_match('/^'.preg_quote($key, '/').'\s*=\s*(\S+)/i', $line, $setting) === 1) {
            $value = ctype_digit($setting[1]) ? (int) $setting[1] : null;
        }
    }

    return $value;
}

/**
 * @return list<string>
 */
function phpFilesUnder(string $directory, string $root): array
{
    $found = [];
    $entries = scandir($directory);

    if ($entries === false) {
        return $found;
    }

    sort($entries);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (isSkipped($path, $root)) {
            continue;
        }

        if (is_dir($path)) {
            array_push($found, ...phpFilesUnder($path, $root));
        } elseif (str_ends_with($entry, '.php')) {
            $found[] = $path;
        }
    }

    return $found;
}

/**
 * @param  list<string>  $paths
 * @return list<string>|null null after reporting a path that does not exist
 */
function explicitFiles(array $paths, string $root): ?array
{
    $found = [];

    foreach ($paths as $path) {
        $resolved = realpath($path);

        if ($resolved === false) {
            fwrite(STDERR, "{$path} does not exist.\n");

            return null;
        }

        if (isSkipped($resolved, $root)) {
            continue;
        }

        if (is_dir($resolved)) {
            array_push($found, ...phpFilesUnder($resolved, $root));
        } elseif (str_ends_with($resolved, '.php')) {
            $found[] = $resolved;
        }
    }

    return $found;
}

function isSkipped(string $path, string $root): bool
{
    if (! str_starts_with($path, $root.'/')) {
        return false;
    }

    $relative = substr($path, strlen($root) + 1);

    foreach (SKIPPED_DIRECTORIES as $skipped) {
        if ($relative === $skipped || str_starts_with($relative, $skipped.'/')) {
            return true;
        }
    }

    return false;
}

function displayPath(string $path, string $root): string
{
    return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
}

/**
 * The source with every docblock reflowed to $width columns.
 */
function reflowSource(string $source, int $width): string
{
    $result = '';
    // What precedes the current token on its line, to tell a docblock that opens
    // its line, and gives the indent of every line inside it, from one that
    // follows code.
    $sinceNewline = '';

    foreach (token_get_all($source) as $token) {
        $text = is_array($token) ? $token[1] : $token;

        if (is_array($token) && $token[0] === T_DOC_COMMENT && trim($sinceNewline) === '') {
            $text = reflowDocblock($text, $sinceNewline, $width);
        }

        $result .= $text;
        $newline = strrpos($text, "\n");
        $sinceNewline = $newline === false ? $sinceNewline.$text : substr($text, $newline + 1);
    }

    return $result;
}

/**
 * @param  string  $indent  the whitespace the docblock's first line starts with
 */
function reflowDocblock(string $docblock, string $indent, int $width): string
{
    $lines = explode("\n", $docblock);

    // A one-line docblock, one carrying text on its opening line, or a CRLF file
    // is not the shape this reflows. Leave it as it is.
    if (count($lines) < 3 || $lines[0] !== '/**' || $lines[count($lines) - 1] !== $indent.' */' || str_contains($docblock, "\r")) {
        return $docblock;
    }

    $raw = array_slice($lines, 1, -1);
    $content = [];

    foreach ($raw as $line) {
        if ($line === $indent.' *') {
            $content[] = '';
        } elseif (str_starts_with($line, $indent.' * ')) {
            $content[] = substr($line, strlen($indent) + 3);
        } else {
            // Not a ` * ` gutter: a docblock written some other way.
            return $docblock;
        }
    }

    $limit = $width - textWidth($indent) - 3;
    $out = [];
    $count = count($content);
    $i = 0;

    while ($i < $count) {
        $line = $content[$i];

        if (trim($line) === '') {
            $out[] = $raw[$i];
            $i++;

            continue;
        }

        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $fence) === 1) {
            $end = $i + 1;

            while ($end < $count && ! closesFence($content[$end], $fence[1])) {
                $end++;
            }

            $end = min($end, $count - 1);
            array_push($out, ...array_slice($raw, $i, $end - $i + 1));
            $i = $end + 1;

            continue;
        }

        if (preg_match('/^\s*<pre\b/i', $line) === 1) {
            $end = $i;

            while ($end < $count - 1 && stripos($content[$end], '</pre>') === false) {
                $end++;
            }

            array_push($out, ...array_slice($raw, $i, $end - $i + 1));
            $i = $end + 1;

            continue;
        }

        if (indentOf($line) >= 4) {
            $end = $i;

            while ($end + 1 < $count && (trim($content[$end + 1]) === '' || indentOf($content[$end + 1]) >= 4)) {
                $end++;
            }

            array_push($out, ...array_slice($raw, $i, $end - $i + 1));
            $i = $end + 1;

            continue;
        }

        if (isTagLine($line)) {
            $end = $i;

            while ($end + 1 < $count && trim($content[$end + 1]) !== '' && ! isTagLine($content[$end + 1])) {
                $end++;
            }

            $block = array_slice($content, $i, $end - $i + 1);
            $head = tagHead($line);
            $reflowed = $head === null ? null : reflowBlock($block, $head, $limit, $indent);

            array_push($out, ...($reflowed ?? array_slice($raw, $i, $end - $i + 1)));
            $i = $end + 1;

            continue;
        }

        $end = $i;

        while ($end + 1 < $count && endsNotBlock($content[$end + 1])) {
            $end++;
        }

        $block = array_slice($content, $i, $end - $i + 1);
        $reflowed = isListItem($line)
            ? reflowListItem($block, $limit, $indent)
            : reflowParagraph($block, $limit, $indent);

        array_push($out, ...($reflowed ?? array_slice($raw, $i, $end - $i + 1)));
        $i = $end + 1;
    }

    return implode("\n", [$lines[0], ...$out, $lines[count($lines) - 1]]);
}

/**
 * Whether the next line still belongs to the block that is being read: it is not blank, and it opens neither a tag, a
 * list item, a fence nor a `<pre>`.
 */
function endsNotBlock(string $next): bool
{
    return trim($next) !== ''
        && ! isTagLine($next)
        && ! isListItem($next)
        && preg_match('/^ {0,3}(`{3,}|~{3,})|^\s*<pre\b/i', $next) !== 1;
}

function closesFence(string $line, string $opening): bool
{
    return preg_match('/^ {0,3}'.preg_quote($opening[0], '/').'{'.strlen($opening).',}\s*$/', $line) === 1;
}

function isTagLine(string $line): bool
{
    return preg_match('/^@[A-Za-z]/', $line) === 1;
}

function isListItem(string $line): bool
{
    return preg_match('/^\s*(?:[-*+]|\d{1,9}[.)])(?:\s+\S|\s*$)/', $line) === 1;
}

function indentOf(string $line): int
{
    return strlen($line) - strlen(ltrim($line, ' '));
}

/**
 * Lines that stand for themselves in Markdown and so must not be joined to the line before them: a table row, a
 * heading, a quote, an HTML tag.
 */
function isStructural(string $line): bool
{
    return preg_match('/^\s*(?:\||>|#{1,6}(?:\s|$)|<[A-Za-z\/!])/', $line) === 1;
}

/**
 * Whether a block must be copied as it is: it contains a structural line, or a hard line break (two trailing spaces, or
 * a backslash) that joining would lose.
 *
 * @param  list<string>  $block
 */
function isPreformatted(array $block): bool
{
    $last = count($block) - 1;

    foreach ($block as $index => $line) {
        if (isStructural($line)) {
            return true;
        }

        if ($index < $last && (str_ends_with($line, '  ') || str_ends_with($line, '\\'))) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<string>  $block
 * @return list<string>|null null to copy the block unchanged
 */
function reflowParagraph(array $block, int $limit, string $indent): ?array
{
    $prefix = str_repeat(' ', indentOf($block[0]));

    if (isPreformatted($block)) {
        return null;
    }

    foreach ($block as $line) {
        // A line indented differently from its neighbours is hand-aligned
        // structure, which joining would flatten.
        if (indentOf($line) !== strlen($prefix)) {
            return null;
        }
    }

    return wrapAtoms(atomsOf(joinLines($block)), $prefix, $prefix, $limit, $indent);
}

/**
 * @param  list<string>  $block
 * @return list<string>|null
 */
function reflowListItem(array $block, int $limit, string $indent): ?array
{
    if (isPreformatted(array_slice($block, 1)) || preg_match('/^\s*(?:[-*+]|\d{1,9}[.)])\s+/', $block[0], $marker) !== 1) {
        return null;
    }

    $first = $marker[0];
    $block[0] = substr($block[0], strlen($first));

    if (isStructural($block[0])) {
        return null;
    }

    return wrapAtoms(atomsOf(joinLines($block)), $first, str_repeat(' ', textWidth($first)), $limit, $indent);
}

/**
 * What a tag line holds before its description, as written, when the tag is one whose description can be rewrapped; or
 * null. Everything after it is the description.
 *
 * For `@internal` and its kind that is the tag and one space. For `@param`, `@return`, `@throws` and `@var` it also
 * holds the type and, for `@param` and `@var`, the variable: `@param  array<string, Schema>  $content  `. A type that
 * cannot be told from the description that follows it, such as `callable(int): void`, gives null.
 */
function tagHead(string $line): ?string
{
    if (preg_match('/^@([A-Za-z][\w-]*)\s+\S/', $line, $tag) !== 1) {
        return null;
    }

    $name = $tag[1];

    if (in_array($name, PROSE_TAGS, true)) {
        return '@'.$name.' ';
    }

    if (! in_array($name, TYPED_TAGS, true)) {
        return null;
    }

    $length = strlen($line);
    $i = strlen($name) + 1;

    while ($i < $length && ($line[$i] === ' ' || $line[$i] === "\t")) {
        $i++;
    }

    // The type, up to the first space outside a bracket. A `$name` or `...$name` first means there is no type.
    if (preg_match('/\G(?:&\s*)?(?:\.\.\.)?\$/', $line, $variable, 0, $i) !== 1) {
        $depth = 0;
        $start = $i;

        for (; $i < $length; $i++) {
            $character = $line[$i];

            if (str_contains('<{([', $character)) {
                $depth++;
            } elseif (str_contains('>})]', $character)) {
                $depth--;
            } elseif (($character === ' ' || $character === "\t") && $depth === 0) {
                break;
            }
        }

        $type = substr($line, $start, $i - $start);

        // Unbalanced, or a type that goes on after the space: `callable(): void`, `A | B`.
        if ($depth !== 0 || preg_match('/[|&:,?]$/', $type) === 1 || preg_match('/\G\s*[|&:]/', $line, $more, 0, $i) === 1) {
            return null;
        }

        while ($i < $length && ($line[$i] === ' ' || $line[$i] === "\t")) {
            $i++;
        }
    }

    if ($name !== 'return' && $name !== 'throws' && preg_match('/\G(?:&\s*)?(?:\.\.\.)?\$\w+/', $line, $variable, 0, $i) === 1) {
        $i += strlen($variable[0]);

        while ($i < $length && ($line[$i] === ' ' || $line[$i] === "\t")) {
            $i++;
        }
    }

    return $i < $length ? substr($line, 0, $i) : null;
}

/**
 * A tag's description, with a continuation hung under where the description starts.
 *
 * @param  list<string>  $block
 * @param  string  $head  the tag line up to the description, from tagHead()
 * @return list<string>|null
 */
function reflowBlock(array $block, string $head, int $limit, string $indent): ?array
{
    $continuation = array_slice($block, 1);

    // A list or a fenced block under a tag is structure, and joining it into the tag's prose would flatten it. The hang
    // this writes never opens either, since atomsOf() glues such a word to the one before it.
    foreach ($continuation as $line) {
        if (! endsNotBlock($line) || preg_match('/^\s*(`{3,}|~{3,})/', $line) === 1) {
            return null;
        }
    }

    if (isPreformatted($continuation)) {
        return null;
    }

    $block[0] = substr($block[0], strlen($head));

    return wrapAtoms(atomsOf(joinLines($block)), $head, str_repeat(' ', textWidth($head)), $limit, $indent);
}

/**
 * @param  list<string>  $lines
 */
function joinLines(array $lines): string
{
    return implode(' ', array_map('trim', $lines));
}

/**
 * The unbreakable pieces of a text: words, but also a backtick span, an inline `{@tag ...}` and a generic or
 * array-shape type, each kept whole whatever spaces it contains.
 *
 * A piece that would open a line as something else (a list marker, a tag, a fence, a heading, a quote) is glued to the
 * piece before it, so wrapping can never change what a line means.
 *
 * @return list<string>
 */
function atomsOf(string $text): array
{
    $atoms = [];
    $current = '';
    $length = strlen($text);
    $i = 0;

    while ($i < $length) {
        $character = $text[$i];

        if ($character === ' ' || $character === "\t") {
            if ($current !== '') {
                $atoms[] = $current;
                $current = '';
            }

            $i++;

            continue;
        }

        if ($character === '`') {
            preg_match('/\G`+/', $text, $run, 0, $i);
            $size = strlen($run[0]);

            if (preg_match('/(?<!`)`{'.$size.'}(?!`)/', $text, $closing, PREG_OFFSET_CAPTURE, $i + $size) === 1) {
                $end = $closing[0][1] + $size;
                $current .= substr($text, $i, $end - $i);
                $i = $end;
            } else {
                $current .= $run[0];
                $i += $size;
            }

            continue;
        }

        $inlineTag = $character === '{' && ($text[$i + 1] ?? '') === '@';
        $generic = ($character === '<' || $character === '{') && preg_match('/[\w\\\\]$/', $current) === 1;

        if ($inlineTag || $generic) {
            $end = closingBracket($text, $i);

            if ($end !== null) {
                $current .= substr($text, $i, $end - $i);
                $i = $end;

                continue;
            }
        }

        $current .= $character;
        $i++;
    }

    if ($current !== '') {
        $atoms[] = $current;
    }

    $glued = [];

    foreach ($atoms as $atom) {
        if ($glued !== [] && opensABlock($atom)) {
            $glued[count($glued) - 1] .= ' '.$atom;
        } else {
            $glued[] = $atom;
        }
    }

    return $glued;
}

/**
 * The position just after the bracket that closes the one at $from, or null.
 */
function closingBracket(string $text, int $from): ?int
{
    $open = $text[$from];
    $close = $open === '<' ? '>' : '}';
    $depth = 0;
    $length = strlen($text);

    for ($i = $from; $i < $length; $i++) {
        if ($text[$i] === $open) {
            $depth++;
        } elseif ($text[$i] === $close) {
            $depth--;

            if ($depth === 0) {
                return $i + 1;
            }
        }
    }

    return null;
}

function opensABlock(string $atom): bool
{
    return isTagLine($atom)
        || isListItem($atom)
        || isStructural($atom)
        || preg_match('/^(`{3,}|~{3,})/', $atom) === 1;
}

/**
 * Greedy wrap, like Prettier's: a line takes words until the next one would pass the limit. A single piece longer than
 * a line sits on a line of its own.
 *
 * @param  list<string>  $atoms
 * @return list<string>
 */
function wrapAtoms(array $atoms, string $firstPrefix, string $hangPrefix, int $limit, string $indent): array
{
    $lines = [];
    $current = null;

    foreach ($atoms as $atom) {
        if ($current === null) {
            $current = $firstPrefix.$atom;
        } elseif (textWidth($current.' '.$atom) <= $limit) {
            $current .= ' '.$atom;
        } else {
            $lines[] = $current;
            $current = $hangPrefix.$atom;
        }
    }

    if ($current !== null) {
        $lines[] = $current;
    }

    return array_map(static fn (string $line): string => $indent.' * '.$line, $lines);
}

/**
 * Characters, not bytes: the docblocks hold typographic dashes and accents.
 */
function textWidth(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int) preg_match_all('/./su', $text);
}

exit(main($_SERVER['argv']));

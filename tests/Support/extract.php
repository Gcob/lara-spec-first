<?php

declare(strict_types=1);

// Run the whole reading engine over one fixture, in a process of its own.
//
// The one reason this exists rather than being an ordinary call inside a test:
// some parser defects kill the PHP process outright — an unrecoverable fatal,
// not a catchable exception — and there is no way to assert that without
// taking the test run down with it. Spawned from
// tests/Conformance/KnownParserBugsTest.php via `symfony/process`, so the
// crash lands in a child process and the parent only ever sees its exit code
// and stderr.
//
// @see tests/Conformance/KnownParserBugsTest.php
require __DIR__.'/../../vendor/autoload.php';

use Gcob\LaraSpecFirst\Parsing\OperationExtractor;
use Gcob\LaraSpecFirst\Parsing\SpecDocumentReader;

$path = $argv[1] ?? null;

if (! is_string($path) || $path === '') {
    fwrite(STDERR, "Usage: extract.php <path-to-spec>\n");

    exit(2);
}

(new OperationExtractor)->extract((new SpecDocumentReader)->read($path));

echo "ok\n";

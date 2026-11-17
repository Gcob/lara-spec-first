<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Contract;

/**
 * The `contentType` a multipart part gets when its `encoding` writes none, for the four kinds of part whose default
 * differs between OpenAPI versions.
 *
 * **The version hands a table, never itself.** A request body carries this and nothing that says which version wrote
 * it, so what reads it cannot branch on one. The kinds are in normal-form terms, each a list of the types the
 * specification gives that part (compared as a set: a list's order means nothing):
 *
 * - `typedFile`: a file part written `type: string`;
 * - `string`: a string that is no file part and has no `format: byte`;
 * - `byteString`: a string that is no file part and writes `format: byte`;
 * - `scalar`: a number, an integer or a boolean.
 *
 * What is the same in every version is not here: an object is `application/json`, an array has its items' default, a
 * file part with no `type` and a part with no `type` at all are `application/octet-stream`.
 *
 * **The constructor's defaults are the widest table**: both `text/plain` and `application/octet-stream` for each kind,
 * which is what a body built by hand (a test, a consumer assembling a contract) gets, since it names no version. It
 * reports nothing a version could call a default.
 *
 * @see docs/guide/uploads.md — "The schema names the file part"
 */
final readonly class EncodingDefaults
{
    /**
     * @param  list<string>  $typedFile
     * @param  list<string>  $string
     * @param  list<string>  $byteString
     * @param  list<string>  $scalar
     */
    public function __construct(
        public array $typedFile = ['text/plain', 'application/octet-stream'],
        public array $string = ['text/plain', 'application/octet-stream'],
        public array $byteString = ['text/plain', 'application/octet-stream'],
        public array $scalar = ['text/plain', 'application/octet-stream'],
    ) {}
}

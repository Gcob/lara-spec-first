<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Parsing\DocumentDecoder;
use Gcob\LaraSpecFirst\Parsing\Exceptions\UnreadableDocumentException;

function decoderScratchFile(string $contents): string
{
    $path = sys_get_temp_dir().'/lsf-decoder-'.bin2hex(random_bytes(6));
    file_put_contents($path, $contents);

    return $path;
}

it('sniffs JSON by its leading brace rather than by the file extension', function (): void {
    expect(DocumentDecoder::looksLikeJson('{"a": 1}'))->toBeTrue()
        ->and(DocumentDecoder::looksLikeJson("  \n  {\"a\": 1}"))->toBeTrue()
        ->and(DocumentDecoder::looksLikeJson('a: 1'))->toBeFalse()
        ->and(DocumentDecoder::looksLikeJson(''))->toBeFalse();
});

it('decodes JSON content through the JSON path even under a non-JSON extension', function (): void {
    $path = decoderScratchFile('{"openapi": "3.1.0", "info": {"title": "x", "version": "1"}}');

    expect(DocumentDecoder::decode($path))->toBe([
        'openapi' => '3.1.0',
        'info' => ['title' => 'x', 'version' => '1'],
    ]);
});

it('reports malformed JSON with the decoder\'s reason, not a YAML complaint', function (): void {
    $path = decoderScratchFile('{"openapi": "3.1.0", }');

    expect(fn () => DocumentDecoder::decode($path))
        ->toThrow(UnreadableDocumentException::class, 'not valid YAML or JSON');
});

it('still decodes plain YAML content', function (): void {
    $path = decoderScratchFile("openapi: 3.1.0\ninfo:\n  title: x\n  version: '1'\n");

    expect(DocumentDecoder::decode($path))->toBe([
        'openapi' => '3.1.0',
        'info' => ['title' => 'x', 'version' => '1'],
    ]);
});

<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Contract\HttpMethod;

it('names the methods as a Path Item writes them', function (): void {
    expect(HttpMethod::keys())->toBe(['get', 'put', 'post', 'delete', 'options', 'head', 'patch']);
});

// OpenAPI defines `trace` on a Path Item and Laravel has no TRACE verb, so an
// operation written under it cannot become a route. The artifact holds only
// what the package honors, which means it is refused where the document is read
// rather than carried this far.
it('has no case for trace', function (): void {
    expect(HttpMethod::tryFrom('trace'))->toBeNull();
});

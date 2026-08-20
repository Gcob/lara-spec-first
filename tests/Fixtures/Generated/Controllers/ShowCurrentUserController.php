<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Tests\Fixtures\Generated\Controllers;

/**
 * Stands in for a controller `spec:build` will emit, for the routes fixture
 * beside it. Deliberately the smallest thing a route can point at: the
 * two-class seam, the `501` and the docblock norm are other changes, and a
 * fixture that anticipated them would be asserting a format nothing writes yet.
 */
class ShowCurrentUserController
{
    public function routeAction(): string
    {
        return 'show-current-user';
    }
}

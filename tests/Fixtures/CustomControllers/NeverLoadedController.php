<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Tests\Fixtures\CustomControllers;

use Gcob\LaraSpecFirst\Generation\CustomControllerLookup;

/**
 * Exists to be found and never loaded.
 *
 * {@see CustomControllerLookup} promises to answer
 * from the autoloader's file map rather than by loading the class, and the only way
 * to assert that is against a class nothing else in the suite touches. Referencing
 * it by name from a test would defeat the point, so nothing does.
 */
class NeverLoadedController
{
    public function routeAction(): string
    {
        return 'never loaded';
    }
}

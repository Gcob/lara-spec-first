<?php

declare(strict_types=1);

namespace Gcob\LaraSpecFirst\Tests\Fixtures\CustomControllers;

/**
 * Stands in for a custom controller a project has already written.
 *
 * **It exists as a real, autoloadable class on purpose.** What the build asks
 * about an `x-controller` value is whether the autoloader can find a file for it,
 * so a fixture that only looked like a class name would prove nothing — and a
 * mocked lookup would prove that the mock answers rather than that the rule does.
 *
 * It deliberately does *not* extend a generated parent, because the parent a test
 * generates lives in a namespace that test invented moments earlier. What is
 * being observed here is the route's target and the generated parent's shape;
 * dispatching into a real child is the Workbench's job, where both classes exist
 * for real.
 */
class WrittenController
{
    public function routeAction(): string
    {
        return 'written';
    }
}

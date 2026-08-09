<?php

declare(strict_types=1);

use Gcob\LaraSpecFirst\Tests\TestCase;

// Feature tests run inside a booted Laravel application; unit tests do not, so
// they stay fast and framework-free.
uses(TestCase::class)->in('Feature');

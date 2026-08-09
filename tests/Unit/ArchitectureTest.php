<?php

declare(strict_types=1);

// Architecture tests enforce the conventions documented in AGENTS.md and
// docs/STACK.md mechanically, so a review never has to catch them by eye.

arch('source files declare strict types')
    ->expect('Gcob\LaraSpecFirst')
    ->toUseStrictTypes();

arch('no debugging helpers are left behind')
    ->expect(['dd', 'ddd', 'dump', 'var_dump', 'ray'])
    ->not->toBeUsed();

arch('service providers extend the Laravel base provider')
    ->expect('Gcob\LaraSpecFirst\LaraSpecFirstServiceProvider')
    ->toExtend('Illuminate\Support\ServiceProvider');

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class ListRsvpsController extends \App\Http\Generated\Controllers\ListRsvpsController
{
    public function routeAction(string $event): mixed
    {
        return Event::findOrFail($event)->rsvps()->orderBy('id')->get();
    }
}

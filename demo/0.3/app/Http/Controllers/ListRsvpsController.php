<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Data\RsvpDto;
use App\Models\Event;
use App\Models\Rsvp;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class ListRsvpsController extends \App\Http\Generated\Controllers\ListRsvpsController
{
    /**
     * @return list<RsvpDto>
     */
    public function routeAction(string $event): array
    {
        return Event::findOrFail($event)->rsvps()->orderBy('id')->get()
            ->map(fn (Rsvp $rsvp) => RsvpDto::from($rsvp->toArray()))
            ->all();
    }
}

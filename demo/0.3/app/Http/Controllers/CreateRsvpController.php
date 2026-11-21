<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Data\RsvpDto;
use App\Http\Generated\Requests\CreateRsvpRequest;
use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class CreateRsvpController extends \App\Http\Generated\Controllers\CreateRsvpController
{
    public function routeAction(CreateRsvpRequest $request, string $event): RsvpDto
    {
        $event = Event::findOrFail($event);

        // The contract declares the 409, and this code is what keeps the rule: nothing in the package counts RSVPs. An
        // error status is thrown, never returned.
        abort_if($event->rsvps()->count() >= $event->capacity, 409, 'The event is full.');

        return RsvpDto::from($event->rsvps()->create($request->dto()->toArray())->toArray());
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Generated\Data\EventDto;
use App\Http\Generated\Requests\CreateEventRequest;
use App\Models\Event;

// The parent below is generated. If PHP cannot find it, run `php artisan spec:build`.
class CreateEventController extends \App\Http\Generated\Controllers\CreateEventController
{
    public function routeAction(CreateEventRequest $request): EventDto
    {
        // The request has already checked the body against the contract, and `starts_at` arrives in the app's timezone,
        // so the model stores the right instant. The parent sends the contract's 201.
        $event = Event::create($request->dto()->toArray());

        return EventDto::from($event->toArray());
    }
}

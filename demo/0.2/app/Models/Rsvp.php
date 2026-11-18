<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rsvp extends Model
{
    protected $fillable = ['name', 'email'];

    // The contract's Rsvp has an id, a name and an email, and nothing else.
    protected $hidden = ['event_id', 'created_at', 'updated_at'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rsvp extends Model
{
    protected $fillable = ['name', 'email'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}

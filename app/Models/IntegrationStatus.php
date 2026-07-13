<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Zuletzt bekannter Status einer API-Anbindung (siehe IntegrationStatusChecker). */
class IntegrationStatus extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'configured' => 'boolean',
            'ok' => 'boolean',
            'checked_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}

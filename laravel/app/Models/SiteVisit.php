<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteVisit extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = [
        'is_new' => 'boolean',
        'first_in_session' => 'boolean',
        'created_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}

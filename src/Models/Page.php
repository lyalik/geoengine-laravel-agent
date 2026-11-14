<?php

namespace GeoEngine\Agent\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $table = 'geoengine_pages';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'published_at' => 'datetime',
    ];
}

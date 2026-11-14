<?php

namespace GeoEngine\Agent\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'geoengine_products';

    protected $guarded = [];

    protected $casts = [
        'fields' => 'array',
        'modified_at' => 'datetime',
    ];
}

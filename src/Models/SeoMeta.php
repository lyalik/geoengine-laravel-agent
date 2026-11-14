<?php

namespace GeoEngine\Agent\Models;

use Illuminate\Database\Eloquent\Model;

class SeoMeta extends Model
{
    protected $table = 'geoengine_seo';

    protected $guarded = [];

    protected $casts = [
        'indexable' => 'boolean',
        'og' => 'array',
        'twitter' => 'array',
        'json_ld' => 'array',
        'faq' => 'array',
        'custom_meta' => 'array',
        'extra' => 'array',
    ];
}

<?php

namespace GeoEngine\Agent\Models;

use Illuminate\Database\Eloquent\Model;

class TaskHistory extends Model
{
    protected $table = 'geoengine_task_history';

    protected $guarded = [];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'restored' => 'boolean',
    ];
}

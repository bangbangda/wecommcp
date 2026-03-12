<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ScheduledTask extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'action_type',
        'action_params',
        'schedule_type',
        'execute_time',
        'schedule_config',
        'next_run_at',
        'last_run_at',
        'is_active',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'action_params' => 'array',
            'schedule_config' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}

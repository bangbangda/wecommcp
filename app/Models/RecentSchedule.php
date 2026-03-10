<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecentSchedule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'summary',
        'description',
        'start_time',
        'end_time',
        'attendees',
        'creator_userid',
        'schedule_id',
        'cal_id',
        'location',
        'api_request',
        'api_response',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'attendees' => 'array',
            'api_request' => 'array',
            'api_response' => 'array',
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecentMeeting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'start_time',
        'duration_minutes',
        'invitees',
        'creator_userid',
        'meetingid',
        'api_request',
        'api_response',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'invitees' => 'array',
            'api_request' => 'array',
            'api_response' => 'array',
        ];
    }
}

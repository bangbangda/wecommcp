<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAnalysisReport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'user_name',
        'date',
        'report_content',
        'insights_snapshot',
        'sent_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'insights_snapshot' => 'json',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}

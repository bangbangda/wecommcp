<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAnalysisConfig extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}

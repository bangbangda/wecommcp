<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserMemory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'module',
        'content',
        'source',
        'hit_count',
        'last_hit_at',
    ];

    protected function casts(): array
    {
        return [
            'hit_count' => 'integer',
            'last_hit_at' => 'datetime',
        ];
    }
}

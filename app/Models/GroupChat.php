<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GroupChat extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'chatid',
        'name',
        'owner_userid',
        'creator_userid',
        'userlist',
    ];

    protected function casts(): array
    {
        return [
            'userlist' => 'array',
        ];
    }
}

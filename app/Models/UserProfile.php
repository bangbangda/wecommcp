<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'bot_name',
        'user_nickname',
        'persona',
        'greeting_template',
        'catchphrases',
        'taboos',
    ];
}

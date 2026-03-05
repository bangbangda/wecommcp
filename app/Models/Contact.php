<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'userid',
        'name',
        'name_pinyin',
        'name_initials',
        'department',
        'position',
        'mobile',
        'email',
    ];
}

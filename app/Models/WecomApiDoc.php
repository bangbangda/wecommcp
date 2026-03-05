<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WecomApiDoc extends Model
{
    protected $fillable = [
        'doc_id',
        'category_id',
        'parent_id',
        'title',
        'category_path',
        'raw_content',
        'parsed_content',
        'type',
        'status',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'doc_id' => 'integer',
            'category_id' => 'integer',
            'parent_id' => 'integer',
            'type' => 'integer',
            'status' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}

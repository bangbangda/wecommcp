<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalTemplate extends Model
{
    protected $fillable = [
        'template_id',
        'template_name',
        'report_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * 该模板关联的所有汇报记录
     */
    public function records(): HasMany
    {
        return $this->hasMany(JournalRecord::class, 'template_id', 'template_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatAnalysisSummary extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'date',
        'user_a',
        'user_b',
        'user_a_name',
        'user_b_name',
        'message_count',
        'summary',
        'raw_analysis',
        'token_usage',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'raw_analysis' => 'json',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 该摘要关联的所有洞察
     */
    public function insights(): HasMany
    {
        return $this->hasMany(ChatAnalysisInsight::class, 'summary_id');
    }
}

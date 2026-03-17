<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAnalysisInsight extends Model
{
    protected $fillable = [
        'type',
        'owner_userid',
        'owner_name',
        'content',
        'priority',
        'status',
        'source_userid',
        'source_name',
        'source_date',
        'deadline_date',
        'context',
        'summary_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_date' => 'date',
            'deadline_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * 关联的对话摘要
     */
    public function summary(): BelongsTo
    {
        return $this->belongsTo(ChatAnalysisSummary::class, 'summary_id');
    }
}

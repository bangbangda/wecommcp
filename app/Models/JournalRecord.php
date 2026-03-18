<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalRecord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'journal_uuid',
        'template_id',
        'template_name',
        'submitter_userid',
        'submitter_name',
        'report_time',
        'content',
        'raw_apply_data',
        'receivers',
        'comments_count',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'report_time' => 'datetime',
            'raw_apply_data' => 'json',
            'receivers' => 'json',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 查询某个接收人收到的汇报（通过 receivers JSON 字段）
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $receiverUserid  接收人 userid
     */
    public function scopeForReceiver($query, string $receiverUserid)
    {
        return $query->whereJsonContains('receivers', $receiverUserid);
    }
}

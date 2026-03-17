<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalContactFollowUser extends Model
{
    protected $fillable = [
        'external_userid',
        'userid',
        'remark',
        'description',
        'remark_corp_name',
        'add_way',
        'state',
        'follow_at',
        'deleted_by_user_at',
        'deleted_by_customer_at',
    ];

    protected function casts(): array
    {
        return [
            'follow_at' => 'datetime',
            'deleted_by_user_at' => 'datetime',
            'deleted_by_customer_at' => 'datetime',
        ];
    }

    /**
     * 关联的外部联系人
     */
    public function externalContact(): BelongsTo
    {
        return $this->belongsTo(ExternalContact::class, 'external_userid', 'external_userid');
    }

    /**
     * 关联的内部员工
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'userid', 'userid');
    }

    /**
     * 该跟进关系是否仍然活跃（未被任何一方删除）
     */
    public function isActive(): bool
    {
        return is_null($this->deleted_by_user_at) && is_null($this->deleted_by_customer_at);
    }
}

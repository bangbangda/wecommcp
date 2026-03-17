<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalContact extends Model
{
    protected $fillable = [
        'external_userid',
        'name',
        'name_pinyin',
        'name_initials',
        'avatar',
        'type',
        'gender',
        'corp_name',
        'corp_full_name',
        'position',
        'unionid',
    ];

    /**
     * 该外部联系人的所有跟进关系（哪些内部员工添加了此人）
     */
    public function followUsers(): HasMany
    {
        return $this->hasMany(ExternalContactFollowUser::class, 'external_userid', 'external_userid');
    }

    /**
     * 获取仍在跟进中的关系（未被任何一方删除）
     */
    public function activeFollowUsers(): HasMany
    {
        return $this->followUsers()
            ->whereNull('deleted_by_user_at')
            ->whereNull('deleted_by_customer_at');
    }
}

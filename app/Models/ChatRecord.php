<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 外部聊天记录模型（只读）
 * 对应外部 MySQL 的 work_wechat_chat_records 表
 */
class ChatRecord extends Model
{
    protected $connection = 'chat_records';

    protected $table = 'work_wechat_chat_records';

    public $timestamps = false;

    /**
     * 全局作用域：只查询当前企业的数据（tenant_id = 1）
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $builder->where('tenant_id', 1);
        });
    }

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'send_time' => 'integer',
        ];
    }
}

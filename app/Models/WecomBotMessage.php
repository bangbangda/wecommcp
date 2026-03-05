<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 企微 AI Bot 消息记录
 * 存储用户发送的消息及 AI 回复内容
 */
class WecomBotMessage extends Model
{
    protected $fillable = [
        'msgid',
        'aibotid',
        'chatid',
        'chattype',
        'userid',
        'msgtype',
        'content',
        'stream_id',
        'response_url',
        'reply',
        'replied_at',
    ];

    /**
     * 属性类型转换
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
        ];
    }
}

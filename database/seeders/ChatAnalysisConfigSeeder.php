<?php

namespace Database\Seeders;

use App\Models\ChatAnalysisConfig;
use Illuminate\Database\Seeder;

/**
 * 聊天分析模块默认配置
 */
class ChatAnalysisConfigSeeder extends Seeder
{
    public function run(): void
    {
        $configs = [
            // ── schedule 分组：执行计划 ──
            ['group' => 'schedule', 'key' => 'analyze_time', 'value' => '02:00', 'description' => '每日分析执行时间'],
            ['group' => 'schedule', 'key' => 'push_time', 'value' => '08:30', 'description' => '日报推送时间'],
            ['group' => 'schedule', 'key' => 'push_days', 'value' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'description' => '推送日（周末不推送，周一合并）'],

            // ── ai 分组：AI 模型参数 ──
            ['group' => 'ai', 'key' => 'driver', 'value' => 'deepseek', 'description' => '分析使用的 AI 驱动'],
            ['group' => 'ai', 'key' => 'model', 'value' => null, 'description' => '具体模型（null 则使用驱动默认模型）'],
            ['group' => 'ai', 'key' => 'history_days', 'value' => 7, 'description' => '回溯历史摘要天数（Phase 1 上下文）'],
            ['group' => 'ai', 'key' => 'max_tokens_per_conversation', 'value' => 4000, 'description' => '单段对话最大 token 数，超出则分段'],

            // ── scope 分组：分析范围 ──
            ['group' => 'scope', 'key' => 'include_users', 'value' => ['*'], 'description' => '分析范围，["*"] 表示全部内部员工'],
            ['group' => 'scope', 'key' => 'exclude_users', 'value' => [], 'description' => '排除的用户 userid 列表'],

            // ── lifecycle 分组：洞察生命周期 ──
            ['group' => 'lifecycle', 'key' => 'todo_expire_days', 'value' => 14, 'description' => '待办超期天数（超过标记为 expired）'],
            ['group' => 'lifecycle', 'key' => 'pending_expire_days', 'value' => 3, 'description' => '未回复超期天数'],
            ['group' => 'lifecycle', 'key' => 'deadline_remind_before_days', 'value' => 1, 'description' => '截止日期提前几天提醒'],
        ];

        foreach ($configs as $config) {
            ChatAnalysisConfig::updateOrCreate(
                ['group' => $config['group'], 'key' => $config['key']],
                ['value' => $config['value'], 'description' => $config['description']],
            );
        }
    }
}

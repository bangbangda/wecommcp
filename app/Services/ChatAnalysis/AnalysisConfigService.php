<?php

namespace App\Services\ChatAnalysis;

use App\Models\ChatAnalysisConfig;
use Illuminate\Support\Facades\Cache;

/**
 * 聊天分析配置管理服务
 * 从 chat_analysis_configs 表读取/管理配置，带内存缓存
 */
class AnalysisConfigService
{
    /** 缓存 key */
    private const CACHE_KEY = 'chat_analysis_configs';

    /** 缓存时长（秒） */
    private const CACHE_TTL = 3600;

    /**
     * 获取指定配置值
     *
     * @param  string  $group  配置分组
     * @param  string  $key  配置键
     * @param  mixed  $default  默认值
     * @return mixed 配置值
     */
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $configs = $this->all();

        return $configs[$group][$key] ?? $default;
    }

    /**
     * 获取指定分组的所有配置
     *
     * @param  string  $group  配置分组
     * @return array 该分组下的所有 key-value
     */
    public function getGroup(string $group): array
    {
        $configs = $this->all();

        return $configs[$group] ?? [];
    }

    /**
     * 设置配置值
     *
     * @param  string  $group  配置分组
     * @param  string  $key  配置键
     * @param  mixed  $value  配置值
     * @param  string|null  $description  配置说明
     */
    public function set(string $group, string $key, mixed $value, ?string $description = null): void
    {
        $attributes = ['value' => $value];
        if ($description !== null) {
            $attributes['description'] = $description;
        }

        ChatAnalysisConfig::updateOrCreate(
            ['group' => $group, 'key' => $key],
            $attributes,
        );

        $this->clearCache();
    }

    /**
     * 获取所有配置（带缓存），按 group 分组
     *
     * @return array<string, array<string, mixed>> group => [key => value]
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $configs = [];

            ChatAnalysisConfig::all()->each(function ($config) use (&$configs) {
                $configs[$config->group][$config->key] = $config->value;
            });

            return $configs;
        });
    }

    /**
     * 清除配置缓存（配置变更后调用）
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ── 便捷方法：schedule 分组 ──

    /**
     * 获取分析执行时间
     */
    public function getAnalyzeTime(): string
    {
        return $this->get('schedule', 'analyze_time', '02:00');
    }

    /**
     * 获取日报推送时间
     */
    public function getPushTime(): string
    {
        return $this->get('schedule', 'push_time', '08:30');
    }

    /**
     * 获取推送日（周几推送）
     *
     * @return array 如 ["mon","tue","wed","thu","fri"]
     */
    public function getPushDays(): array
    {
        return $this->get('schedule', 'push_days', ['mon', 'tue', 'wed', 'thu', 'fri']);
    }

    // ── 便捷方法：ai 分组 ──

    /**
     * 获取 AI 驱动名称
     */
    public function getAiDriver(): string
    {
        return $this->get('ai', 'driver', 'deepseek');
    }

    /**
     * 获取 AI 模型名称（null 则使用驱动默认模型）
     */
    public function getAiModel(): ?string
    {
        return $this->get('ai', 'model');
    }

    /**
     * 获取历史摘要回溯天数
     */
    public function getHistoryDays(): int
    {
        return (int) $this->get('ai', 'history_days', 7);
    }

    /**
     * 获取单段对话最大 token 数
     */
    public function getMaxTokensPerConversation(): int
    {
        return (int) $this->get('ai', 'max_tokens_per_conversation', 4000);
    }

    // ── 便捷方法：scope 分组 ──

    /**
     * 获取分析范围内的用户列表
     *
     * @return array ["*"] 表示全部
     */
    public function getIncludeUsers(): array
    {
        return $this->get('scope', 'include_users', ['*']);
    }

    /**
     * 获取排除的用户列表
     */
    public function getExcludeUsers(): array
    {
        return $this->get('scope', 'exclude_users', []);
    }

    // ── 便捷方法：lifecycle 分组 ──

    /**
     * 获取待办超期天数
     */
    public function getTodoExpireDays(): int
    {
        return (int) $this->get('lifecycle', 'todo_expire_days', 14);
    }

    /**
     * 获取未回复超期天数
     */
    public function getPendingExpireDays(): int
    {
        return (int) $this->get('lifecycle', 'pending_expire_days', 3);
    }

    /**
     * 获取截止日期提前提醒天数
     */
    public function getDeadlineRemindBeforeDays(): int
    {
        return (int) $this->get('lifecycle', 'deadline_remind_before_days', 1);
    }
}

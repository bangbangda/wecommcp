<?php

namespace App\Services\ChatAnalysis;

use App\Ai\AiManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 用户日报生成器（Phase 2）
 * 聚合用户当日所有洞察 + 历史待办，调用 AI 生成可读的日报文本
 */
class ReportGenerator
{
    public function __construct(
        private AiManager $aiManager,
        private AnalysisConfigService $config,
    ) {}

    /**
     * 为指定用户生成日报
     *
     * @param  string  $userId  用户 userid
     * @param  string  $userName  用户姓名
     * @param  string  $date  日报日期（Y-m-d）
     * @param  Collection  $todayInsights  今日新提取的洞察
     * @param  Collection  $openHistorical  历史 open/expired 状态的洞察
     * @param  Collection  $todaySummaries  今日所有对话摘要
     * @param  bool  $isMergedReport  是否为合并报告（周一含周末）
     * @return string|null 日报文本，无内容时返回 null
     */
    public function generate(
        string $userId,
        string $userName,
        string $date,
        Collection $todayInsights,
        Collection $openHistorical,
        Collection $todaySummaries,
        bool $isMergedReport = false,
    ): ?string {
        // 如果没有任何内容，不生成日报
        if ($todayInsights->isEmpty() && $openHistorical->isEmpty() && $todaySummaries->isEmpty()) {
            Log::debug('ReportGenerator::generate 无内容，跳过', [
                'user_id' => $userId,
                'date' => $date,
            ]);

            return null;
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userMessage = $this->buildUserMessage(
            $userName, $date, $todayInsights, $openHistorical, $todaySummaries, $isMergedReport,
        );

        Log::info('ReportGenerator::generate 调用 AI 生成日报', [
            'user_id' => $userId,
            'date' => $date,
            'today_insights' => $todayInsights->count(),
            'open_historical' => $openHistorical->count(),
            'is_merged' => $isMergedReport,
        ]);

        $driver = $this->config->getAiDriver();
        $response = $this->aiManager->driver($driver)->chat(
            $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
        );

        if (! $response || empty($response->text)) {
            Log::error('ReportGenerator::generate AI 返回为空', ['user_id' => $userId]);

            return null;
        }

        $report = trim($response->text);

        Log::info('ReportGenerator::generate 日报生成完成', [
            'user_id' => $userId,
            'date' => $date,
            'length' => mb_strlen($report),
        ]);

        return $report;
    }

    /**
     * 构建 Phase 2 系统提示词
     */
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个工作助手，负责为员工生成每日工作沟通总结。

## 要求
- 使用纯文本格式，不要使用 markdown 语法
- 不要使用任何 emoji 表情符号
- 语气专业、简洁、有温度
- 只展示有内容的板块，没有内容的板块完全不要出现
- 待办事项按优先级排序（high > medium > low）
- 临近截止日期的事项需要重点标注
- 超期提醒板块需要附带操作指引
- 沟通概要板块简要概括当天的主要沟通内容和对象
- 如果是合并报告（含多天），按天分组概要但洞察合并展示

## 输出格式模板（根据实际内容取舍板块）

[日期] 工作日报

-- 待办事项 --
1. [重要] 事项内容 -- 来自与某某的对话
2. 事项内容 -- 来自与某某的对话

-- 重要决策 --
1. 决策内容 -- 与某某达成一致

-- 关键时间节点 --
1. [紧急] 日期 -- 事项内容

-- 待回复 --
1. 某某在HH:MM提出了XX问题

-- 超期提醒 --
1. 事项内容（已超期N天，来自M/D）
   回复「完成1」标记已完成 | 回复「忽略1」不再跟踪

-- 今日沟通概要 --
今日主要与某某讨论了...，与某某同步了...

请直接输出日报内容，不要输出其他解释文字。
PROMPT;
    }

    /**
     * 构建 Phase 2 用户消息
     */
    private function buildUserMessage(
        string $userName,
        string $date,
        Collection $todayInsights,
        Collection $openHistorical,
        Collection $todaySummaries,
        bool $isMergedReport,
    ): string {
        $parts = [];

        $parts[] = "用户姓名：{$userName}";
        $parts[] = "报告日期：{$date}";

        if ($isMergedReport) {
            $parts[] = '说明：这是合并报告，包含周五至周日的数据，请在沟通概要中按天分组';
        }

        // 今日对话摘要
        if ($todaySummaries->isNotEmpty()) {
            $parts[] = "\n## 今日对话摘要";
            foreach ($todaySummaries as $summary) {
                $parts[] = "- 与 {$summary->user_a_name}/{$summary->user_b_name} 的对话（{$summary->message_count}条）: {$summary->summary}";
            }
        }

        // 今日新增洞察
        if ($todayInsights->isNotEmpty()) {
            $parts[] = "\n## 今日新增洞察";
            $grouped = $todayInsights->groupBy('type');

            foreach (['todo', 'decision', 'deadline', 'pending'] as $type) {
                $items = $grouped->get($type, collect());
                if ($items->isEmpty()) {
                    continue;
                }

                $typeLabel = match ($type) {
                    'todo' => '待办事项',
                    'decision' => '重要决策',
                    'deadline' => '关键时间节点',
                    'pending' => '未回复',
                };
                $parts[] = "\n### {$typeLabel}";

                foreach ($items as $item) {
                    $line = "- {$item->content}";
                    if ($item->priority) {
                        $line .= " (优先级: {$item->priority})";
                    }
                    if ($item->deadline_date) {
                        $line .= " (截止: {$item->deadline_date->format('m/d')})";
                    }
                    if ($item->source_name) {
                        $line .= " -- 来自与{$item->source_name}的对话";
                    }
                    $parts[] = $line;
                }
            }
        }

        // 历史未完成事项
        if ($openHistorical->isNotEmpty()) {
            $parts[] = "\n## 历史未完成事项";

            $expired = $openHistorical->whereIn('status', ['expired', 'reminded']);
            $open = $openHistorical->where('status', 'open');

            if ($expired->isNotEmpty()) {
                $parts[] = "\n### 已超期（需要在日报中提醒用户操作）";
                foreach ($expired as $item) {
                    $days = now()->diffInDays($item->source_date);
                    $parts[] = "- [#{$item->id}] {$item->content} (已超期{$days}天, 来自{$item->source_date->format('m/d')})";
                }
            }

            if ($open->isNotEmpty()) {
                $parts[] = "\n### 仍在进行中";
                foreach ($open as $item) {
                    $line = "- {$item->content}";
                    if ($item->deadline_date) {
                        $daysLeft = now()->diffInDays($item->deadline_date, false);
                        if ($daysLeft <= $this->config->getDeadlineRemindBeforeDays()) {
                            $line .= " [紧急: 截止日期 {$item->deadline_date->format('m/d')}]";
                        }
                    }
                    $parts[] = $line;
                }
            }
        }

        return implode("\n", $parts);
    }
}

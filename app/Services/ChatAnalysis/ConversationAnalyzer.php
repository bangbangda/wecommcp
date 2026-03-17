<?php

namespace App\Services\ChatAnalysis;

use App\Ai\AiManager;
use App\Models\ChatAnalysisInsight;
use App\Models\ChatAnalysisSummary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 对话级 AI 分析器（Phase 1）
 * 分析单个对话对的消息，提取结构化洞察
 */
class ConversationAnalyzer
{
    public function __construct(
        private AiManager $aiManager,
        private AnalysisConfigService $config,
    ) {}

    /**
     * 分析一段对话，返回结构化结果
     *
     * @param  string  $date  分析日期（Y-m-d）
     * @param  string  $userA  对话方 A 的 userid
     * @param  string  $userB  对话方 B 的 userid
     * @param  string  $formattedConversation  格式化后的对话文本
     * @param  int  $messageCount  消息条数
     * @param  array  $nameMap  userid → 姓名映射
     * @return array|null AI 分析结果（解析后的 JSON），无效对话返回 null
     */
    public function analyze(
        string $date,
        string $userA,
        string $userB,
        string $formattedConversation,
        int $messageCount,
        array $nameMap,
    ): ?array {
        // 预过滤：闲聊/无效对话直接跳过
        if ($this->shouldSkip($formattedConversation, $messageCount)) {
            Log::debug('ConversationAnalyzer::analyze 跳过无效对话', [
                'date' => $date,
                'pair' => "{$userA}|{$userB}",
                'message_count' => $messageCount,
            ]);

            return null;
        }

        $nameA = $nameMap[$userA] ?? $userA;
        $nameB = $nameMap[$userB] ?? $userB;

        // 获取历史上下文
        $historySummaries = $this->getHistorySummaries($userA, $userB, $date);
        $openInsights = $this->getOpenInsights($userA, $userB);

        // 组装 prompt
        $systemPrompt = $this->buildSystemPrompt();
        $userMessage = $this->buildUserMessage(
            $date, $userA, $userB, $nameA, $nameB,
            $formattedConversation, $historySummaries, $openInsights,
        );

        Log::info('ConversationAnalyzer::analyze 调用 AI', [
            'date' => $date,
            'pair' => "{$nameA} ↔ {$nameB}",
            'message_count' => $messageCount,
            'has_history' => $historySummaries->isNotEmpty(),
            'open_insights_count' => $openInsights->count(),
        ]);

        // 调用 AI
        $driver = $this->config->getAiDriver();
        $response = $this->aiManager->driver($driver)->chat(
            $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
        );

        if (! $response || empty($response->text)) {
            Log::error('ConversationAnalyzer::analyze AI 返回为空', [
                'pair' => "{$userA}|{$userB}",
            ]);

            return null;
        }

        // 解析 JSON
        $result = $this->parseResponse($response->text);

        if ($result === null) {
            Log::error('ConversationAnalyzer::analyze JSON 解析失败', [
                'pair' => "{$userA}|{$userB}",
                'raw_text' => mb_substr($response->text, 0, 500),
            ]);

            return null;
        }

        Log::info('ConversationAnalyzer::analyze 分析完成', [
            'pair' => "{$nameA} ↔ {$nameB}",
            'todos' => count($result['new_insights']['todos'] ?? []),
            'decisions' => count($result['new_insights']['decisions'] ?? []),
            'deadlines' => count($result['new_insights']['deadlines'] ?? []),
            'pending' => count($result['new_insights']['pending'] ?? []),
            'status_updates' => count($result['status_updates'] ?? []),
        ]);

        return $result;
    }

    /**
     * 预过滤：判断对话是否值得进行深度分析
     * 第一层：极端情况用规则快速跳过（仅 1 条消息）
     * 第二层：调用 AI 轻量判断是否包含工作相关内容
     *
     * @param  string  $conversation  格式化后的对话文本
     * @param  int  $messageCount  消息条数
     * @return bool 应跳过返回 true
     */
    private function shouldSkip(string $conversation, int $messageCount): bool
    {
        // 第一层：只有 1 条消息，无法构成有效对话
        if ($messageCount <= 1) {
            return true;
        }

        // 第二层：调用 AI 轻量判断
        return $this->isChitChat($conversation);
    }

    /**
     * 调用 AI 轻量判断对话是否为闲聊/无工作价值内容
     * 使用极简 prompt，消耗 token 极少
     *
     * @param  string  $conversation  格式化后的对话文本
     * @return bool 闲聊返回 true，有工作内容返回 false
     */
    private function isChitChat(string $conversation): bool
    {
        $systemPrompt = '你是一个对话分类器。判断以下对话是否包含有价值的工作内容（如任务分配、工作讨论、决策、时间安排等）。只回复 yes 或 no。yes 表示有工作价值需要分析，no 表示纯闲聊无需分析。';

        $driver = $this->config->getAiDriver();
        $response = $this->aiManager->driver($driver)->chat(
            $systemPrompt,
            [['role' => 'user', 'content' => $conversation]],
        );

        if (! $response || empty($response->text)) {
            // AI 调用失败时保守处理：不跳过，继续分析
            return false;
        }

        $answer = strtolower(trim($response->text));

        $skip = str_contains($answer, 'no');

        Log::debug('ConversationAnalyzer::isChitChat AI 判断', [
            'answer' => $answer,
            'skip' => $skip,
        ]);

        return $skip;
    }

    /**
     * 获取该对话对近 N 天的历史摘要
     *
     * @param  string  $userA  对话方 A
     * @param  string  $userB  对话方 B
     * @param  string  $currentDate  当前分析日期
     * @return Collection 历史摘要集合
     */
    private function getHistorySummaries(string $userA, string $userB, string $currentDate): Collection
    {
        $days = $this->config->getHistoryDays();

        return ChatAnalysisSummary::where('user_a', $userA)
            ->where('user_b', $userB)
            ->where('date', '<', $currentDate)
            ->where('date', '>=', now()->parse($currentDate)->subDays($days)->format('Y-m-d'))
            ->orderBy('date', 'desc')
            ->get();
    }

    /**
     * 获取该对话对关联的所有 open 状态洞察
     *
     * @param  string  $userA  对话方 A
     * @param  string  $userB  对话方 B
     * @return Collection open 状态的洞察集合
     */
    private function getOpenInsights(string $userA, string $userB): Collection
    {
        return ChatAnalysisInsight::where('status', 'open')
            ->where(function ($query) use ($userA, $userB) {
                $query->where(function ($q) use ($userA, $userB) {
                    $q->where('owner_userid', $userA)->where('source_userid', $userB);
                })->orWhere(function ($q) use ($userA, $userB) {
                    $q->where('owner_userid', $userB)->where('source_userid', $userA);
                });
            })
            ->orderBy('source_date')
            ->get();
    }

    /**
     * 构建 Phase 1 系统提示词
     */
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的工作沟通分析助手。你的任务是分析两人之间的工作对话，提取结构化的工作洞察。

## 提取规则

### 1. 待办事项 (todo)
识别对话中一方对另一方的工作请求或任务分配。
- owner 是需要执行动作的人，不是发起请求的人
- "好的""没问题""我来处理"是对任务的承诺确认，不要重复提取为新的待办
- 如果说话人承诺自己做某事（如"我下午把文档发你"），owner 是说话人自己
- 优先级判断：涉及客户投诉/线上故障/紧急上线 → high，有明确时间要求 → medium，一般事务 → low
- 只提取需要后续跟进、有实质工作量的事项。即时性的简单动作不算待办

应提取：
  "帮我看一下登录页面的Bug" → owner=对方, priority=medium
  "你跟进一下这个客户" → owner=对方, priority=medium
  "我下午把文档发你" → owner=说话人, priority=low
  "这个今天必须搞定" → owner=对方, priority=high

不应提取：
  "好的 我知道了" — 确认，不是新任务
  "收到" "OK" "👍" — 回应
  "你吃了吗" "哈哈" — 非工作内容
  "加一下我微信""把你拉进群" — 即时性社交动作，不需要后续跟进
  "我发你一下""稍等" — 即时性操作，不是持续性任务
  "好的，我看看" — 模糊的回应，没有明确的交付物

### 2. 重要决策 (decision)
双方讨论后达成一致的结论或方案选择。
- 必须是经过讨论后双方确认的结果，不是单方面陈述
- participants 包含参与决策的所有人

应提取：
  A: "用方案B怎么样" B: "行 就这样" → decision
  A: "确认下周三上线" B: "没问题" → decision

不是决策：
  "我觉得方案B更好" — 只是个人观点，对方未确认

### 3. 关键时间节点 (deadline)
对话中提到的截止日期或时间要求。
- 必须将相对日期转换为绝对日期（基于"对话日期"推算）
- "明天" = 对话日期+1天, "下周一" = 下一个周一, "月底" = 当月最后一天, "这周五" = 本周五
- owner 是需要在该日期前完成任务的人

### 4. 未回复/待跟进 (pending)
一方提出了工作相关的问题或请求，但对方在当天对话中未给出回应。
- 仅限工作相关内容，闲聊未回复不算
- 如果对方在后续消息中已回应，则不算 pending

## 历史事项状态更新
如果提供了"当前未完成事项"列表，请检查今天的对话中是否有证据表明某个事项已经完成。
- 比如"那个Bug修好了""文档已经发了"等表述
- 只有明确的完成证据才标记为 completed，不要猜测

## 重要注意事项
- 如果对话主要是闲聊、寒暄、表情，请在 summary 中简要说明，new_insights 各项返回空数组
- 不要过度提取：宁可遗漏不重要的事项，也不要把普通闲聊错误标记为工作洞察
- content 字段要简洁概括事项内容，不要照搬原文

## 输出格式
严格输出 JSON，不要输出任何其他内容（不要输出 markdown 代码块标记）：
{
  "summary": "2-3句话概括今天这段对话的主要内容",
  "topics": ["话题关键词1", "话题关键词2"],
  "new_insights": {
    "todos": [
      {"owner": "userid", "owner_name": "姓名", "content": "事项概述", "priority": "high/medium/low"}
    ],
    "decisions": [
      {"participants": ["userid1", "userid2"], "content": "决策内容概述"}
    ],
    "deadlines": [
      {"owner": "userid", "owner_name": "姓名", "date": "YYYY-MM-DD", "content": "事项概述"}
    ],
    "pending": [
      {"from": "提问者userid", "from_name": "姓名", "to": "未回复者userid", "to_name": "姓名", "content": "问题/请求概述"}
    ]
  },
  "status_updates": [
    {"insight_id": 42, "new_status": "completed", "evidence": "完成的依据概述"}
  ]
}

如果某个类别没有内容，返回空数组。如果没有需要更新的历史事项，status_updates 返回空数组。
PROMPT;
    }

    /**
     * 构建 Phase 1 用户消息
     */
    private function buildUserMessage(
        string $date,
        string $userA,
        string $userB,
        string $nameA,
        string $nameB,
        string $conversation,
        Collection $historySummaries,
        Collection $openInsights,
    ): string {
        $parts = [];

        // 历史上下文
        if ($historySummaries->isNotEmpty()) {
            $parts[] = "## 历史沟通摘要\n以下是这两人近期的沟通概要，供你了解上下文连续性：";
            foreach ($historySummaries as $summary) {
                $parts[] = "- {$summary->date->format('m/d')}: {$summary->summary}";
            }
            $parts[] = '';
        }

        // 未完成事项
        if ($openInsights->isNotEmpty()) {
            $parts[] = "## 当前未完成事项\n以下事项仍处于未完成状态，请检查今日对话中是否有完成的证据：";
            foreach ($openInsights as $insight) {
                $typeLabel = match ($insight->type) {
                    'todo' => '待办',
                    'deadline' => '截止',
                    'pending' => '待回复',
                    default => $insight->type,
                };
                $parts[] = "- [#{$insight->id}] {$typeLabel} | {$insight->owner_name}: {$insight->content} (since {$insight->source_date->format('m/d')})";
            }
            $parts[] = '';
        }

        // 今日对话
        $parts[] = "## 今日对话\n日期：{$date}\n参与者：{$nameA}({$userA}) 与 {$nameB}({$userB})\n";
        $parts[] = $conversation;

        return implode("\n", $parts);
    }

    /**
     * 解析 AI 返回的 JSON 文本
     *
     * @param  string  $text  AI 返回的原始文本
     * @return array|null 解析后的数组，失败返回 null
     */
    private function parseResponse(string $text): ?array
    {
        // 去除可能的 markdown 代码块标记
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/i', '', $text);

        // 去除可能的思考标签（qwen 等模型）
        $text = preg_replace('/<think>.*?<\/think>/s', '', $text);
        $text = trim($text);

        $result = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        // 验证基本结构
        if (! isset($result['summary']) || ! isset($result['new_insights'])) {
            return null;
        }

        // 确保各数组字段存在
        $result['new_insights']['todos'] = $result['new_insights']['todos'] ?? [];
        $result['new_insights']['decisions'] = $result['new_insights']['decisions'] ?? [];
        $result['new_insights']['deadlines'] = $result['new_insights']['deadlines'] ?? [];
        $result['new_insights']['pending'] = $result['new_insights']['pending'] ?? [];
        $result['status_updates'] = $result['status_updates'] ?? [];
        $result['topics'] = $result['topics'] ?? [];

        return $result;
    }
}

<?php

namespace App\Mcp\Tools\Analysis;

use App\Ai\AiManager;
use App\Models\ChatAnalysisReport;
use App\Models\ChatAnalysisSummary;
use App\Models\GroupChat;
use App\Services\ChatAnalysis\AnalysisConfigService;
use App\Services\ChatAnalysis\MessageCollector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_daily_work_summary')]
#[Description('总结指定日期当前用户的工作内容。基于用户参与的所有单聊和群聊记录，AI 生成当日工作概要。典型场景："今天我都干了什么""帮我总结一下今天的工作""昨天的工作内容是什么""写一下今天的日报"。此工具汇总用户一天的全部沟通，如需分析与某个人的具体沟通（跟进建议、客户分析），请使用 analyze_chat_with_contact。')]
class GetDailyWorkSummaryTool extends Tool
{
    /** 每个对话/群聊最大消息条数 */
    private const MAX_MESSAGES_PER_CONVERSATION = 50;

    /** 消息总量上限（条） */
    private const MAX_TOTAL_MESSAGES = 500;

    public function schema(JsonSchema $schema): array
    {
        return [
            'userid' => $schema->string('当前用户的 userid')->required(),
            'date' => $schema->string('查询日期（Y-m-d），默认今天'),
        ];
    }

    /**
     * 生成用户指定日期的工作总结
     * 优先使用预计算的 ChatAnalysisReport，无则从原始消息实时汇总
     */
    public function handle(
        Request $request,
        AiManager $aiManager,
        AnalysisConfigService $config,
        MessageCollector $collector,
    ): Response {
        $data = $request->validate([
            'userid' => 'required|string',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $userId = $data['userid'];
        $date = $data['date'] ?? Carbon::now('Asia/Shanghai')->format('Y-m-d');

        Log::debug('GetDailyWorkSummaryTool::handle 收到请求', [
            'userid' => $userId,
            'date' => $date,
        ]);

        // Step 1: 查预计算报告
        $report = ChatAnalysisReport::where('user_id', $userId)
            ->whereDate('date', $date)
            ->first();

        if ($report && ! empty($report->report_content)) {
            Log::info('GetDailyWorkSummaryTool 命中预计算报告', [
                'userid' => $userId,
                'date' => $date,
            ]);

            return Response::text(json_encode([
                'status' => 'success',
                'date' => $date,
                'source' => 'precomputed',
                'summary' => $report->report_content,
            ], JSON_UNESCAPED_UNICODE));
        }

        // Step 2: 查原始消息
        $records = $collector->collectByDateForUser($date, $userId);

        if ($records->isEmpty()) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => "{$date} 没有找到你参与的聊天记录",
            ], JSON_UNESCAPED_UNICODE));
        }

        Log::info('GetDailyWorkSummaryTool 采集到原始消息', [
            'userid' => $userId,
            'date' => $date,
            'count' => $records->count(),
        ]);

        // Step 3: 分组（单聊按对话对，群聊按 room_id）
        $directMessages = $records->filter(fn ($r) => empty($r->room_id));
        $groupMessages = $records->filter(fn ($r) => ! empty($r->room_id));

        $conversations = collect();

        // 单聊：优先使用 Layer 1 摘要，无则用原始消息
        if ($directMessages->isNotEmpty()) {
            $pairs = $collector->groupByConversationPair($directMessages);

            foreach ($pairs as $pairKey => $messages) {
                $summaryText = $this->getDirectChatSummary($userId, $pairKey, $date);
                if ($summaryText) {
                    $conversations->push([
                        'type' => 'direct',
                        'label' => $this->getDirectChatLabel($pairKey, $collector, $messages),
                        'content' => $summaryText,
                        'message_count' => $messages->count(),
                    ]);
                } else {
                    $nameMap = $collector->resolveNames($messages);
                    $formatted = $collector->formatConversation(
                        $messages->take(self::MAX_MESSAGES_PER_CONVERSATION),
                        $nameMap,
                    );
                    $conversations->push([
                        'type' => 'direct',
                        'label' => $this->getDirectChatLabel($pairKey, $collector, $messages),
                        'content' => $formatted,
                        'message_count' => $messages->count(),
                    ]);
                }
            }
        }

        // 群聊：按 room_id 分组
        if ($groupMessages->isNotEmpty()) {
            $groups = $groupMessages->groupBy('room_id');

            // 批量查询群聊名称
            $roomIds = $groups->keys()->toArray();
            $groupNames = GroupChat::whereIn('chatid', $roomIds)->pluck('name', 'chatid');

            foreach ($groups as $roomId => $messages) {
                $groupName = $groupNames[$roomId] ?? $roomId;
                $nameMap = $collector->resolveNames($messages);
                $formatted = $collector->formatConversation(
                    $messages->take(self::MAX_MESSAGES_PER_CONVERSATION),
                    $nameMap,
                );
                $conversations->push([
                    'type' => 'group',
                    'label' => "群聊「{$groupName}」",
                    'content' => $formatted,
                    'message_count' => $messages->count(),
                ]);
            }
        }

        // Step 4: Token 控制 — 按消息量排序，优先保留消息多的对话
        $conversations = $conversations->sortByDesc('message_count')->values();
        $conversations = $this->truncateConversations($conversations);

        // Step 5: AI 汇总
        $summary = $this->callAiSummary($aiManager, $config, $userId, $date, $conversations);

        if ($summary === null) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => 'AI 生成工作总结失败，请稍后重试',
            ], JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'status' => 'success',
            'date' => $date,
            'source' => 'realtime',
            'conversations_count' => $conversations->count(),
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 尝试获取单聊的 Layer 1 摘要
     *
     * @param  string  $userId  当前用户
     * @param  string  $pairKey  对话对 key（"userA|userB"）
     * @param  string  $date  日期
     * @return string|null 摘要文本
     */
    private function getDirectChatSummary(string $userId, string $pairKey, string $date): ?string
    {
        [$userA, $userB] = explode('|', $pairKey, 2);
        $pair = [$userA, $userB];
        sort($pair);

        $summary = ChatAnalysisSummary::where('user_a', $pair[0])
            ->where('user_b', $pair[1])
            ->whereDate('date', $date)
            ->whereNotNull('summary')
            ->first();

        return $summary?->summary;
    }

    /**
     * 获取单聊对话标签（对方姓名）
     *
     * @param  string  $pairKey  对话对 key
     * @param  MessageCollector  $collector  消息采集器
     * @param  Collection  $messages  消息集合
     * @return string 对话标签
     */
    private function getDirectChatLabel(string $pairKey, MessageCollector $collector, Collection $messages): string
    {
        $nameMap = $collector->resolveNames($messages);
        [$userA, $userB] = explode('|', $pairKey, 2);

        $nameA = $nameMap[$userA] ?? $userA;
        $nameB = $nameMap[$userB] ?? $userB;

        return "与{$nameA}和{$nameB}的单聊";
    }

    /**
     * 截断对话列表，确保不超过总消息量上限
     *
     * @param  Collection  $conversations  对话列表（已按消息量降序排列）
     * @return Collection 截断后的对话列表
     */
    private function truncateConversations(Collection $conversations): Collection
    {
        $totalMessages = 0;
        $result = collect();

        foreach ($conversations as $conv) {
            $totalMessages += $conv['message_count'];
            if ($totalMessages > self::MAX_TOTAL_MESSAGES && $result->isNotEmpty()) {
                break;
            }
            $result->push($conv);
        }

        return $result;
    }

    /**
     * 调用 AI 生成工作总结
     *
     * @param  AiManager  $aiManager  AI 管理器
     * @param  AnalysisConfigService  $config  分析配置
     * @param  string  $userId  用户 ID
     * @param  string  $date  日期
     * @param  Collection  $conversations  对话列表
     * @return string|null AI 生成的工作总结
     */
    private function callAiSummary(
        AiManager $aiManager,
        AnalysisConfigService $config,
        string $userId,
        string $date,
        Collection $conversations,
    ): ?string {
        $systemPrompt = $this->buildSummaryPrompt();

        $parts = [];
        $parts[] = "用户 userid：{$userId}";
        $parts[] = "日期：{$date}";
        $parts[] = "共 {$conversations->count()} 个对话/群聊";

        foreach ($conversations as $index => $conv) {
            $num = $index + 1;
            $type = $conv['type'] === 'group' ? '群聊' : '单聊';
            $parts[] = "\n--- [{$num}] {$conv['label']}（{$type}，{$conv['message_count']}条消息）---";
            $parts[] = $conv['content'];
        }

        $userMessage = implode("\n", $parts);

        Log::info('GetDailyWorkSummaryTool 调用 AI 生成工作总结', [
            'userid' => $userId,
            'date' => $date,
            'conversations' => $conversations->count(),
            'message_length' => mb_strlen($userMessage),
        ]);

        $driver = $config->getAiDriver();
        $response = $aiManager->driver($driver)->chat(
            $systemPrompt,
            [['role' => 'user', 'content' => $userMessage]],
        );

        if (! $response || empty($response->text)) {
            Log::error('GetDailyWorkSummaryTool AI 返回为空');

            return null;
        }

        return trim($response->text);
    }

    /**
     * 构建工作总结 AI 提示词
     */
    private function buildSummaryPrompt(): string
    {
        return <<<'PROMPT'
你是一个专业的工作总结助手。请基于用户当天参与的所有聊天记录（单聊和群聊），生成一份简洁的工作总结。

## 要求
- 使用纯文本格式，不要使用 markdown 语法
- 不要使用任何 emoji 表情符号
- 语气专业简洁
- 按工作主题归纳，不要按对话逐个罗列
- 重点突出关键决策、待办事项和重要进展
- 如果有些对话内容是闲聊或非工作相关，可以略过或简要提及

## 输出格式

[日期] 工作总结

-- 主要工作内容 --
按主题归纳今天的工作内容，每项 1-2 句话概括

-- 关键决策与结论 --
今天达成的重要决策或结论（如果有）

-- 待跟进事项 --
需要后续跟进的事项（如果有）

-- 沟通概览 --
简要列出今天沟通的主要对象和话题

注意：
- 只展示有内容的板块，没有内容的板块不要出现
- 从用户自身视角出发，总结"我今天做了什么"
- 请直接输出总结内容，不要输出其他解释文字
PROMPT;
    }
}

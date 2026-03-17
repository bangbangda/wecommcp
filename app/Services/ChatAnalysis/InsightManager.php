<?php

namespace App\Services\ChatAnalysis;

use App\Models\ChatAnalysisInsight;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 洞察生命周期管理器
 * 负责洞察的创建、状态流转、超期检测等
 *
 * 状态机：
 * open → completed（对话中确认完成）
 * open → expired（超 N 天未完成）
 * expired → reminded（日报中已提醒）
 * reminded → completed / ignored / open
 */
class InsightManager
{
    public function __construct(
        private AnalysisConfigService $config,
    ) {}

    /**
     * 从 AI 分析结果中批量创建洞察记录
     *
     * @param  array  $analysisResult  ConversationAnalyzer 返回的结构化 JSON
     * @param  int  $summaryId  关联的对话摘要 ID
     * @param  string  $date  发现日期（Y-m-d）
     * @param  array  $nameMap  userid → 姓名映射
     * @return Collection 创建的洞察集合
     */
    public function createFromAnalysis(array $analysisResult, int $summaryId, string $date, array $nameMap): Collection
    {
        $created = collect();
        $insights = $analysisResult['new_insights'] ?? [];

        // 待办事项
        foreach ($insights['todos'] ?? [] as $todo) {
            $insight = $this->createInsight([
                'type' => 'todo',
                'owner_userid' => $todo['owner'] ?? '',
                'owner_name' => $todo['owner_name'] ?? ($nameMap[$todo['owner'] ?? ''] ?? ''),
                'content' => $todo['content'] ?? '',
                'priority' => $todo['priority'] ?? 'medium',
                'source_userid' => $this->inferSourceUser($todo['owner'] ?? '', $nameMap),
                'source_name' => $this->inferSourceName($todo['owner'] ?? '', $nameMap),
                'source_date' => $date,
                'summary_id' => $summaryId,
            ]);
            if ($insight) {
                $created->push($insight);
            }
        }

        // 重要决策
        foreach ($insights['decisions'] ?? [] as $decision) {
            $participants = $decision['participants'] ?? [];
            $ownerUserid = $participants[0] ?? '';
            $insight = $this->createInsight([
                'type' => 'decision',
                'owner_userid' => $ownerUserid,
                'owner_name' => $nameMap[$ownerUserid] ?? ($decision['owner_name'] ?? ''),
                'content' => $decision['content'] ?? '',
                'source_userid' => $participants[1] ?? null,
                'source_name' => isset($participants[1]) ? ($nameMap[$participants[1]] ?? '') : null,
                'source_date' => $date,
                'summary_id' => $summaryId,
            ]);
            if ($insight) {
                $created->push($insight);
            }
        }

        // 关键时间节点
        foreach ($insights['deadlines'] ?? [] as $deadline) {
            $insight = $this->createInsight([
                'type' => 'deadline',
                'owner_userid' => $deadline['owner'] ?? '',
                'owner_name' => $deadline['owner_name'] ?? ($nameMap[$deadline['owner'] ?? ''] ?? ''),
                'content' => $deadline['content'] ?? '',
                'deadline_date' => $deadline['date'] ?? null,
                'source_userid' => $this->inferSourceUser($deadline['owner'] ?? '', $nameMap),
                'source_name' => $this->inferSourceName($deadline['owner'] ?? '', $nameMap),
                'source_date' => $date,
                'summary_id' => $summaryId,
            ]);
            if ($insight) {
                $created->push($insight);
            }
        }

        // 未回复/待跟进
        foreach ($insights['pending'] ?? [] as $pending) {
            $insight = $this->createInsight([
                'type' => 'pending',
                'owner_userid' => $pending['to'] ?? '',
                'owner_name' => $pending['to_name'] ?? ($nameMap[$pending['to'] ?? ''] ?? ''),
                'content' => $pending['content'] ?? '',
                'source_userid' => $pending['from'] ?? null,
                'source_name' => $pending['from_name'] ?? ($nameMap[$pending['from'] ?? ''] ?? null),
                'source_date' => $date,
                'summary_id' => $summaryId,
            ]);
            if ($insight) {
                $created->push($insight);
            }
        }

        Log::info('InsightManager::createFromAnalysis 批量创建完成', [
            'summary_id' => $summaryId,
            'created_count' => $created->count(),
        ]);

        return $created;
    }

    /**
     * 处理 AI 返回的历史事项状态更新
     *
     * @param  array  $statusUpdates  status_updates 数组
     */
    public function applyStatusUpdates(array $statusUpdates): void
    {
        foreach ($statusUpdates as $update) {
            $insightId = $update['insight_id'] ?? null;
            $newStatus = $update['new_status'] ?? null;

            if (! $insightId || ! $newStatus) {
                continue;
            }

            $insight = ChatAnalysisInsight::find($insightId);
            if (! $insight) {
                Log::warning('InsightManager::applyStatusUpdates 洞察不存在', [
                    'insight_id' => $insightId,
                ]);

                continue;
            }

            // 只允许合理的状态流转
            if (! $this->isValidTransition($insight->status, $newStatus)) {
                Log::warning('InsightManager::applyStatusUpdates 无效的状态流转', [
                    'insight_id' => $insightId,
                    'current' => $insight->status,
                    'target' => $newStatus,
                ]);

                continue;
            }

            $insight->status = $newStatus;
            if ($newStatus === 'completed') {
                $insight->completed_at = now();
            }
            $insight->save();

            Log::info('InsightManager::applyStatusUpdates 状态更新', [
                'insight_id' => $insightId,
                'new_status' => $newStatus,
                'evidence' => $update['evidence'] ?? '',
            ]);
        }
    }

    /**
     * 扫描并标记超期的洞察
     * open 状态超过配置天数 → expired
     */
    public function markExpired(): int
    {
        $todoExpireDays = $this->config->getTodoExpireDays();
        $pendingExpireDays = $this->config->getPendingExpireDays();

        // 待办超期
        $todoCount = ChatAnalysisInsight::where('status', 'open')
            ->whereIn('type', ['todo', 'deadline', 'decision'])
            ->where('source_date', '<=', now()->subDays($todoExpireDays)->format('Y-m-d'))
            ->update(['status' => 'expired']);

        // 未回复超期
        $pendingCount = ChatAnalysisInsight::where('status', 'open')
            ->where('type', 'pending')
            ->where('source_date', '<=', now()->subDays($pendingExpireDays)->format('Y-m-d'))
            ->update(['status' => 'expired']);

        $total = $todoCount + $pendingCount;

        if ($total > 0) {
            Log::info('InsightManager::markExpired 标记超期', [
                'todo_expired' => $todoCount,
                'pending_expired' => $pendingCount,
            ]);
        }

        return $total;
    }

    /**
     * 将 expired 状态的洞察标记为 reminded（已在日报中提醒）
     *
     * @param  Collection  $insights  要标记的洞察集合
     */
    public function markReminded(Collection $insights): void
    {
        $ids = $insights->where('status', 'expired')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        ChatAnalysisInsight::whereIn('id', $ids)->update(['status' => 'reminded']);

        Log::info('InsightManager::markReminded 标记已提醒', ['count' => $ids->count()]);
    }

    /**
     * 处理用户回复操作（完成/忽略）
     *
     * @param  string  $userId  用户 userid
     * @param  string  $action  操作类型：completed / ignored / open
     * @param  int  $insightId  洞察 ID
     * @return bool 操作是否成功
     */
    public function handleUserAction(string $userId, string $action, int $insightId): bool
    {
        $insight = ChatAnalysisInsight::where('id', $insightId)
            ->where('owner_userid', $userId)
            ->first();

        if (! $insight) {
            return false;
        }

        if (! $this->isValidTransition($insight->status, $action)) {
            return false;
        }

        $insight->status = $action;
        if ($action === 'completed') {
            $insight->completed_at = now();
        }
        $insight->save();

        Log::info('InsightManager::handleUserAction 用户操作', [
            'user_id' => $userId,
            'insight_id' => $insightId,
            'action' => $action,
        ]);

        return true;
    }

    /**
     * 获取指定用户的所有 open/expired/reminded 状态洞察
     *
     * @param  string  $userId  用户 userid
     * @return Collection 活跃洞察集合
     */
    public function getActiveInsights(string $userId): Collection
    {
        return ChatAnalysisInsight::where('owner_userid', $userId)
            ->whereIn('status', ['open', 'expired', 'reminded'])
            ->orderBy('source_date')
            ->get();
    }

    /**
     * 获取指定用户在指定日期创建的洞察
     *
     * @param  string  $userId  用户 userid
     * @param  string  $date  日期（Y-m-d）
     * @return Collection 当日洞察集合
     */
    public function getTodayInsights(string $userId, string $date): Collection
    {
        return ChatAnalysisInsight::where('owner_userid', $userId)
            ->where('source_date', $date)
            ->orderByRaw("FIELD(type, 'todo', 'deadline', 'decision', 'pending')")
            ->get();
    }

    /**
     * 创建单条洞察记录
     *
     * @param  array  $data  洞察数据
     * @return ChatAnalysisInsight|null 创建成功返回模型，owner_userid 为空返回 null
     */
    private function createInsight(array $data): ?ChatAnalysisInsight
    {
        if (empty($data['owner_userid']) || empty($data['content'])) {
            return null;
        }

        $data['status'] = 'open';

        return ChatAnalysisInsight::create($data);
    }

    /**
     * 推断对话中"另一方"的 userid
     * 在两人对话中，owner 以外的那个人就是 source
     *
     * @param  string  $ownerUserid  责任人 userid
     * @param  array  $nameMap  userid → 姓名映射（包含对话双方）
     * @return string|null 另一方的 userid
     */
    private function inferSourceUser(string $ownerUserid, array $nameMap): ?string
    {
        foreach (array_keys($nameMap) as $userid) {
            if ($userid !== $ownerUserid) {
                return $userid;
            }
        }

        return null;
    }

    /**
     * 推断对话中"另一方"的姓名
     */
    private function inferSourceName(string $ownerUserid, array $nameMap): ?string
    {
        $sourceUserid = $this->inferSourceUser($ownerUserid, $nameMap);

        return $sourceUserid ? ($nameMap[$sourceUserid] ?? null) : null;
    }

    /**
     * 检查状态流转是否合法
     *
     * @param  string  $current  当前状态
     * @param  string  $target  目标状态
     * @return bool 合法返回 true
     */
    private function isValidTransition(string $current, string $target): bool
    {
        $transitions = [
            'open' => ['completed', 'expired'],
            'expired' => ['reminded', 'completed'],
            'reminded' => ['completed', 'ignored', 'open'],
        ];

        return in_array($target, $transitions[$current] ?? []);
    }
}

<?php

namespace App\Services\ChatAnalysis;

use App\Models\ChatAnalysisReport;
use App\Models\ChatAnalysisSummary;
use App\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 聊天分析主编排服务
 * 协调 MessageCollector → ConversationAnalyzer → InsightManager → ReportGenerator 的完整流程
 */
class ChatAnalysisService
{
    public function __construct(
        private MessageCollector $collector,
        private ConversationAnalyzer $analyzer,
        private InsightManager $insightManager,
        private ReportGenerator $reportGenerator,
        private AnalysisConfigService $config,
    ) {}

    /**
     * 执行指定日期的完整分析流程
     *
     * @param  string  $date  分析日期（Y-m-d）
     * @return array 分析统计结果
     */
    public function analyzeDate(string $date): array
    {
        Log::info('ChatAnalysisService::analyzeDate 开始', ['date' => $date]);

        $stats = [
            'date' => $date,
            'messages' => 0,
            'conversation_pairs' => 0,
            'analyzed' => 0,
            'skipped' => 0,
            'insights_created' => 0,
            'status_updates' => 0,
            'expired_marked' => 0,
            'reports_generated' => 0,
        ];

        // Phase 1: 采集 + 对话级分析
        $records = $this->collector->collectByDate($date);
        $stats['messages'] = $records->count();

        if ($records->isEmpty()) {
            Log::info('ChatAnalysisService::analyzeDate 无消息，跳过', ['date' => $date]);

            return $stats;
        }

        $groups = $this->collector->groupByConversationPair($records);
        $stats['conversation_pairs'] = $groups->count();

        foreach ($groups as $pairKey => $messages) {
            $result = $this->analyzeConversationPair($pairKey, $messages, $date);

            if ($result) {
                $stats['analyzed']++;
                $stats['insights_created'] += $result['insights_count'];
                $stats['status_updates'] += $result['status_updates_count'];
            } else {
                $stats['skipped']++;
            }
        }

        // 生命周期检查：标记超期事项
        $stats['expired_marked'] = $this->insightManager->markExpired();

        // Phase 2: 为每个内部用户生成日报
        $stats['reports_generated'] = $this->generateReports($date);

        Log::info('ChatAnalysisService::analyzeDate 完成', $stats);

        return $stats;
    }

    /**
     * 冷启动：回溯分析最近 N 天的历史数据
     *
     * @param  int|null  $days  回溯天数，默认使用配置值
     * @return array 每天的分析统计
     */
    public function backfill(?int $days = null): array
    {
        $days = $days ?? $this->config->getHistoryDays();
        $results = [];

        Log::info('ChatAnalysisService::backfill 开始回溯', ['days' => $days]);

        // 从最早的日期开始分析，确保历史摘要逐步积累
        for ($i = $days; $i >= 1; $i--) {
            $date = Carbon::now('Asia/Shanghai')->subDays($i)->format('Y-m-d');

            // 跳过已经分析过的日期
            $existingSummaries = ChatAnalysisSummary::where('date', $date)->count();
            if ($existingSummaries > 0) {
                Log::debug("ChatAnalysisService::backfill 跳过已分析日期: {$date}");

                continue;
            }

            $results[$date] = $this->analyzeDate($date);
        }

        Log::info('ChatAnalysisService::backfill 回溯完成', [
            'days_analyzed' => count($results),
        ]);

        return $results;
    }

    /**
     * 分析单个对话对
     *
     * @param  string  $pairKey  对话对标识（"userA|userB"）
     * @param  Collection  $messages  该对话对的消息集合
     * @param  string  $date  分析日期
     * @return array|null 分析结果统计，跳过返回 null
     */
    private function analyzeConversationPair(string $pairKey, Collection $messages, string $date): ?array
    {
        [$userA, $userB] = $this->collector->parsePairKey($pairKey);
        $nameMap = $this->collector->resolveNames($messages);
        $formattedConversation = $this->collector->formatConversation($messages, $nameMap);

        // 调用 AI 分析
        $analysisResult = $this->analyzer->analyze(
            $date, $userA, $userB,
            $formattedConversation,
            $messages->count(),
            $nameMap,
        );

        if ($analysisResult === null) {
            // 保存一条最简摘要（标记为已分析但无工作内容）
            ChatAnalysisSummary::updateOrCreate(
                ['date' => $date, 'user_a' => $userA, 'user_b' => $userB],
                [
                    'user_a_name' => $nameMap[$userA] ?? null,
                    'user_b_name' => $nameMap[$userB] ?? null,
                    'message_count' => $messages->count(),
                    'summary' => '无工作相关内容',
                    'raw_analysis' => null,
                    'token_usage' => 0,
                    'created_at' => now(),
                ],
            );

            return null;
        }

        // 存储 Layer 1 对话摘要
        $summary = ChatAnalysisSummary::updateOrCreate(
            ['date' => $date, 'user_a' => $userA, 'user_b' => $userB],
            [
                'user_a_name' => $nameMap[$userA] ?? null,
                'user_b_name' => $nameMap[$userB] ?? null,
                'message_count' => $messages->count(),
                'summary' => $analysisResult['summary'] ?? '',
                'raw_analysis' => $analysisResult,
                'token_usage' => 0,
                'created_at' => now(),
            ],
        );

        // 创建洞察记录
        $createdInsights = $this->insightManager->createFromAnalysis(
            $analysisResult, $summary->id, $date, $nameMap,
        );

        // 处理历史事项状态更新
        $statusUpdates = $analysisResult['status_updates'] ?? [];
        $this->insightManager->applyStatusUpdates($statusUpdates);

        return [
            'insights_count' => $createdInsights->count(),
            'status_updates_count' => count($statusUpdates),
        ];
    }

    /**
     * 为所有内部员工生成日报（Phase 2）
     *
     * @param  string  $date  报告日期
     * @return int 生成的日报数量
     */
    private function generateReports(string $date): int
    {
        $internalUsers = $this->getAnalysisUsers();
        $count = 0;

        foreach ($internalUsers as $user) {
            $report = $this->generateUserReport($user->userid, $user->name, $date);
            if ($report) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 为单个用户生成日报
     *
     * @param  string  $userId  用户 userid
     * @param  string  $userName  用户姓名
     * @param  string  $date  日期
     * @return ChatAnalysisReport|null 生成的日报记录
     */
    private function generateUserReport(string $userId, string $userName, string $date): ?ChatAnalysisReport
    {
        // 获取该用户当日的洞察
        $todayInsights = $this->insightManager->getTodayInsights($userId, $date);

        // 获取历史活跃洞察（open/expired/reminded）
        $openHistorical = $this->insightManager->getActiveInsights($userId);
        // 排除今日新增的，避免重复
        $openHistorical = $openHistorical->filter(fn ($i) => $i->source_date->format('Y-m-d') !== $date);

        // 获取该用户今日相关的对话摘要
        $todaySummaries = ChatAnalysisSummary::where('date', $date)
            ->where(function ($query) use ($userId) {
                $query->where('user_a', $userId)->orWhere('user_b', $userId);
            })
            ->whereNotNull('raw_analysis')
            ->get();

        // 判断是否为合并报告（周一包含周末）
        $isMerged = $this->isMergedReportDate($date);
        if ($isMerged) {
            $this->mergeWeekendData($userId, $date, $todayInsights, $openHistorical, $todaySummaries);
        }

        // 调用 AI 生成日报
        $reportContent = $this->reportGenerator->generate(
            $userId, $userName, $date,
            $todayInsights, $openHistorical, $todaySummaries, $isMerged,
        );

        if ($reportContent === null) {
            return null;
        }

        // 存储日报
        $report = ChatAnalysisReport::updateOrCreate(
            ['user_id' => $userId, 'date' => $date],
            [
                'user_name' => $userName,
                'report_content' => $reportContent,
                'insights_snapshot' => $todayInsights->toArray(),
                'created_at' => now(),
            ],
        );

        // 标记超期洞察为已提醒
        $this->insightManager->markReminded($openHistorical);

        return $report;
    }

    /**
     * 获取分析范围内的内部用户列表
     *
     * @return Collection Contact 集合
     */
    private function getAnalysisUsers(): Collection
    {
        $includeUsers = $this->config->getIncludeUsers();
        $excludeUsers = $this->config->getExcludeUsers();

        $query = Contact::query();

        // 如果不是全部用户，按列表过滤
        if (! in_array('*', $includeUsers)) {
            $query->whereIn('userid', $includeUsers);
        }

        // 排除用户
        if (! empty($excludeUsers)) {
            $query->whereNotIn('userid', $excludeUsers);
        }

        return $query->get();
    }

    /**
     * 判断指定日期是否需要生成合并报告
     * 周一需要合并周五~周日的数据
     *
     * @param  string  $date  日期
     * @return bool 需要合并返回 true
     */
    private function isMergedReportDate(string $date): bool
    {
        return Carbon::parse($date)->dayOfWeek === Carbon::MONDAY;
    }

    /**
     * 合并周末数据到当前集合中
     * 将周六和周日的洞察、摘要追加到传入的集合
     *
     * @param  string  $userId  用户 userid
     * @param  string  $mondayDate  周一日期
     * @param  Collection  &$insights  洞察集合（会被追加）
     * @param  Collection  &$historical  历史洞察集合
     * @param  Collection  &$summaries  摘要集合（会被追加）
     */
    private function mergeWeekendData(
        string $userId,
        string $mondayDate,
        Collection &$insights,
        Collection &$historical,
        Collection &$summaries,
    ): void {
        $saturday = Carbon::parse($mondayDate)->subDays(2)->format('Y-m-d');
        $sunday = Carbon::parse($mondayDate)->subDays(1)->format('Y-m-d');

        foreach ([$saturday, $sunday] as $weekendDate) {
            // 追加周末的洞察
            $weekendInsights = $this->insightManager->getTodayInsights($userId, $weekendDate);
            $insights = $insights->merge($weekendInsights);

            // 追加周末的摘要
            $weekendSummaries = ChatAnalysisSummary::where('date', $weekendDate)
                ->where(function ($query) use ($userId) {
                    $query->where('user_a', $userId)->orWhere('user_b', $userId);
                })
                ->whereNotNull('raw_analysis')
                ->get();
            $summaries = $summaries->merge($weekendSummaries);
        }
    }
}

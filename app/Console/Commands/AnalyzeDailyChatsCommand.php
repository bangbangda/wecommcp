<?php

namespace App\Console\Commands;

use App\Services\ChatAnalysis\ChatAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AnalyzeDailyChatsCommand extends Command
{
    protected $signature = 'chat:analyze-daily
                            {--date= : 指定分析日期（Y-m-d），默认为昨天}
                            {--backfill : 冷启动模式，回溯分析最近 N 天}
                            {--days= : 回溯天数（配合 --backfill 使用）}';

    protected $description = '分析聊天记录，提取待办事项、重要决策、时间节点等工作洞察并生成日报';

    /**
     * 执行聊天分析命令
     * 支持三种模式：指定日期分析、昨日分析、冷启动回溯
     */
    public function handle(ChatAnalysisService $service): int
    {
        if ($this->option('backfill')) {
            return $this->handleBackfill($service);
        }

        return $this->handleSingleDate($service);
    }

    /**
     * 分析单日数据
     */
    private function handleSingleDate(ChatAnalysisService $service): int
    {
        $date = $this->option('date')
            ?? Carbon::yesterday('Asia/Shanghai')->format('Y-m-d');

        $this->info("开始分析 {$date} 的聊天记录...");

        $stats = $service->analyzeDate($date);

        $this->displayStats($stats);

        return self::SUCCESS;
    }

    /**
     * 冷启动回溯分析
     */
    private function handleBackfill(ChatAnalysisService $service): int
    {
        $days = $this->option('days') ? (int) $this->option('days') : null;

        $this->info('开始冷启动回溯分析...');

        $results = $service->backfill($days);

        if (empty($results)) {
            $this->info('无需回溯，所有日期已分析完毕。');

            return self::SUCCESS;
        }

        foreach ($results as $date => $stats) {
            $this->line('');
            $this->displayStats($stats);
        }

        $this->info("\n回溯完成，共分析 ".count($results).' 天。');

        return self::SUCCESS;
    }

    /**
     * 显示分析统计信息
     */
    private function displayStats(array $stats): void
    {
        $this->info("[{$stats['date']}] 分析结果：");
        $this->line("  消息总数: {$stats['messages']}");
        $this->line("  对话对数: {$stats['conversation_pairs']}");
        $this->line("  已分析: {$stats['analyzed']}，跳过: {$stats['skipped']}");
        $this->line("  新增洞察: {$stats['insights_created']}");
        $this->line("  状态更新: {$stats['status_updates']}");
        $this->line("  超期标记: {$stats['expired_marked']}");
        $this->line("  生成日报: {$stats['reports_generated']} 份");
    }
}

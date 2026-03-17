<?php

namespace App\Console\Commands;

use App\Models\ChatAnalysisReport;
use App\Services\ChatAnalysis\AnalysisConfigService;
use App\Wecom\WecomMessageClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class PushDailyReportsCommand extends Command
{
    protected $signature = 'chat:push-reports
                            {--date= : 指定推送日期（Y-m-d），默认为昨天}
                            {--force : 强制推送（忽略 push_days 限制）}';

    protected $description = '推送聊天分析日报给员工（通过企微 bot 消息）';

    /**
     * 推送日报
     * 检查今天是否为推送日，查询未推送的日报并逐一发送
     */
    public function handle(AnalysisConfigService $config, WecomMessageClient $messageClient): int
    {
        $date = $this->option('date')
            ?? Carbon::yesterday('Asia/Shanghai')->format('Y-m-d');

        // 检查今天是否为推送日
        if (! $this->option('force') && ! $this->isPushDay($config)) {
            $this->info('今天不是推送日，跳过推送。使用 --force 强制推送。');

            return self::SUCCESS;
        }

        // 查询待推送的日报
        $query = ChatAnalysisReport::whereNull('sent_at')
            ->whereNotNull('report_content');

        // 周一推送时包含周五~周日的日报
        if (Carbon::now('Asia/Shanghai')->dayOfWeek === Carbon::MONDAY && ! $this->option('date')) {
            $friday = Carbon::now('Asia/Shanghai')->subDays(3)->format('Y-m-d');
            $query->where('date', '>=', $friday);
        } else {
            $query->where('date', $date);
        }

        $reports = $query->get();

        if ($reports->isEmpty()) {
            $this->info("没有待推送的日报（{$date}）。");

            return self::SUCCESS;
        }

        $this->info("找到 {$reports->count()} 份待推送日报，开始推送...");

        $success = 0;
        $failed = 0;

        foreach ($reports as $report) {
            try {
                $messageClient->sendText($report->user_id, $report->report_content);
                $report->update(['sent_at' => now()]);
                $success++;

                $this->line("  已推送: {$report->user_name} ({$report->user_id}) - {$report->date}");
            } catch (\Throwable $e) {
                $failed++;
                Log::error('PushDailyReportsCommand 推送失败', [
                    'user_id' => $report->user_id,
                    'date' => $report->date,
                    'error' => $e->getMessage(),
                ]);
                $this->error("  推送失败: {$report->user_name} - {$e->getMessage()}");
            }
        }

        $this->info("推送完成：成功 {$success}，失败 {$failed}。");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 判断今天是否为配置的推送日
     *
     * @param  AnalysisConfigService  $config  配置服务
     * @return bool 是推送日返回 true
     */
    private function isPushDay(AnalysisConfigService $config): bool
    {
        $pushDays = $config->getPushDays();
        $today = strtolower(Carbon::now('Asia/Shanghai')->format('D')); // mon, tue, ...

        return in_array($today, $pushDays);
    }
}

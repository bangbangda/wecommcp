<?php

namespace App\Console\Commands;

use App\Services\JournalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncJournalRecordsCommand extends Command
{
    protected $signature = 'journal:sync
                            {--date= : 指定日期（Y-m-d），默认为昨天}
                            {--days= : 拉取最近 N 天，默认 1}';

    protected $description = '从企业微信同步汇报记录到本地数据库';

    /**
     * 同步汇报记录
     */
    public function handle(JournalService $service): int
    {
        $days = (int) ($this->option('days') ?? 1);

        if ($this->option('date')) {
            $startDate = $this->option('date');
            $endDate = $startDate;
        } else {
            $endDate = Carbon::yesterday('Asia/Shanghai')->format('Y-m-d');
            $startDate = Carbon::yesterday('Asia/Shanghai')->subDays($days - 1)->format('Y-m-d');
        }

        $this->info("开始同步汇报记录 [{$startDate} ~ {$endDate}]...");

        $result = $service->syncRecords($startDate, $endDate);

        $this->info("同步完成：新增 {$result['synced']} 条，跳过 {$result['skipped']} 条（已存在）。");

        return self::SUCCESS;
    }
}

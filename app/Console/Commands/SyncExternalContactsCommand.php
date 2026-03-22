<?php

namespace App\Console\Commands;

use App\Services\ExternalContactService;
use Illuminate\Console\Command;

class SyncExternalContactsCommand extends Command
{
    protected $signature = 'wecom:sync-external-contacts
                            {--user= : 只同步指定员工的外部联系人}';

    protected $description = '从企业微信同步外部联系人（客户）到本地数据库';

    /**
     * 执行同步命令
     * 支持全量同步或指定员工同步，输出耗时统计
     */
    public function handle(ExternalContactService $service): int
    {
        $userid = $this->option('user');
        $startTime = microtime(true);

        if ($userid) {
            $this->info("开始同步员工 [{$userid}] 的外部联系人...");
            $count = $service->syncByUser($userid);
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->info("同步完成，共同步 {$count} 位外部联系人，耗时 {$elapsed} 秒。");
        } else {
            $this->info('开始全量同步外部联系人...');
            $result = $service->syncAll();
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->info("同步完成，共同步 {$result['contacts']} 位外部联系人，{$result['relations']} 条跟进关系，耗时 {$elapsed} 秒。");
        }

        return self::SUCCESS;
    }
}

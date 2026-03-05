<?php

namespace App\Console\Commands;

use App\Services\ContactsService;
use App\Wecom\WecomContactClient;
use Illuminate\Console\Command;

class SyncContactsCommand extends Command
{
    protected $signature = 'wecom:sync-contacts';

    protected $description = '从企业微信同步通讯录到本地数据库';

    public function handle(ContactsService $contactsService, WecomContactClient $wecomContactClient): int
    {
        $this->info('开始同步企微通讯录...');

        $count = $contactsService->syncFromWecom($wecomContactClient);

        $this->info("同步完成，共同步 {$count} 位联系人。");

        return self::SUCCESS;
    }
}

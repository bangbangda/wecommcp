<?php

namespace App\Console\Commands;

use App\Services\ChatService;
use Illuminate\Console\Command;

class ChatCommand extends Command
{
    protected $signature = 'chat {--user=test_user : 模拟用户 ID}';

    protected $description = '企业微信 AI 助手交互式对话（MVP 验证入口）';

    public function handle(ChatService $chatService): int
    {
        $userId = $this->option('user');

        $this->info('=== 企业微信 AI 助手 ===');
        $this->info("当前用户: {$userId}");
        $this->info('输入消息开始对话，输入 exit 退出，输入 clear 清除对话历史');
        $this->newLine();

        while (true) {
            $message = $this->ask('你');

            if ($message === null || strtolower(trim($message)) === 'exit') {
                $this->info('再见！');
                break;
            }

            if (strtolower(trim($message)) === 'clear') {
                $chatService->clearHistory($userId);
                $this->info('对话历史已清除。');
                $this->newLine();

                continue;
            }

            if (trim($message) === '') {
                continue;
            }

            $this->output->write('<fg=gray>思考中...</>');

            $reply = $chatService->chat($userId, $message);

            // 清除"思考中..."
            $this->output->write("\r".str_repeat(' ', 20)."\r");

            $this->line("<fg=green>助手</>: {$reply}");
            $this->newLine();
        }

        return 0;
    }
}

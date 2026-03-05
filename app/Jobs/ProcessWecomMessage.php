<?php

namespace App\Jobs;

use App\Models\WecomBotMessage;
use App\Services\ChatService;
use App\Wecom\WecomMessageClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 异步处理企微用户消息
 * 调用 AI 对话服务获取回复，将结果写入 Cache 供流式响应读取
 */
class ProcessWecomMessage implements ShouldQueue
{
    use Queueable;

    /** 不重试，防止重复回复 */
    public int $tries = 1;

    /** AI 处理可能较慢，设置较长超时 */
    public int $timeout = 120;

    /**
     * @param  string  $userId  企微用户 userid
     * @param  string  $content  用户发送的文本消息内容
     * @param  string  $streamId  流式响应 ID
     * @param  string  $responseUrl  备用推送 URL
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $content,
        public readonly string $streamId,
        public readonly string $responseUrl,
    ) {}

    /**
     * 执行 Job：AI 对话 → 写入 Cache（流式响应）
     * 成功时写入 finish: true + content，失败时写入 finish: true + error
     *
     * @param  ChatService  $chatService  AI 对话服务
     */
    public function handle(ChatService $chatService): void
    {
        Log::info('ProcessWecomMessage 开始处理', [
            'userId' => $this->userId,
            'streamId' => $this->streamId,
            'content' => $this->content,
        ]);

        try {
            $reply = $chatService->chat($this->userId, $this->content, function (string $progress) {
                Cache::put("wecom_stream:{$this->streamId}", [
                    'finish' => false,
                    'content' => $progress,
                ], 600);
            });

            // 写入 Cache（流式响应完成）
            Cache::put("wecom_stream:{$this->streamId}", [
                'finish' => true,
                'content' => $reply,
            ], 600);

            // 更新 DB 记录
            WecomBotMessage::where('stream_id', $this->streamId)->update([
                'reply' => $reply,
                'replied_at' => now(),
            ]);

            Log::info('ProcessWecomMessage 处理完成', [
                'userId' => $this->userId,
                'streamId' => $this->streamId,
            ]);
        } catch (\Throwable $e) {
            Log::error('ProcessWecomMessage 处理失败', [
                'userId' => $this->userId,
                'streamId' => $this->streamId,
                'error' => $e->getMessage(),
            ]);

            // 写入 Cache（错误状态）
            Cache::put("wecom_stream:{$this->streamId}", [
                'finish' => true,
                'error' => '抱歉，消息处理失败，请稍后重试。',
            ], 600);

            // 备用：通过 WecomMessageClient 兜底推送
            try {
                app(WecomMessageClient::class)->sendText($this->userId, '抱歉，消息处理失败，请稍后重试。');
            } catch (\Throwable $notifyError) {
                Log::error('ProcessWecomMessage 兜底推送失败', [
                    'userId' => $this->userId,
                    'error' => $notifyError->getMessage(),
                ]);
            }
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWecomMessage;
use App\Models\WecomBotMessage;
use App\Services\UserProfileService;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 企微 AI Bot 回调控制器
 * 处理 URL 验证（GET）和消息接收（POST），支持流式响应机制
 */
class WecomCallbackController extends Controller
{
    /**
     * 统一处理企微回调请求
     * GET: URL 验证 — 由 EasyWeChat Server 自动处理
     * POST: 消息接收 — 通过 with() 注册消息处理器，返回流式响应
     *
     * @param  Request  $request  HTTP 请求
     * @return Response HTTP 响应
     */
    public function handle(Request $request): Response
    {
        /** @var WecomApp $app */
        $app = app('wecom.bot');
        $app->setRequestFromSymfonyRequest($request);

        $server = $app->getServer(messageType: 'json');

        $server->with(function ($message, $next) {
            return $this->handleMessage($message);
        });

        $psrResponse = $server->serve();

        return new Response(
            (string) $psrResponse->getBody(),
            $psrResponse->getStatusCode(),
        );
    }

    /**
     * 消息路由分发
     * 根据 msgtype 分发到对应处理方法
     *
     * @param  \EasyWeChat\Work\Message  $message  解密后的消息对象
     * @return array|null 流式响应数组，未知消息类型返回 null
     */
    private function handleMessage($message): ?array
    {
        $msgType = $message->msgtype ?? '';

        Log::debug('WecomCallbackController 收到消息', [
            'msgtype' => $msgType,
            'msgid' => $message->msgid ?? null,
        ]);

        return match ($msgType) {
            'text' => $this->handleTextMessage($message),
            'voice' => $this->handleVoiceMessage($message),
            'stream' => $this->handleStreamMessage($message),
            'event' => $this->handleEventMessage($message),
            default => null,
        };
    }

    /**
     * 处理文本消息
     * 提取 text.content 字段，保留 @ 前缀
     *
     * @param  \EasyWeChat\Work\Message  $message  消息对象
     * @return array 流式响应
     */
    private function handleTextMessage($message): array
    {
        $content = $message->text['content'] ?? '';

        return $this->processNewMessage($message, $content);
    }

    /**
     * 处理语音消息
     * 提取 voice.content 语音转写文本
     *
     * @param  \EasyWeChat\Work\Message  $message  消息对象
     * @return array 流式响应
     */
    private function handleVoiceMessage($message): array
    {
        $content = $message->voice['content'] ?? '';

        return $this->processNewMessage($message, $content);
    }

    /**
     * 处理事件消息
     * 根据 event.eventtype 分发，目前支持 enter_chat（进入会话）
     *
     * @param  \EasyWeChat\Work\Message  $message  消息对象
     * @return array|null 响应数组，不支持的事件返回 null
     */
    private function handleEventMessage($message): ?array
    {
        $eventType = $message->event['eventtype'] ?? '';
        $userId = (string) ($message->from['userid'] ?? '');

        Log::debug('WecomCallbackController 收到事件', [
            'eventtype' => $eventType,
            'userid' => $userId,
        ]);

        return match ($eventType) {
            'enter_chat' => $this->handleEnterChat($userId),
            default => null,
        };
    }

    /**
     * 处理用户进入会话事件
     * 根据用户 profile 生成个性化或默认欢迎语，5 秒内返回
     *
     * @param  string  $userId  用户 userid
     * @return array 欢迎语响应数组
     */
    private function handleEnterChat(string $userId): array
    {
        $profileService = app(UserProfileService::class);
        $welcomeText = $profileService->buildWelcomeMessage($userId);

        Log::info('WecomCallbackController 发送欢迎语', [
            'userid' => $userId,
            'welcome' => $welcomeText,
        ]);

        return [
            'msgtype' => 'text',
            'text' => [
                'content' => $welcomeText,
            ],
        ];
    }

    /**
     * 处理新消息（文本/语音通用流程）
     * MsgId 去重 → 存 DB → 初始化 Cache → 派发 Job → 返回流式响应
     *
     * @param  \EasyWeChat\Work\Message  $message  消息对象
     * @param  string  $content  用户消息文本内容
     * @return array 流式响应
     */
    private function processNewMessage($message, string $content): array
    {
        $msgId = (string) ($message->msgid ?? '');
        $userId = (string) ($message->from['userid'] ?? '');

        // MsgId 去重（5 分钟窗口，防止企微重复推送）
        if ($msgId !== '') {
            $cacheKey = "wecom_msg:{$msgId}";
            if (Cache::has($cacheKey)) {
                Log::debug('WecomCallbackController 消息去重', ['msgid' => $msgId]);

                // 从 DB 查已有 stream_id，返回有效响应
                $existing = WecomBotMessage::where('msgid', $msgId)->first();
                if ($existing) {
                    $cached = Cache::get("wecom_stream:{$existing->stream_id}");

                    return $this->buildStreamResponse(
                        $existing->stream_id,
                        $cached['finish'] ?? false,
                        $cached['error'] ?? $cached['content'] ?? '正在思考中...',
                    );
                }

                return $this->buildStreamResponse(Str::ulid(), false, '正在思考中...');
            }
            Cache::put($cacheKey, true, 300);
        }

        $streamId = (string) Str::ulid();

        // 存 DB
        WecomBotMessage::create([
            'msgid' => $msgId,
            'aibotid' => (string) ($message->aibotid ?? ''),
            'chatid' => $message->chatid ?? null,
            'chattype' => (string) ($message->chattype ?? 'single'),
            'userid' => $userId,
            'msgtype' => (string) ($message->msgtype ?? ''),
            'content' => $content,
            'stream_id' => $streamId,
            'response_url' => (string) ($message->response_url ?? ''),
        ]);

        // 初始化 Cache
        Cache::put("wecom_stream:{$streamId}", [
            'finish' => false,
            'content' => '正在思考中...',
        ], 600);

        // 派发 Job
        ProcessWecomMessage::dispatch($userId, $content, $streamId, (string) ($message->response_url ?? ''));

        Log::info('WecomCallbackController 已派发消息处理 Job', [
            'userId' => $userId,
            'streamId' => $streamId,
            'contentLength' => mb_strlen($content),
        ]);

        return $this->buildStreamResponse($streamId, false, '正在思考中...');
    }

    /**
     * 处理 stream 刷新请求
     * 企微每秒回调一次拉取最新内容，从 Cache 读取当前状态
     *
     * @param  \EasyWeChat\Work\Message  $message  消息对象
     * @return array 流式响应（包含当前内容和完成状态）
     */
    private function handleStreamMessage($message): array
    {
        $streamId = (string) ($message->stream['id'] ?? '');

        Log::debug('WecomCallbackController stream 刷新', ['streamId' => $streamId]);

        $cached = Cache::get("wecom_stream:{$streamId}");

        if ($cached === null) {
            return $this->buildStreamResponse($streamId, true, '会话已过期，请重新发送消息。');
        }

        return $this->buildStreamResponse(
            $streamId,
            $cached['finish'] ?? false,
            $cached['error'] ?? $cached['content'] ?? '',
        );
    }

    /**
     * 构建流式响应数组
     * 返回符合企微 AI Bot 流式响应格式的数组
     *
     * @param  string  $streamId  流 ID
     * @param  bool  $finish  是否完成
     * @param  string  $content  响应内容
     * @return array 流式响应数组
     */
    private function buildStreamResponse(string $streamId, bool $finish, string $content): array
    {
        return [
            'msgtype' => 'stream',
            'stream' => [
                'id' => $streamId,
                'finish' => $finish,
                'content' => $content,
            ],
        ];
    }
}

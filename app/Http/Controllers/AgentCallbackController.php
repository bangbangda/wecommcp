<?php

namespace App\Http\Controllers;

use App\Services\ExternalContactService;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * 企微自建应用（agent）回调控制器
 * 处理客户联系相关事件回调（外部联系人变更等）
 */
class AgentCallbackController extends Controller
{
    /**
     * 统一处理企微 agent 应用回调请求
     * GET: URL 验证 — 由 EasyWeChat Server 自动处理
     * POST: 事件接收 — 分发到对应事件处理器
     *
     * @param  Request  $request  HTTP 请求
     * @return Response HTTP 响应
     */
    public function handle(Request $request): Response
    {
        /** @var WecomApp $app */
        $app = app('wecom.app');
        $app->setRequestFromSymfonyRequest($request);

        $server = $app->getServer();

        $server->with(function ($message, $next) {
            return $this->handleEvent($message);
        });

        $psrResponse = $server->serve();

        return new Response(
            (string) $psrResponse->getBody(),
            $psrResponse->getStatusCode(),
        );
    }

    /**
     * 事件路由分发
     * 根据 Event 类型分发到对应处理方法
     *
     * @param  mixed  $message  解密后的消息/事件对象
     * @return string 返回 success 表示处理成功
     */
    private function handleEvent($message): string
    {
        $event = $message['Event'] ?? '';
        $changeType = $message['ChangeType'] ?? '';

        Log::debug('AgentCallbackController 收到事件', [
            'event' => $event,
            'change_type' => $changeType,
        ]);

        if ($event === 'change_external_contact') {
            $this->handleExternalContactChange($message, $changeType);
        }

        return 'success';
    }

    /**
     * 处理外部联系人变更事件
     * 根据 ChangeType 分发到 ExternalContactService 的对应方法
     *
     * @param  mixed  $message  事件消息
     * @param  string  $changeType  变更类型
     */
    private function handleExternalContactChange($message, string $changeType): void
    {
        $userid = $message['UserID'] ?? '';
        $externalUserid = $message['ExternalUserID'] ?? '';

        if (empty($userid) || empty($externalUserid)) {
            Log::warning('AgentCallbackController 外部联系人事件缺少必要字段', [
                'change_type' => $changeType,
                'userid' => $userid,
                'external_userid' => $externalUserid,
            ]);

            return;
        }

        /** @var ExternalContactService $service */
        $service = app(ExternalContactService::class);

        match ($changeType) {
            'add_external_contact', 'add_half_external_contact' => $service->handleAddEvent($userid, $externalUserid),
            'edit_external_contact' => $service->handleEditEvent($userid, $externalUserid),
            'del_external_contact' => $service->handleDeleteByUserEvent($userid, $externalUserid),
            'del_follow_user' => $service->handleDeleteByCustomerEvent($userid, $externalUserid),
            default => Log::debug("AgentCallbackController 未处理的 ChangeType: {$changeType}"),
        };
    }
}

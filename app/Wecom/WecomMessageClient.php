<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Support\Facades\Log;

class WecomMessageClient
{
    public function __construct(
        private WecomApp $app,
        private string $agentId,
    ) {}

    /**
     * 发送文本消息给指定用户
     * API: POST /cgi-bin/message/send
     *
     * @param  string  $toUser  接收消息的用户 userid
     * @param  string  $content  文本消息内容
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function sendText(string $toUser, string $content): array
    {
        $params = [
            'touser' => $toUser,
            'msgtype' => 'text',
            'agentid' => (int) $this->agentId,
            'text' => [
                'content' => $content,
            ],
        ];

        Log::debug('WecomMessageClient::sendText 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/message/send', $params)->toArray();

        Log::debug('WecomMessageClient::sendText 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 检查 API 响应，errcode 不为 0 时抛出异常
     *
     * @param  array  $response  API 响应数组
     *
     * @throws WecomApiException errcode != 0 时抛出
     */
    private function checkResponse(array $response): void
    {
        $errcode = $response['errcode'] ?? 0;

        if ($errcode !== 0) {
            $errmsg = $response['errmsg'] ?? '未知错误';
            Log::error('WecomMessageClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

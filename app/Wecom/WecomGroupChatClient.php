<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Support\Facades\Log;

class WecomGroupChatClient
{
    public function __construct(private WecomApp $app) {}

    /**
     * 创建群聊会话
     * API: POST /cgi-bin/appchat/create
     *
     * @param  array  $userlist  群成员 userid 列表（至少 2 人，至多 2000 人）
     * @param  string  $name  群聊名（最多 50 个 utf8 字符）
     * @param  string  $owner  群主 userid（不指定则随机选一人）
     * @return array API 原始响应（含 chatid）
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function createGroupChat(array $userlist, string $name = '', string $owner = ''): array
    {
        $params = [
            'userlist' => $userlist,
        ];

        if ($name !== '') {
            $params['name'] = $name;
        }

        if ($owner !== '') {
            $params['owner'] = $owner;
        }

        Log::debug('WecomGroupChatClient::createGroupChat 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/appchat/create', $params)->toArray();

        Log::debug('WecomGroupChatClient::createGroupChat 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 修改群聊会话
     * API: POST /cgi-bin/appchat/update
     *
     * @param  string  $chatid  群聊 ID
     * @param  string  $name  新群名（空字符串表示不修改）
     * @param  string  $owner  新群主 userid（空字符串表示不修改）
     * @param  array  $addUserList  添加成员 userid 列表
     * @param  array  $delUserList  踢出成员 userid 列表
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function updateGroupChat(string $chatid, string $name = '', string $owner = '', array $addUserList = [], array $delUserList = []): array
    {
        $params = [
            'chatid' => $chatid,
        ];

        if ($name !== '') {
            $params['name'] = $name;
        }

        if ($owner !== '') {
            $params['owner'] = $owner;
        }

        if (! empty($addUserList)) {
            $params['add_user_list'] = $addUserList;
        }

        if (! empty($delUserList)) {
            $params['del_user_list'] = $delUserList;
        }

        Log::debug('WecomGroupChatClient::updateGroupChat 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/appchat/update', $params)->toArray();

        Log::debug('WecomGroupChatClient::updateGroupChat 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 获取群聊会话详情
     * API: GET /cgi-bin/appchat/get
     *
     * @param  string  $chatid  群聊 ID
     * @return array API 原始响应（含 chat_info）
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getGroupChat(string $chatid): array
    {
        Log::debug('WecomGroupChatClient::getGroupChat 请求参数', ['chatid' => $chatid]);

        $client = $this->app->getClient();
        $response = $client->get('/cgi-bin/appchat/get', ['chatid' => $chatid])->toArray();

        Log::debug('WecomGroupChatClient::getGroupChat 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 应用推送消息到群聊
     * API: POST /cgi-bin/appchat/send
     *
     * @param  string  $chatid  群聊 ID
     * @param  string  $msgtype  消息类型（text / markdown）
     * @param  array  $content  消息内容，格式依 msgtype 而定
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function sendMessage(string $chatid, string $msgtype, array $content): array
    {
        $params = [
            'chatid' => $chatid,
            'msgtype' => $msgtype,
            $msgtype => $content,
        ];

        Log::debug('WecomGroupChatClient::sendMessage 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/appchat/send', $params)->toArray();

        Log::debug('WecomGroupChatClient::sendMessage 返回结果', $response);

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
            Log::error('WecomGroupChatClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

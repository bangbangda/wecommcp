<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use Illuminate\Support\Facades\Log;

class WecomContactClient
{
    public function __construct(
        private WecomManager $manager,
    ) {}

    /**
     * 获取成员ID列表
     * API: POST /cgi-bin/user/list_id
     * 文档: https://developer.work.weixin.qq.com/document/path/96067
     *
     * @param  string|null  $cursor  分页游标，首次传空
     * @param  int  $limit  每页数量，最大 10000
     * @return array{dept_user: array, next_cursor: string} dept_user 包含 userid 和 department
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getUserIdList(?string $cursor = null, int $limit = 10000): array
    {
        $params = [
            'cursor' => $cursor ?? '',
            'limit' => $limit,
        ];

        Log::debug('WecomContactClient::getUserIdList 请求参数', $params);

        $client = $this->manager->app('contact')->getClient();
        $response = $client->postJson('/cgi-bin/user/list_id', $params)->toArray();

        Log::debug('WecomContactClient::getUserIdList 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 读取成员详情
     * API: GET /cgi-bin/user/get
     * 文档: https://developer.work.weixin.qq.com/document/path/90196
     *
     * @param  string  $userid  成员 userid
     * @return array 成员详情（name, department, position, mobile, email 等）
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getUser(string $userid): array
    {
        $params = ['userid' => $userid];

        Log::debug('WecomContactClient::getUser 请求参数', $params);

        $client = $this->manager->app('agent')->getClient();
        $response = $client->get('/cgi-bin/user/get', $params)->toArray();

        Log::debug('WecomContactClient::getUser 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 获取部门详情
     * API: GET /cgi-bin/department/get
     * 文档: https://developer.work.weixin.qq.com/document/path/86664
     *
     * @param  int  $id  部门 ID
     * @return array 部门详情（id, name, parentid 等）
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getDepartment(int $id): array
    {
        $params = ['id' => $id];

        Log::debug('WecomContactClient::getDepartment 请求参数', $params);

        $client = $this->manager->app('agent')->getClient();
        $response = $client->get('/cgi-bin/department/get', $params)->toArray();

        Log::debug('WecomContactClient::getDepartment 返回结果', $response);

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
            Log::error('WecomContactClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

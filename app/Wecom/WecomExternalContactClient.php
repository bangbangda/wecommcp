<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use Illuminate\Support\Facades\Log;

class WecomExternalContactClient
{
    public function __construct(
        private WecomManager $manager,
    ) {}

    /**
     * 获取指定员工的客户列表
     * API: GET /cgi-bin/externalcontact/list
     * 文档 doc_id: 15445
     *
     * @param  string  $userid  内部员工 userid
     * @return array external_userid 列表
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getContactList(string $userid): array
    {
        $params = ['userid' => $userid];

        Log::debug('WecomExternalContactClient::getContactList 请求参数', $params);

        $client = $this->manager->app('agent')->getClient();
        $response = $client->get('/cgi-bin/externalcontact/list', $params)->toArray();

        Log::debug('WecomExternalContactClient::getContactList 返回结果', $response);

        $this->checkResponse($response);

        return $response['external_userid'] ?? [];
    }

    /**
     * 获取客户详情（含所有跟进人信息）
     * API: GET /cgi-bin/externalcontact/get
     * 文档 doc_id: 13878
     *
     * @param  string  $externalUserid  外部联系人 userid
     * @return array 包含 external_contact 和 follow_user 的详情
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getContactDetail(string $externalUserid): array
    {
        $params = ['external_userid' => $externalUserid];

        Log::debug('WecomExternalContactClient::getContactDetail 请求参数', $params);

        $client = $this->manager->app('agent')->getClient();
        $response = $client->get('/cgi-bin/externalcontact/get', $params)->toArray();

        Log::debug('WecomExternalContactClient::getContactDetail 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 批量获取指定员工的客户列表及详情
     * API: POST /cgi-bin/externalcontact/batch/get_by_user
     * 文档 doc_id: 23414
     *
     * @param  array  $useridList  内部员工 userid 列表，最多 100 个
     * @param  string  $cursor  分页游标，首次传空
     * @param  int  $limit  每页数量，最大 100
     * @return array 包含 external_contact_list 和 next_cursor
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function batchGetByUser(array $useridList, string $cursor = '', int $limit = 100): array
    {
        $params = [
            'userid_list' => $useridList,
            'cursor' => $cursor,
            'limit' => $limit,
        ];

        Log::debug('WecomExternalContactClient::batchGetByUser 请求参数', $params);

        $client = $this->manager->app('agent')->getClient();
        $response = $client->postJson('/cgi-bin/externalcontact/batch/get_by_user', $params)->toArray();

        Log::debug('WecomExternalContactClient::batchGetByUser 返回结果', [
            'errcode' => $response['errcode'] ?? null,
            'count' => count($response['external_contact_list'] ?? []),
            'has_next' => ! empty($response['next_cursor'] ?? ''),
        ]);

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
            Log::error('WecomExternalContactClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

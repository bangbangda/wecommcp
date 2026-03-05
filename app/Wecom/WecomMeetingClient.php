<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Support\Facades\Log;

class WecomMeetingClient
{
    public function __construct(private WecomApp $app) {}

    /**
     * 创建预约会议
     * API: POST /cgi-bin/meeting/create
     *
     * @param  string  $adminUserid  发起人 userid
     * @param  string  $title  会议主题
     * @param  int  $startTime  开始时间（Unix 时间戳）
     * @param  int  $duration  时长（秒）
     * @param  array  $inviteeUserids  参会人 userid 列表
     * @return array{meetingid: string, ...} API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function createMeeting(string $adminUserid, string $title, int $startTime, int $duration, array $inviteeUserids = []): array
    {
        $params = [
            'admin_userid' => $adminUserid,
            'title' => $title,
            'meeting_start' => (string) $startTime,
            'meeting_duration' => $duration,
            'invitees' => [
                'userid' => array_merge([$adminUserid], $inviteeUserids),
            ],
        ];

        Log::debug('WecomMeetingClient::createMeeting 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/meeting/create', $params)->toArray();

        Log::debug('WecomMeetingClient::createMeeting 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 取消会议
     * API: POST /cgi-bin/meeting/cancel
     *
     * @param  string  $meetingid  会议 ID
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function cancelMeeting(string $meetingid): array
    {
        $params = ['meetingid' => $meetingid];

        Log::debug('WecomMeetingClient::cancelMeeting 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/meeting/cancel', $params)->toArray();

        Log::debug('WecomMeetingClient::cancelMeeting 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 修改会议
     * API: POST /cgi-bin/meeting/update
     *
     * @param  string  $meetingid  会议 ID
     * @param  array  $params  更新字段（title, meeting_start, meeting_duration, invitees 等）
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function updateMeeting(string $meetingid, array $params): array
    {
        $params['meetingid'] = $meetingid;

        Log::debug('WecomMeetingClient::updateMeeting 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/meeting/update', $params)->toArray();

        Log::debug('WecomMeetingClient::updateMeeting 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 查询会议详情
     * API: POST /cgi-bin/meeting/get_info
     *
     * @param  string  $meetingid  会议 ID
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getMeetingInfo(string $meetingid): array
    {
        $params = ['meetingid' => $meetingid];

        Log::debug('WecomMeetingClient::getMeetingInfo 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/meeting/get_info', $params)->toArray();

        Log::debug('WecomMeetingClient::getMeetingInfo 返回结果', $response);

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
            Log::error('WecomMeetingClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

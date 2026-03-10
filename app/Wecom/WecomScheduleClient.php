<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Support\Facades\Log;

class WecomScheduleClient
{
    public function __construct(private WecomApp $app) {}

    /**
     * 创建日历
     * API: POST /cgi-bin/oa/calendar/add
     *
     * @param  string  $summary  日历标题（1~128 字符）
     * @param  string  $color  颜色 RGB 编码（必填，如 "#0000FF"）
     * @param  string  $description  日历描述（0~512 字符）
     * @param  array  $shares  日历通知范围成员列表，格式 [['userid' => 'xxx', 'permission' => 1], ...]
     * @param  array  $admins  管理员 userid 列表（最多 3 人，须在 shares 中）
     * @param  bool  $isPublic  是否公共日历
     * @param  bool  $isCorpCalendar  是否全员日历（全员日历也是公共日历，需指定 publicRange）
     * @param  array  $publicRange  公开范围，格式 ['userids' => [...], 'partyids' => [...]]
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function createCalendar(
        string $summary,
        string $color = '#3F51B5',
        string $description = '',
        array $shares = [],
        array $admins = [],
        bool $isPublic = false,
        bool $isCorpCalendar = false,
        array $publicRange = [],
    ): array {
        $calendar = [
            'summary' => $summary,
            'color' => $color,
        ];

        if ($description !== '') {
            $calendar['description'] = $description;
        }

        if (! empty($shares)) {
            $calendar['shares'] = $shares;
        }

        if (! empty($admins)) {
            $calendar['admins'] = $admins;
        }

        if ($isPublic) {
            $calendar['is_public'] = 1;
        }

        if ($isCorpCalendar) {
            $calendar['is_corp_calendar'] = 1;
        }

        if (! empty($publicRange)) {
            $calendar['public_range'] = $publicRange;
        }

        $params = ['calendar' => $calendar];

        Log::debug('WecomScheduleClient::createCalendar 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/calendar/add', $params)->toArray();

        Log::debug('WecomScheduleClient::createCalendar 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 获取日历详情
     * API: POST /cgi-bin/oa/calendar/get
     *
     * @param  array  $calIdList  日历 ID 列表（一次最多 1000 条）
     * @return array API 原始响应（含 calendar_list）
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getCalendars(array $calIdList): array
    {
        $params = ['cal_id_list' => $calIdList];

        Log::debug('WecomScheduleClient::getCalendars 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/calendar/get', $params)->toArray();

        Log::debug('WecomScheduleClient::getCalendars 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 创建日程
     * API: POST /cgi-bin/oa/schedule/add
     *
     * @param  string  $summary  日程标题（0~128 字符，不填默认"新建事件"）
     * @param  int  $startTime  开始时间（Unix 时间戳，必填）
     * @param  int  $endTime  结束时间（Unix 时间戳，必填）
     * @param  array  $attendeeUserids  参与者 userid 列表（最多 1000 人）
     * @param  string  $calId  日历 ID，为空时使用应用默认日历
     * @param  string  $location  日程地点（最多 128 字符）
     * @param  string  $description  日程描述（最多 1000 字符）
     * @param  array  $reminders  提醒相关信息（直接传企微 API 格式）
     * @param  bool  $isWholeDay  是否全天日程
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function createSchedule(
        string $summary,
        int $startTime,
        int $endTime,
        array $attendeeUserids = [],
        string $calId = '',
        string $location = '',
        string $description = '',
        array $reminders = [],
        bool $isWholeDay = false,
    ): array {
        $schedule = [
            'summary' => $summary,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];

        if (! empty($attendeeUserids)) {
            $schedule['attendees'] = array_map(fn ($userid) => ['userid' => $userid], $attendeeUserids);
        }

        if ($calId !== '') {
            $schedule['cal_id'] = $calId;
        }

        if ($location !== '') {
            $schedule['location'] = $location;
        }

        if ($description !== '') {
            $schedule['description'] = $description;
        }

        if (! empty($reminders)) {
            $schedule['reminders'] = $reminders;
        }

        if ($isWholeDay) {
            $schedule['is_whole_day'] = 1;
        }

        $params = ['schedule' => $schedule];

        Log::debug('WecomScheduleClient::createSchedule 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/schedule/add', $params)->toArray();

        Log::debug('WecomScheduleClient::createSchedule 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 按日历查询日程列表
     * API: POST /cgi-bin/oa/schedule/get_by_calendar
     *
     * @param  string  $calId  日历 ID
     * @param  int  $offset  偏移量
     * @param  int  $limit  数量限制
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getSchedulesByCalendar(string $calId, int $offset = 0, int $limit = 500): array
    {
        $params = [
            'cal_id' => $calId,
            'offset' => $offset,
            'limit' => $limit,
        ];

        Log::debug('WecomScheduleClient::getSchedulesByCalendar 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/schedule/get_by_calendar', $params)->toArray();

        Log::debug('WecomScheduleClient::getSchedulesByCalendar 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 获取日程详情
     * API: POST /cgi-bin/oa/schedule/get
     *
     * @param  array  $scheduleIds  日程 ID 列表
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getScheduleDetail(array $scheduleIds): array
    {
        $params = ['schedule_id_list' => $scheduleIds];

        Log::debug('WecomScheduleClient::getScheduleDetail 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/schedule/get', $params)->toArray();

        Log::debug('WecomScheduleClient::getScheduleDetail 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 取消（删除）日程
     * API: POST /cgi-bin/oa/schedule/del
     *
     * @param  string  $scheduleId  日程 ID
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function cancelSchedule(string $scheduleId): array
    {
        $params = ['schedule_id' => $scheduleId];

        Log::debug('WecomScheduleClient::cancelSchedule 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/schedule/del', $params)->toArray();

        Log::debug('WecomScheduleClient::cancelSchedule 返回结果', $response);

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
            Log::error('WecomScheduleClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

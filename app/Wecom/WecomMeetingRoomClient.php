<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Support\Facades\Log;

class WecomMeetingRoomClient
{
    public function __construct(private WecomApp $app) {}

    /**
     * 查询会议室列表
     * API: POST /cgi-bin/oa/meetingroom/list
     *
     * @param  array  $filters  过滤条件（city, building, floor, equipment 均可选）
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function listRooms(array $filters = []): array
    {
        $params = array_filter($filters);

        Log::debug('WecomMeetingRoomClient::listRooms 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/meetingroom/list', $params ?: [])->toArray();

        Log::debug('WecomMeetingRoomClient::listRooms 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 查询会议室预定信息
     * API: POST /cgi-bin/oa/meetingroom/get_booking_info
     *
     * @param  array  $params  查询参数（meetingroom_id, start_time, end_time, city, building, floor）
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getBookingInfo(array $params): array
    {
        Log::debug('WecomMeetingRoomClient::getBookingInfo 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/meetingroom/get_booking_info', $params)->toArray();

        Log::debug('WecomMeetingRoomClient::getBookingInfo 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 预定会议室
     * API: POST /cgi-bin/oa/meetingroom/book
     *
     * @param  int  $meetingRoomId  会议室 ID
     * @param  int  $startTime  开始时间（Unix 时间戳）
     * @param  int  $endTime  结束时间（Unix 时间戳）
     * @param  string  $booker  预定人 userid
     * @param  string|null  $subject  会议主题
     * @param  array  $attendees  参会人 userid 列表
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function bookRoom(int $meetingRoomId, int $startTime, int $endTime, string $booker, ?string $subject = null, array $attendees = []): array
    {
        $params = [
            'meetingroom_id' => $meetingRoomId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'booker' => $booker,
        ];

        if ($subject !== null) {
            $params['subject'] = $subject;
        }

        if (! empty($attendees)) {
            $params['attendees'] = $attendees;
        }

        Log::debug('WecomMeetingRoomClient::bookRoom 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/meetingroom/book', $params)->toArray();

        Log::debug('WecomMeetingRoomClient::bookRoom 返回结果', $response);

        $this->checkResponse($response);

        return $response;
    }

    /**
     * 取消会议室预定
     * API: POST /cgi-bin/oa/meetingroom/cancel_book
     *
     * @param  string  $bookingId  预定 ID
     * @param  int  $keepSchedule  是否保留关联日程（0=删除，1=保留），默认 0
     * @return array API 原始响应
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function cancelBooking(string $bookingId, int $keepSchedule = 0): array
    {
        $params = [
            'booking_id' => $bookingId,
            'keep_schedule' => $keepSchedule,
        ];

        Log::debug('WecomMeetingRoomClient::cancelBooking 请求参数', $params);

        $client = $this->app->getClient();
        $response = $client->postJson('/cgi-bin/oa/meetingroom/cancel_book', $params)->toArray();

        Log::debug('WecomMeetingRoomClient::cancelBooking 返回结果', $response);

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
            Log::error('WecomMeetingRoomClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

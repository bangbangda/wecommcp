<?php

namespace App\Mcp\Tools\MeetingRoom;

use App\Wecom\WecomMeetingRoomClient;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('query_room_bookings')]
#[Description('查询企业微信会议室的预定信息。当用户说"会议室被谁订了""看看明天会议室有没有空""查一下这个会议室的预定情况"时使用此工具。可按会议室 ID、时间范围、城市、楼宇、楼层过滤。不支持跨天查询，默认查询当天。此工具仅查询预定信息，不执行预定或取消操作。取消预定需先用此工具获取 booking_id 再调用 cancel_room_booking。')]
class QueryRoomBookingsTool extends Tool
{
    /**
     * 定义 Tool 参数 schema
     *
     * @param  JsonSchema  $schema  JSON Schema 构建器
     * @return array schema 定义
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meetingroom_id' => $schema->integer('会议室 ID，不填则查询所有会议室'),
            'start_time' => $schema->string('查询起始时间，ISO 8601 格式，默认当前时间'),
            'end_time' => $schema->string('查询结束时间，ISO 8601 格式，默认当天 23:59:59（不支持跨天）'),
            'city' => $schema->string('城市过滤'),
            'building' => $schema->string('楼宇过滤'),
            'floor' => $schema->integer('楼层过滤'),
        ];
    }

    /**
     * 处理查询会议室预定信息请求
     * 根据过滤条件调用企微 API 查询预定详情
     *
     * @param  Request  $request  MCP 请求
     * @param  WecomMeetingRoomClient  $wecomMeetingRoomClient  企微会议室客户端
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomMeetingRoomClient $wecomMeetingRoomClient): Response
    {
        $data = $request->validate([
            'meetingroom_id' => 'nullable|integer',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'city' => 'nullable|string|max:255',
            'building' => 'nullable|string|max:255',
            'floor' => 'nullable|integer',
        ]);

        Log::debug('QueryRoomBookingsTool::handle 收到请求', $data);

        // 默认查询当天
        $now = Carbon::now('Asia/Shanghai');

        try {
            $startCarbon = ! empty($data['start_time'])
                ? Carbon::parse($data['start_time'], 'Asia/Shanghai')
                : $now->copy();
            $endCarbon = ! empty($data['end_time'])
                ? Carbon::parse($data['end_time'], 'Asia/Shanghai')
                : $now->copy()->endOfDay();
        } catch (\Exception $e) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '无法解析时间，请使用 ISO 8601 格式，如 2026-03-02T14:00:00',
            ], JSON_UNESCAPED_UNICODE));
        }

        $params = [
            'start_time' => $startCarbon->getTimestamp(),
            'end_time' => $endCarbon->getTimestamp(),
        ];

        if (! empty($data['meetingroom_id'])) {
            $params['meetingroom_id'] = $data['meetingroom_id'];
        }
        if (! empty($data['city'])) {
            $params['city'] = $data['city'];
        }
        if (! empty($data['building'])) {
            $params['building'] = $data['building'];
        }
        if (! empty($data['floor'])) {
            $params['floor'] = $data['floor'];
        }

        $apiResult = $wecomMeetingRoomClient->getBookingInfo($params);

        $bookingList = $apiResult['booking_list'] ?? [];

        if (empty($bookingList)) {
            $result = [
                'status' => 'empty',
                'count' => 0,
                'bookings' => [],
                'time_range' => [
                    'start' => $startCarbon->toIso8601String(),
                    'end' => $endCarbon->toIso8601String(),
                ],
                'message' => '该时段没有会议室预定记录',
            ];

            Log::debug('QueryRoomBookingsTool::handle 无结果', $result);

            return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        // 格式化预定信息
        $formattedBookings = [];
        foreach ($bookingList as $room) {
            $roomInfo = [
                'meetingroom_id' => $room['meetingroom_id'] ?? 0,
                'meetingroom_name' => $room['meetingroom_name'] ?? '',
                'schedules' => [],
            ];

            foreach ($room['schedule'] ?? [] as $schedule) {
                $roomInfo['schedules'][] = [
                    'booking_id' => $schedule['booking_id'] ?? '',
                    'schedule_id' => $schedule['schedule_id'] ?? '',
                    'start_time' => isset($schedule['start_time'])
                        ? Carbon::createFromTimestamp($schedule['start_time'], 'Asia/Shanghai')->toIso8601String()
                        : '',
                    'end_time' => isset($schedule['end_time'])
                        ? Carbon::createFromTimestamp($schedule['end_time'], 'Asia/Shanghai')->toIso8601String()
                        : '',
                    'booker' => $schedule['booker'] ?? '',
                    'status' => $schedule['status'] ?? 0,
                ];
            }

            $formattedBookings[] = $roomInfo;
        }

        $totalSchedules = collect($formattedBookings)->sum(fn ($room) => count($room['schedules']));

        $result = [
            'status' => 'success',
            'count' => $totalSchedules,
            'bookings' => $formattedBookings,
            'time_range' => [
                'start' => $startCarbon->toIso8601String(),
                'end' => $endCarbon->toIso8601String(),
            ],
            'message' => "找到 {$totalSchedules} 条预定记录",
        ];

        Log::debug('QueryRoomBookingsTool::handle 查询成功', ['count' => $totalSchedules]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

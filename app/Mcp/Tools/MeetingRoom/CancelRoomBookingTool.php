<?php

namespace App\Mcp\Tools\MeetingRoom;

use App\Wecom\WecomMeetingRoomClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('cancel_room_booking')]
#[Description('取消企业微信会议室预定。当用户说"取消会议室预定""不要那个会议室了""退掉预约的会议室"时使用此工具。需要提供 booking_id（预定 ID），可先调用 query_room_bookings 获取。取消后会同步删除关联的日程。此工具仅取消会议室预定，取消在线会议请使用 cancel_meeting。')]
class CancelRoomBookingTool extends Tool
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
            'booking_id' => $schema->string('预定 ID，从 query_room_bookings 结果获取')->required(),
        ];
    }

    /**
     * 处理取消会议室预定请求
     * 调用企微 API 取消预定，默认同步删除关联日程
     *
     * @param  Request  $request  MCP 请求
     * @param  WecomMeetingRoomClient  $wecomMeetingRoomClient  企微会议室客户端
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomMeetingRoomClient $wecomMeetingRoomClient): Response
    {
        $data = $request->validate([
            'booking_id' => 'required|string',
        ]);

        Log::debug('CancelRoomBookingTool::handle 收到请求', $data);

        $apiResult = $wecomMeetingRoomClient->cancelBooking(
            bookingId: $data['booking_id'],
            keepSchedule: 0,
        );

        $result = [
            'status' => 'success',
            'booking_id' => $data['booking_id'],
            'message' => '会议室预定已取消',
        ];

        Log::debug('CancelRoomBookingTool::handle 取消成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

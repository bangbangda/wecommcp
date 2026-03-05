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

#[Name('query_meeting_rooms')]
#[Description('查询企业微信会议室列表。当用户说"有哪些会议室""查一下 18F 的会议室""看看三楼有没有空会议室"时使用此工具。支持按城市、楼宇、楼层、设备过滤。用户可以不指定任何条件，那么将查询所有的会议室信息。此工具用于获取可预定的会议室信息（ID、名称、容量、位置），返回的内容必须包含会ID、议室名称、位置、时间以及会议主体。预定会议室前需先调用此工具获取 meetingroom_id。仅查询，不执行预定操作。')]
class QueryMeetingRoomsTool extends Tool
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
            'city' => $schema->string('城市，如"深圳""北京"'),
            'building' => $schema->string('楼宇，如"科技大厦"'),
            'floor' => $schema->integer('楼层，如 18'),
            'equipment' => $schema->array('设备过滤，如 [1] 表示电视')->items($schema->integer()),
        ];
    }

    /**
     * 处理查询会议室列表请求
     * 根据过滤条件调用企微 API 查询可用会议室
     *
     * @param  Request  $request  MCP 请求
     * @param  WecomMeetingRoomClient  $wecomMeetingRoomClient  企微会议室客户端
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomMeetingRoomClient $wecomMeetingRoomClient): Response
    {
        $data = $request->validate([
            'city' => 'nullable|string|max:255',
            'building' => 'nullable|string|max:255',
            'floor' => 'nullable|integer',
            'equipment' => 'nullable|array',
            'equipment.*' => 'integer',
        ]);

        Log::debug('QueryMeetingRoomsTool::handle 收到请求', $data);

        $filters = array_filter([
            'city' => $data['city'] ?? null,
            'building' => $data['building'] ?? null,
            'floor' => $data['floor'] ?? null,
            'equipment' => $data['equipment'] ?? null,
        ]);

        $apiResult = $wecomMeetingRoomClient->listRooms($filters);

        $rooms = $apiResult['meetingroom_list'] ?? [];

        if (empty($rooms)) {
            $result = [
                'status' => 'empty',
                'count' => 0,
                'rooms' => [],
                'message' => '没有找到符合条件的会议室',
            ];

            Log::debug('QueryMeetingRoomsTool::handle 无结果', $result);

            return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        $result = [
            'status' => 'success',
            'count' => count($rooms),
            'rooms' => collect($rooms)->map(fn (array $room, int $index) => [
                'index' => $index + 1,
                'meetingroom_id' => $room['meetingroom_id'],
                'name' => $room['name'] ?? '',
                'capacity' => $room['capacity'] ?? 0,
                'city' => $room['city'] ?? '',
                'building' => $room['building'] ?? '',
                'floor' => $room['floor'] ?? '',
                'need_approval' => ($room['need_approval'] ?? 0) === 1,
            ])->values()->toArray(),
            'message' => '找到 '.count($rooms).' 个会议室',
        ];

        Log::debug('QueryMeetingRoomsTool::handle 查询成功', ['count' => count($rooms)]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

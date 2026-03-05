<?php

namespace App\Mcp\Tools\Meeting;

use App\Mcp\Tools\Meeting\Concerns\MeetingLookupTrait;
use App\Models\RecentMeeting;
use App\Wecom\WecomMeetingClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_meeting_info')]
#[Description('查询单个企业微信会议的完整详情（参会人、时长、会议链接等）。优先通过 meetingid 精确定位，如果用户没有提供明确的会议标识（如说"查一下刚才那个会"），应先使用 query_meetings 工具查询列表获取 meetingid，再调用此工具。与 query_meetings 的区别：query_meetings 返回会议列表概要，此工具返回单个会议的完整详情。')]
class GetMeetingInfoTool extends Tool
{
    use MeetingLookupTrait;

    /**
     * 定义 Tool 参数 schema
     *
     * @param  JsonSchema  $schema  JSON Schema 构建器
     * @return array schema 定义
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meetingid' => $schema->string('会议 ID，从 query_meetings 结果获取，提供时直接精确定位'),
            'title' => $schema->string('会议主题关键词，meetingid 未提供时必填'),
            'start_time' => $schema->string('会议开始时间，用于缩小查找范围，可选'),
        ];
    }

    /**
     * 处理查询会议详情请求
     * 通过 meetingid 精确定位或标题搜索 → 调用企微 API 获取详情 → 返回结果
     *
     * @param  Request  $request  MCP 请求
     * @param  WecomMeetingClient  $wecomMeetingClient  企微会议服务
     * @param  string|null  $userId  当前用户 ID（从 ChatService 透传）
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomMeetingClient $wecomMeetingClient, ?string $userId = null): Response
    {
        $data = $request->validate([
            'meetingid' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'start_time' => 'nullable|string',
        ]);

        Log::debug('GetMeetingInfoTool::handle 收到请求', $data);

        // meetingid 精确定位
        if (! empty($data['meetingid'])) {
            $meeting = RecentMeeting::where('meetingid', $data['meetingid'])->first();
            if (! $meeting) {
                return Response::text(json_encode([
                    'status' => 'not_found',
                    'message' => "未找到 meetingid={$data['meetingid']} 的会议",
                ], JSON_UNESCAPED_UNICODE));
            }
        } else {
            // 标题搜索定位
            if (empty($data['title'])) {
                return Response::text(json_encode([
                    'status' => 'error',
                    'message' => '请提供 meetingid 或 title 参数',
                ], JSON_UNESCAPED_UNICODE));
            }

            $meetings = $this->lookupMeetings($data['title'], $data['start_time'] ?? null, $userId);
            $errorResponse = $this->resolveOrRespond($meetings);

            if ($errorResponse) {
                return $errorResponse;
            }

            $meeting = $meetings->first();
        }

        // 调用企微 API 查询详情
        $apiResult = $wecomMeetingClient->getMeetingInfo($meeting->meetingid);

        $result = [
            'status' => 'success',
            'title' => $meeting->title,
            'meetingid' => $meeting->meetingid,
            'start_time' => $meeting->start_time->toIso8601String(),
            'duration_minutes' => $meeting->duration_minutes,
            'invitees' => $meeting->invitees,
            'api_detail' => $apiResult,
            'message' => "会议「{$meeting->title}」的详情已获取",
        ];

        Log::debug('GetMeetingInfoTool::handle 查询成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

<?php

namespace App\Mcp\Tools\MeetingRoom;

use App\Services\ContactsService;
use App\Wecom\WecomMeetingRoomClient;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('book_meeting_room')]
#[Description('预定企业微信会议室。当用户说"帮我预定会议室""订个明天下午的会议室""预约 18F 大会议室"时使用此工具。使用前需先调用 query_meeting_rooms 获取会议室 ID（meetingroom_id）。参会人传入中文姓名即可，系统自动匹配为企微用户（支持同音字模糊匹配）。注意：预定时间自动按 30 分钟取整，最小预定时长 30 分钟；仅可预定无需审批的会议室。')]
class BookMeetingRoomTool extends Tool
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
            'meetingroom_id' => $schema->integer('会议室 ID，从 query_meeting_rooms 结果获取')->required(),
            'start_time' => $schema->string('开始时间，ISO 8601 格式，例如 2026-03-02T14:00:00')->required(),
            'end_time' => $schema->string('结束时间，ISO 8601 格式，例如 2026-03-02T15:00:00')->required(),
            'subject' => $schema->string('会议主题'),
            'attendees' => $schema->array('参会人的中文姓名列表')->items($schema->string()),
        ];
    }

    /**
     * 处理预定会议室请求
     * 解析时间 → 解析参会人 → 调用企微 API 预定
     *
     * @param  Request  $request  MCP 请求
     * @param  ContactsService  $contactsService  通讯录服务
     * @param  WecomMeetingRoomClient  $wecomMeetingRoomClient  企微会议室客户端
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, ContactsService $contactsService, WecomMeetingRoomClient $wecomMeetingRoomClient, string $userId): Response
    {
        $data = $request->validate([
            'meetingroom_id' => 'required|integer',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'subject' => 'nullable|string|max:255',
            'attendees' => 'nullable|array',
            'attendees.*' => 'string',
        ]);

        Log::debug('BookMeetingRoomTool::handle 收到请求', $data);

        // 解析开始时间和结束时间
        try {
            $startCarbon = Carbon::parse($data['start_time'], 'Asia/Shanghai');
            $endCarbon = Carbon::parse($data['end_time'], 'Asia/Shanghai');
            $startTimestamp = $startCarbon->getTimestamp();
            $endTimestamp = $endCarbon->getTimestamp();
        } catch (\Exception $e) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '无法解析时间，请使用 ISO 8601 格式，如 2026-03-02T14:00:00',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 校验时间逻辑
        if ($startCarbon->isPast()) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '预定开始时间必须大于当前时间，请重新指定',
            ], JSON_UNESCAPED_UNICODE));
        }

        if ($endTimestamp <= $startTimestamp) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '结束时间必须大于开始时间',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 解析参会人姓名 → 联系人信息
        $attendeeNames = $data['attendees'] ?? [];
        $resolvedAttendees = [];
        $ambiguous = [];

        foreach ($attendeeNames as $name) {
            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $contact = $matches->first();
                $resolvedAttendees[] = [
                    'userid' => $contact->userid,
                    'name' => $contact->name,
                ];
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'input' => $name,
                    'candidates' => $matches->map(fn ($c) => [
                        'name' => $c->name,
                        'department' => $c->department,
                        'position' => $c->position,
                    ])->toArray(),
                ];
            } else {
                $ambiguous[] = [
                    'input' => $name,
                    'candidates' => [],
                    'message' => "未找到联系人「{$name}」",
                ];
            }
        }

        // 如果存在歧义，返回让 AI 追问用户
        if (! empty($ambiguous)) {
            Log::debug('BookMeetingRoomTool::handle 参会人歧义', ['resolved' => $resolvedAttendees, 'ambiguous' => $ambiguous]);

            return Response::text(json_encode([
                'status' => 'need_clarification',
                'resolved' => $resolvedAttendees,
                'ambiguous' => $ambiguous,
                'message' => '部分参会人需要确认，请向用户询问具体是哪一位',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 调用企微 API 预定会议室
        $attendeeUserids = collect($resolvedAttendees)->pluck('userid')->toArray();

        $apiResult = $wecomMeetingRoomClient->bookRoom(
            meetingRoomId: $data['meetingroom_id'],
            startTime: $startTimestamp,
            endTime: $endTimestamp,
            booker: $userId,
            subject: $data['subject'] ?? null,
            attendees: $attendeeUserids,
        );

        $result = [
            'status' => 'success',
            'booking_id' => $apiResult['booking_id'] ?? '',
            'schedule_id' => $apiResult['schedule_id'] ?? '',
            'meetingroom_id' => $data['meetingroom_id'],
            'start_time' => $startCarbon->toIso8601String(),
            'end_time' => $endCarbon->toIso8601String(),
            'subject' => $data['subject'] ?? '',
            'attendees' => $resolvedAttendees,
            'message' => '会议室预定成功',
        ];

        Log::debug('BookMeetingRoomTool::handle 预定成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

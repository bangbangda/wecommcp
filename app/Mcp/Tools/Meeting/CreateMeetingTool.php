<?php

namespace App\Mcp\Tools\Meeting;

use App\Models\RecentMeeting;
use App\Services\ContactsService;
use App\Wecom\WecomMeetingClient;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_meeting')]
#[Description('创建企业微信在线视频会议（有会议链接，参会人通过链接加入线上会议）。
当用户说"开个视频会""在线会议""线上开会""约个视频会议"时使用此工具。
参会人传入中文姓名即可，系统自动匹配为企微用户（支持同音字模糊匹配），
匹配到多个候选时会返回 need_clarification 供确认。
此工具仅用于创建在线视频会议。如果用户想创建日程安排（如面试、线下会议、备忘提醒），
应使用 create_schedule 而非此工具。修改或取消已有会议请使用其他工具。')]
class CreateMeetingTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string('会议主题')->required(),
            'start_time' => $schema->string('开始时间，ISO 8601 格式，例如 2026-02-25T14:00:00')->required(),
            'duration_minutes' => $schema->integer('会议时长（分钟），默认 60'),
            'invitees' => $schema->array('其他参会人的中文姓名列表，不需要包含当前用户（会自动作为管理员加入）')->items($schema->string()),
        ];
    }

    /**
     * 处理创建会议请求
     * 解析参会人姓名 → 校验时间 → 调用企微 API → 写入本地记录
     *
     * @param  Request  $request  MCP 请求（AI 提供的业务参数）
     * @param  ContactsService  $contactsService  通讯录服务
     * @param  WecomMeetingClient  $wecomMeetingClient  企微会议服务
     * @param  string  $userId  当前用户 userid（从回调消息 from.userid 透传）
     * @return Response MCP 响应
     */
    public function handle(Request $request, ContactsService $contactsService, WecomMeetingClient $wecomMeetingClient, string $userId): Response
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'start_time' => 'required|string',
            'duration_minutes' => 'integer|min:15|max:480',
            'invitees' => 'array',
            'invitees.*' => 'string',
        ]);

        Log::debug('CreateMeetingTool::handle 收到请求', $data);

        $duration = $data['duration_minutes'] ?? 60;
        // 过滤掉当前用户（admin 已自动加入参会人，无需重复匹配）
        $inviteeNames = collect($data['invitees'] ?? [])->reject(fn ($name) => $name === $userId)->values()->all();

        // 解析参会人姓名 → 联系人信息
        $resolvedInvitees = [];
        $ambiguous = [];

        foreach ($inviteeNames as $name) {
            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $contact = $matches->first();
                $resolvedInvitees[] = [
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
            Log::debug('CreateMeetingTool::handle 参会人歧义', ['resolved' => $resolvedInvitees, 'ambiguous' => $ambiguous]);

            return Response::text(json_encode([
                'status' => 'need_clarification',
                'resolved' => $resolvedInvitees,
                'ambiguous' => $ambiguous,
                'message' => '部分参会人需要确认，请向用户询问具体是哪一位',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 解析并校验开始时间（企微 API 要求 Unix 时间戳且必须大于当前时间）
        // 使用 Asia/Shanghai 时区解析，避免 UTC 偏移导致时间错误
        try {
            $startCarbon = Carbon::parse($data['start_time'], 'Asia/Shanghai');
            $startTimestamp = $startCarbon->getTimestamp();
        } catch (\Exception $e) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => "无法解析开始时间「{$data['start_time']}」，请使用 ISO 8601 格式，如 2026-02-26T15:00:00",
            ], JSON_UNESCAPED_UNICODE));
        }

        if ($startCarbon->isPast()) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '会议开始时间必须大于当前时间，请重新指定',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 调用企微 API 创建会议
        $durationSeconds = $duration * 60;
        $userids = collect($resolvedInvitees)->pluck('userid')->toArray();
        $adminUserid = $userId;

        $apiRequest = [
            'admin_userid' => $adminUserid,
            'title' => $data['title'],
            'meeting_start' => (string) $startTimestamp,
            'meeting_duration' => $durationSeconds,
            'invitees' => ['userid' => array_merge([$adminUserid], $userids)],
        ];

        $apiResult = $wecomMeetingClient->createMeeting(
            adminUserid: $adminUserid,
            title: $data['title'],
            startTime: $startTimestamp,
            duration: $durationSeconds,
            inviteeUserids: $userids,
        );

        // 写入本地记录
        $meeting = RecentMeeting::create([
            'title' => $data['title'],
            'start_time' => $data['start_time'],
            'duration_minutes' => $duration,
            'invitees' => $resolvedInvitees,
            'creator_userid' => $userId,
            'meetingid' => $apiResult['meetingid'] ?? 'local_'.uniqid(),
            'api_request' => $apiRequest,
            'api_response' => $apiResult,
        ]);

        $result = [
            'status' => 'success',
            'meeting_id' => $meeting->id,
            'meetingid' => $meeting->meetingid,
            'title' => $meeting->title,
            'start_time' => $meeting->start_time->toIso8601String(),
            'duration_minutes' => $meeting->duration_minutes,
            'invitees' => $resolvedInvitees,
            'message' => "会议「{$meeting->title}」创建成功",
        ];

        Log::debug('CreateMeetingTool::handle 创建成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

<?php

namespace App\Mcp\Tools\Meeting;

use App\Mcp\Tools\Meeting\Concerns\MeetingLookupTrait;
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

#[Name('update_meeting')]
#[Description('修改企业微信会议的标题、时间、时长或参会人。当用户说"把会议改到明天""修改会议时间""加个人到会议里""把会议标题改成XX"时使用此工具。优先通过 meetingid 精确定位，若用户未提供明确会议标识（如说"改一下刚才那个会"），应先使用 query_meetings 查询会议列表获取 meetingid。查找参数（meetingid/title_keyword/lookup_start_time）用于定位会议，更新参数（new_title/new_start_time/new_duration_minutes/new_invitees）用于修改内容，至少需要提供一个更新参数。此工具仅用于修改已有会议，创建新会议请使用 create_meeting，取消会议请使用 cancel_meeting。')]
class UpdateMeetingTool extends Tool
{
    use MeetingLookupTrait;

    /**
     * 定义 Tool 参数 schema
     * 查找参数使用 title_keyword/lookup_start_time 避免与更新参数混淆
     *
     * @param  JsonSchema  $schema  JSON Schema 构建器
     * @return array schema 定义
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'meetingid' => $schema->string('会议 ID，从 query_meetings 结果获取，提供时直接精确定位'),
            'title_keyword' => $schema->string('会议主题关键词，用于查找目标会议，meetingid 未提供时必填'),
            'lookup_start_time' => $schema->string('目标会议的开始时间，用于缩小查找范围，可选'),
            'new_title' => $schema->string('新的会议主题'),
            'new_start_time' => $schema->string('新的开始时间，ISO 8601 格式，例如 2026-02-27T15:00:00'),
            'new_duration_minutes' => $schema->integer('新的会议时长（分钟）'),
            'new_invitees' => $schema->array('新的参会人中文姓名列表（完整替换，不是追加），不需要包含当前用户')->items($schema->string()),
        ];
    }

    /**
     * 处理修改会议请求
     * 定位会议 → 校验更新字段 → 时间时长自动补全 → 解析参会人 → 调用 API → 更新本地记录
     *
     * @param  Request  $request  MCP 请求
     * @param  ContactsService  $contactsService  通讯录服务
     * @param  WecomMeetingClient  $wecomMeetingClient  企微会议服务
     * @param  string|null  $userId  当前用户 ID
     * @return Response MCP 响应
     */
    public function handle(Request $request, ContactsService $contactsService, WecomMeetingClient $wecomMeetingClient, ?string $userId = null): Response
    {
        $data = $request->validate([
            'meetingid' => 'nullable|string',
            'title_keyword' => 'nullable|string|max:255',
            'lookup_start_time' => 'nullable|string',
            'new_title' => 'nullable|string|max:255',
            'new_start_time' => 'nullable|string',
            'new_duration_minutes' => 'nullable|integer|min:15|max:480',
            'new_invitees' => 'nullable|array',
            'new_invitees.*' => 'string',
        ]);

        Log::debug('UpdateMeetingTool::handle 收到请求', $data);

        // 校验至少有一个更新字段
        $hasUpdate = ! empty($data['new_title'])
            || ! empty($data['new_start_time'])
            || ! empty($data['new_duration_minutes'])
            || array_key_exists('new_invitees', $data);

        if (! $hasUpdate) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '请至少提供一个更新参数（new_title、new_start_time、new_duration_minutes、new_invitees）',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 定位会议
        if (! empty($data['meetingid'])) {
            $meeting = RecentMeeting::where('meetingid', $data['meetingid'])->first();
            if (! $meeting) {
                return Response::text(json_encode([
                    'status' => 'not_found',
                    'message' => "未找到 meetingid={$data['meetingid']} 的会议",
                ], JSON_UNESCAPED_UNICODE));
            }
        } else {
            if (empty($data['title_keyword'])) {
                return Response::text(json_encode([
                    'status' => 'error',
                    'message' => '请提供 meetingid 或 title_keyword 参数',
                ], JSON_UNESCAPED_UNICODE));
            }

            $meetings = $this->lookupMeetings($data['title_keyword'], $data['lookup_start_time'] ?? null, $userId);
            $errorResponse = $this->resolveOrRespond($meetings);

            if ($errorResponse) {
                return $errorResponse;
            }

            $meeting = $meetings->first();
        }

        // 构建 API 更新参数
        $apiParams = [];
        $localUpdates = [];

        // 标题更新
        if (! empty($data['new_title'])) {
            $apiParams['title'] = $data['new_title'];
            $localUpdates['title'] = $data['new_title'];
        }

        // 时间和时长更新（企微 API 要求 meeting_start 和 meeting_duration 同时提供）
        $newStartTime = $data['new_start_time'] ?? null;
        $newDuration = $data['new_duration_minutes'] ?? null;

        if ($newStartTime || $newDuration) {
            // 解析新开始时间，未提供则用原会议时间
            if ($newStartTime) {
                try {
                    $startCarbon = Carbon::parse($newStartTime, 'Asia/Shanghai');
                } catch (\Exception $e) {
                    return Response::text(json_encode([
                        'status' => 'error',
                        'message' => "无法解析开始时间「{$newStartTime}」，请使用 ISO 8601 格式，如 2026-02-27T15:00:00",
                    ], JSON_UNESCAPED_UNICODE));
                }

                if ($startCarbon->isPast()) {
                    return Response::text(json_encode([
                        'status' => 'error',
                        'message' => '会议开始时间必须大于当前时间，请重新指定',
                    ], JSON_UNESCAPED_UNICODE));
                }

                $apiParams['meeting_start'] = (string) $startCarbon->getTimestamp();
                $localUpdates['start_time'] = $startCarbon->toIso8601String();
            } else {
                // 未修改时间，沿用原时间
                $apiParams['meeting_start'] = (string) $meeting->start_time->getTimestamp();
            }

            // 解析时长，未提供则用原时长
            $durationMinutes = $newDuration ?? $meeting->duration_minutes;
            $apiParams['meeting_duration'] = $durationMinutes * 60;
            if ($newDuration) {
                $localUpdates['duration_minutes'] = $newDuration;
            }
        }

        // 参会人更新
        $resolvedInvitees = null;
        if (array_key_exists('new_invitees', $data) && is_array($data['new_invitees'])) {
            $inviteeNames = collect($data['new_invitees'])
                ->reject(fn ($name) => $name === $userId)
                ->values()
                ->all();

            $resolved = [];
            $ambiguous = [];

            foreach ($inviteeNames as $name) {
                $matches = $contactsService->searchByName($name);

                if ($matches->count() === 1) {
                    $contact = $matches->first();
                    $resolved[] = [
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

            if (! empty($ambiguous)) {
                Log::debug('UpdateMeetingTool::handle 参会人歧义', ['resolved' => $resolved, 'ambiguous' => $ambiguous]);

                return Response::text(json_encode([
                    'status' => 'need_clarification',
                    'resolved' => $resolved,
                    'ambiguous' => $ambiguous,
                    'message' => '部分参会人需要确认，请向用户询问具体是哪一位',
                ], JSON_UNESCAPED_UNICODE));
            }

            $resolvedInvitees = $resolved;
            $userids = collect($resolved)->pluck('userid')->toArray();
            $adminUserid = $userId ?? $meeting->creator_userid;
            $apiParams['invitees'] = [
                'userid' => array_merge([$adminUserid], $userids),
            ];
            $localUpdates['invitees'] = $resolved;
        }

        // 调用企微 API 修改会议
        $apiResult = $wecomMeetingClient->updateMeeting($meeting->meetingid, $apiParams);

        // 更新本地记录
        if (! empty($localUpdates)) {
            $meeting->update(array_merge($localUpdates, [
                'api_request' => $apiParams,
                'api_response' => $apiResult,
            ]));
        }

        // 构建变更摘要
        $changes = [];
        if (! empty($data['new_title'])) {
            $changes[] = "标题改为「{$data['new_title']}」";
        }
        if ($newStartTime) {
            $changes[] = "时间改为 {$localUpdates['start_time']}";
        }
        if ($newDuration) {
            $changes[] = "时长改为 {$newDuration} 分钟";
        }
        if ($resolvedInvitees !== null) {
            $names = collect($resolvedInvitees)->pluck('name')->implode('、');
            $changes[] = "参会人更新为：{$names}";
        }

        $result = [
            'status' => 'success',
            'meetingid' => $meeting->meetingid,
            'title' => $meeting->fresh()->title,
            'changes' => $changes,
            'message' => '会议修改成功：'.implode('，', $changes),
        ];

        Log::debug('UpdateMeetingTool::handle 修改成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

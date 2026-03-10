<?php

namespace App\Mcp\Tools\Schedule;

use App\Models\RecentSchedule;
use App\Services\ContactsService;
use App\Services\ModuleConfigService;
use App\Wecom\WecomScheduleClient;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_schedule')]
#[Description('创建企业微信日程。用于安排面试、预约线下会议、项目计划、备忘提醒等场景。
当用户说"帮我安排一下""记个提醒""建个日程""面试定在周三下午""明天下午3点和张三做需求评审"
"后天上午10点去拜访客户""帮我记一下周五要交报告"时使用此工具。
参与者传入中文姓名即可，系统自动匹配为企微用户（支持同音字模糊匹配），
匹配到多个候选时会返回 need_clarification 供确认。
不填 cal_id 时系统自动处理：无日历则引导创建，仅一个日历则自动使用，多个日历则返回列表供用户选择（含推荐）。
用户选择后，用对应的 cal_id 再次调用此工具即可。
此工具仅用于创建新日程，取消日程请使用 cancel_schedule。')]
class CreateScheduleTool extends Tool
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
            'summary' => $schema->string('日程标题')->required(),
            'start_time' => $schema->string('开始时间，ISO 8601 格式，例如 2026-03-05T14:00:00')->required(),
            'end_time' => $schema->string('结束时间，ISO 8601 格式，例如 2026-03-05T15:00:00')->required(),
            'attendees' => $schema->array('参与者的中文姓名列表，不需要包含当前用户（会自动作为组织者加入）')->items($schema->string()),
            'location' => $schema->string('日程地点'),
            'description' => $schema->string('日程描述'),
            'cal_id' => $schema->string('日历 ID，不填时使用应用默认日历'),
            'is_remind' => $schema->boolean('是否提醒，默认 true'),
            'is_whole_day' => $schema->boolean('是否全天日程，默认 false'),
        ];
    }

    /**
     * 处理创建日程请求
     * 解析参与者姓名 → 校验时间 → 解析 cal_id（多日历时返回选择列表） → 调用企微 API → 写入本地记录
     *
     * @param  Request  $request  MCP 请求（AI 提供的业务参数）
     * @param  ContactsService  $contactsService  通讯录服务
     * @param  WecomScheduleClient  $wecomScheduleClient  企微日程服务
     * @param  ModuleConfigService  $moduleConfigService  模块配置服务
     * @param  string  $userId  当前用户 userid
     * @param  array  $moduleConfig  模块配置（自动注入，含 cal_id 等）
     * @return Response MCP 响应
     */
    public function handle(Request $request, ContactsService $contactsService, WecomScheduleClient $wecomScheduleClient, ModuleConfigService $moduleConfigService, string $userId, array $moduleConfig = []): Response
    {
        $data = $request->validate([
            'summary' => 'required|string|max:255',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'attendees' => 'array',
            'attendees.*' => 'string',
            'location' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:512',
            'cal_id' => 'nullable|string',
            'is_remind' => 'nullable|boolean',
            'is_whole_day' => 'nullable|boolean',
        ]);

        Log::debug('CreateScheduleTool::handle 收到请求', $data);

        // 过滤掉当前用户（organizer 已自动加入，无需重复匹配）
        $attendeeNames = collect($data['attendees'] ?? [])->reject(fn ($name) => $name === $userId)->values()->all();

        // 解析参与者姓名 → 联系人信息
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
            Log::debug('CreateScheduleTool::handle 参与者歧义', ['resolved' => $resolvedAttendees, 'ambiguous' => $ambiguous]);

            return Response::text(json_encode([
                'status' => 'need_clarification',
                'resolved' => $resolvedAttendees,
                'ambiguous' => $ambiguous,
                'message' => '部分参与者需要确认，请向用户询问具体是哪一位',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 解析并校验开始时间
        try {
            $startCarbon = Carbon::parse($data['start_time'], 'Asia/Shanghai');
            $startTimestamp = $startCarbon->getTimestamp();
        } catch (\Exception $e) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => "无法解析开始时间「{$data['start_time']}」，请使用 ISO 8601 格式，如 2026-03-05T15:00:00",
            ], JSON_UNESCAPED_UNICODE));
        }

        // 解析并校验结束时间
        try {
            $endCarbon = Carbon::parse($data['end_time'], 'Asia/Shanghai');
            $endTimestamp = $endCarbon->getTimestamp();
        } catch (\Exception $e) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => "无法解析结束时间「{$data['end_time']}」，请使用 ISO 8601 格式，如 2026-03-05T16:00:00",
            ], JSON_UNESCAPED_UNICODE));
        }

        if ($endTimestamp <= $startTimestamp) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '结束时间必须大于开始时间，请重新指定',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 解析 cal_id：优先用户明确传入 → 模块配置（单个自动用 / 多个返回选择列表）
        $calId = $this->resolveCalId($data, $moduleConfigService, $wecomScheduleClient, $userId);

        if ($calId instanceof Response) {
            return $calId;
        }

        // 调用企微 API 创建日程
        $userids = collect($resolvedAttendees)->pluck('userid')->toArray();
        $isRemind = $data['is_remind'] ?? true;
        $isWholeDay = $data['is_whole_day'] ?? false;

        // 构建提醒参数（企微 API 格式）
        $reminders = [];
        if ($isRemind) {
            $reminders = ['is_remind' => 1, 'remind_before_event_secs' => 900];
        }

        $apiResult = $wecomScheduleClient->createSchedule(
            summary: $data['summary'],
            startTime: $startTimestamp,
            endTime: $endTimestamp,
            attendeeUserids: $userids,
            calId: $calId,
            location: $data['location'] ?? '',
            description: $data['description'] ?? '',
            reminders: $reminders,
            isWholeDay: $isWholeDay,
        );

        // 写入本地记录
        $schedule = RecentSchedule::create([
            'summary' => $data['summary'],
            'description' => $data['description'] ?? null,
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'attendees' => $resolvedAttendees,
            'creator_userid' => $userId,
            'schedule_id' => $apiResult['schedule_id'] ?? 'local_'.uniqid(),
            'cal_id' => $calId ?: null,
            'location' => $data['location'] ?? null,
            'api_request' => ['schedule' => array_filter([
                'summary' => $data['summary'],
                'start_time' => $startTimestamp,
                'end_time' => $endTimestamp,
                'attendees' => $userids,
                'cal_id' => $calId ?: null,
                'location' => $data['location'] ?? null,
            ])],
            'api_response' => $apiResult,
        ]);

        $result = [
            'status' => 'success',
            'schedule_id' => $schedule->schedule_id,
            'summary' => $schedule->summary,
            'start_time' => $schedule->start_time->toIso8601String(),
            'end_time' => $schedule->end_time->toIso8601String(),
            'attendees' => $resolvedAttendees,
            'location' => $schedule->location,
            'message' => "日程「{$schedule->summary}」创建成功",
        ];

        Log::debug('CreateScheduleTool::handle 创建成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 解析日历 ID
     * 优先级：用户明确传入 cal_id → 模块配置中仅一个日历自动使用 → 多个日历返回选择列表 → 无日历引导创建
     *
     * @param  array  $data  已校验的请求参数
     * @param  ModuleConfigService  $moduleConfigService  模块配置服务
     * @param  WecomScheduleClient  $wecomScheduleClient  企微日程服务
     * @param  string  $userId  当前用户 userid
     * @return string|Response 成功返回 cal_id 字符串，需要用户交互时返回 Response
     */
    private function resolveCalId(array $data, ModuleConfigService $moduleConfigService, WecomScheduleClient $wecomScheduleClient, string $userId): string|Response
    {
        // 用户明确传入 cal_id，直接使用
        if (! empty($data['cal_id'])) {
            return $data['cal_id'];
        }

        // 从模块配置读取日历 ID 列表
        $calIdList = $moduleConfigService->getList($userId, 'schedule', 'cal_id');

        // 无日历，引导创建
        if (empty($calIdList)) {
            Log::debug('CreateScheduleTool::resolveCalId 无日历，引导用户创建');

            return Response::text(json_encode([
                'status' => 'need_calendar',
                'message' => '尚未创建日历，请先引导用户通过 create_calendar 创建一个日历',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 仅一个日历，自动使用
        if (count($calIdList) === 1) {
            return $calIdList[0];
        }

        // 多个日历，查询详情后返回选择列表
        Log::debug('CreateScheduleTool::resolveCalId 检测到多个日历，查询详情', ['cal_ids' => $calIdList]);

        $apiResult = $wecomScheduleClient->getCalendars($calIdList);
        $calendarList = $apiResult['calendar_list'] ?? [];

        // API 没有返回有效日历（可能已被删除），引导创建
        if (empty($calendarList)) {
            return Response::text(json_encode([
                'status' => 'need_calendar',
                'message' => '已保存的日历可能已被删除，请引导用户通过 create_calendar 创建一个新日历',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 如果 API 只返回了一个有效日历，直接使用
        if (count($calendarList) === 1) {
            return $calendarList[0]['cal_id'];
        }

        // 构建带推荐的选择列表
        $hasAttendees = ! empty($data['attendees']);
        $calendars = collect($calendarList)->map(function (array $cal, int $index) use ($hasAttendees) {
            $type = $this->resolveCalendarType($cal);
            $recommendation = $this->buildRecommendation($type, $hasAttendees);

            return [
                'index' => $index + 1,
                'cal_id' => $cal['cal_id'] ?? '',
                'summary' => $cal['summary'] ?? '',
                'calendar_type' => $type,
                'description' => $cal['description'] ?? '',
                'recommendation' => $recommendation,
            ];
        })->values()->toArray();

        return Response::text(json_encode([
            'status' => 'need_selection',
            'calendars' => $calendars,
            'message' => '用户有多个日历，请展示以下列表让用户选择要使用的日历，并根据推荐信息给出建议。用户选择后，用对应的 cal_id 再次调用 create_schedule',
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 根据 API 返回的字段推断日历类型
     *
     * @param  array  $cal  日历 API 数据
     * @return string 日历类型标签
     */
    private function resolveCalendarType(array $cal): string
    {
        if (! empty($cal['is_corp_calendar'])) {
            return '全员日历';
        }

        if (! empty($cal['is_public'])) {
            return '公共日历';
        }

        return '个人日历';
    }

    /**
     * 根据日历类型和业务场景生成推荐理由
     *
     * @param  string  $type  日历类型标签
     * @param  bool  $hasAttendees  是否有其他参与者
     * @return string 推荐理由，无推荐时为空字符串
     */
    private function buildRecommendation(string $type, bool $hasAttendees): string
    {
        if ($hasAttendees) {
            return match ($type) {
                '公共日历' => '推荐：有其他参与者时，公共日历方便跨部门共享',
                '全员日历' => '适合全公司通知类日程',
                '个人日历' => '个人日历仅自己可见，其他参与者可能看不到此日程',
                default => '',
            };
        }

        return match ($type) {
            '个人日历' => '推荐：个人备忘类日程适合放在个人日历',
            '公共日历' => '适合需要让其他人看到的日程',
            '全员日历' => '适合全公司通知类日程',
            default => '',
        };
    }
}

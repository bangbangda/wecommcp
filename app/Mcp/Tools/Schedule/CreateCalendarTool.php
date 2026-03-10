<?php

namespace App\Mcp\Tools\Schedule;

use App\Services\ContactsService;
use App\Services\ModuleConfigService;
use App\Wecom\WecomScheduleClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_calendar')]
#[Description('创建企业微信日历。日历是日程的容器，一个日历下可以创建多个日程。
当用户说"建个工作日历""给项目组建个日历""创建一个日历用来记录面试安排"时使用此工具。
创建成功后返回 cal_id，可在 create_schedule 中通过 cal_id 指定日程归属的日历。
如果用户只是想添加一个日程（如"帮我安排个会""记个提醒"），不需要先创建日历，
直接使用 create_schedule 即可（系统会使用默认日历）。
重要：calendar_type 为必填参数，如果用户未明确说明日历类型，必须先向用户询问。
向用户解释三种类型的区别：
- private（个人日历）：仅自己可见，适合个人备忘，不需要额外参数
- public（公共日历）：指定范围的人可见可订阅，适合跨部门协作，推荐需要和其他人共享时使用。需要指定可见范围（visible_members 和/或 visible_department_ids）
- corp（全员日历）：全公司可见，适合公司级活动通知（每企业最多 20 个）。需要指定可见范围，且不支持自定义颜色
当用户需要和其他部门的人一起使用时，推荐使用公共日历。
公共日历和全员日历必须指定可见范围（visible_members 传中文姓名，visible_department_ids 传部门 ID），否则工具会返回提示。
shares 参数用于指定通知范围（会收到日历变更通知的成员），传入中文姓名即可。')]
class CreateCalendarTool extends Tool
{
    /** @var array<string> 预设高饱和色板，视觉区分度高，适合日历标识 */
    private const COLORS = [
        '#F44336', // 红
        '#E91E63', // 粉
        '#9C27B0', // 紫
        '#673AB7', // 深紫
        '#3F51B5', // 靛蓝
        '#2196F3', // 蓝
        '#00BCD4', // 青
        '#009688', // 蓝绿
        '#4CAF50', // 绿
        '#FF9800', // 橙
        '#FF5722', // 深橙
        '#795548', // 棕
    ];

    /**
     * 随机选取一个高饱和色
     *
     * @return string RGB 颜色编码，如 "#F44336"
     */
    private static function randomColor(): string
    {
        return self::COLORS[array_rand(self::COLORS)];
    }

    /**
     * 定义 Tool 参数 schema
     *
     * @param  JsonSchema  $schema  JSON Schema 构建器
     * @return array schema 定义
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string('日历标题，1~128 字符')->required(),
            'calendar_type' => $schema->string('日历类型，必填。可选值：private（个人日历）、public（公共日历）、corp（全员日历）。必须明确询问用户后填写')->required(),
            'visible_members' => $schema->array('公开范围 - 成员姓名列表（中文），仅 public/corp 类型需要。系统自动匹配为 userid')->items($schema->string()),
            'visible_department_ids' => $schema->array('公开范围 - 部门 ID 列表，仅 public/corp 类型需要。部门 ID 可通过通讯录获取')->items($schema->number()),
            'shares' => $schema->array('通知范围成员的中文姓名列表，这些人会收到日历变更通知。系统自动匹配为 userid，不需要包含当前用户（会自动加入）')->items($schema->string()),
            'color' => $schema->string('颜色 RGB 编码，如 "#0000FF"，默认 "#3F51B5"。注意：全员日历（corp）不支持自定义颜色'),
            'description' => $schema->string('日历描述，0~512 字符'),
        ];
    }

    /**
     * 处理创建日历请求
     * 校验参数 → 解析成员姓名 → 校验公开范围 → 设置管理员 → 调用企微 API → 自动保存 cal_id
     *
     * @param  Request  $request  MCP 请求（AI 提供的业务参数）
     * @param  WecomScheduleClient  $wecomScheduleClient  企微日程服务
     * @param  ModuleConfigService  $moduleConfigService  模块配置服务
     * @param  ContactsService  $contactsService  通讯录服务
     * @param  string  $userId  当前用户 userid
     * @param  array  $moduleConfig  模块配置（自动注入）
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomScheduleClient $wecomScheduleClient, ModuleConfigService $moduleConfigService, ContactsService $contactsService, string $userId, array $moduleConfig = []): Response
    {
        $data = $request->validate([
            'summary' => 'required|string|max:128',
            'calendar_type' => 'required|string|in:private,public,corp',
            'visible_members' => 'nullable|array',
            'visible_members.*' => 'string',
            'visible_department_ids' => 'nullable|array',
            'visible_department_ids.*' => 'integer',
            'shares' => 'nullable|array',
            'shares.*' => 'string',
            'color' => 'nullable|string|max:10',
            'description' => 'nullable|string|max:512',
        ]);

        Log::debug('CreateCalendarTool::handle 收到请求', $data);

        $calendarType = $data['calendar_type'];
        $isPublic = in_array($calendarType, ['public', 'corp']);
        $isCorpCalendar = $calendarType === 'corp';

        // 公共日历和全员日历必须指定可见范围
        $visibleMemberNames = $data['visible_members'] ?? [];
        $visibleDepartmentIds = $data['visible_department_ids'] ?? [];

        if ($isPublic && empty($visibleMemberNames) && empty($visibleDepartmentIds)) {
            $typeLabel = $isCorpCalendar ? '全员日历' : '公共日历';

            return Response::text(json_encode([
                'status' => 'need_public_range',
                'message' => "{$typeLabel}必须指定可见范围。请询问用户：哪些人或部门需要看到这个日历？可以指定具体成员姓名（visible_members）和/或部门 ID（visible_department_ids）",
            ], JSON_UNESCAPED_UNICODE));
        }

        // 解析可见范围成员姓名 → userid
        $publicRange = [];
        $ambiguous = [];

        if (! empty($visibleMemberNames)) {
            $resolvedUserids = [];
            foreach ($visibleMemberNames as $name) {
                $matches = $contactsService->searchByName($name);

                if ($matches->count() === 1) {
                    $resolvedUserids[] = $matches->first()->userid;
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
                return Response::text(json_encode([
                    'status' => 'need_clarification',
                    'ambiguous' => $ambiguous,
                    'message' => '可见范围中部分成员需要确认，请向用户询问具体是哪一位',
                ], JSON_UNESCAPED_UNICODE));
            }

            $publicRange['userids'] = $resolvedUserids;
        }

        if (! empty($visibleDepartmentIds)) {
            $publicRange['partyids'] = $visibleDepartmentIds;
        }

        // 解析通知范围（shares）成员姓名 → userid
        $shares = [['userid' => $userId, 'permission' => 1]];
        $shareNames = $data['shares'] ?? [];

        foreach ($shareNames as $name) {
            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $shares[] = ['userid' => $matches->first()->userid, 'permission' => 1];
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
            return Response::text(json_encode([
                'status' => 'need_clarification',
                'ambiguous' => $ambiguous,
                'message' => '通知范围中部分成员需要确认，请向用户询问具体是哪一位',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 全员日历不支持自定义颜色；未指定颜色时随机选一个高饱和色
        $color = $isCorpCalendar ? '#3F51B5' : ($data['color'] ?? self::randomColor());
        $description = $data['description'] ?? '';
        $admins = [$userId];

        $apiResult = $wecomScheduleClient->createCalendar(
            summary: $data['summary'],
            color: $color,
            description: $description,
            shares: $shares,
            admins: $admins,
            isPublic: $isPublic,
            isCorpCalendar: $isCorpCalendar,
            publicRange: $publicRange,
        );

        // 创建成功后自动追加 cal_id 到模块配置（支持多日历）
        $calId = $apiResult['cal_id'] ?? '';
        if ($calId !== '') {
            $moduleConfigService->append($userId, 'schedule', 'cal_id', $calId);
            Log::debug('CreateCalendarTool::handle 已自动保存 cal_id 到模块配置', ['cal_id' => $calId]);
        }

        $typeLabel = match ($calendarType) {
            'public' => '公共日历',
            'corp' => '全员日历',
            default => '个人日历',
        };

        // 构建创建成功结果
        $result = [
            'status' => 'success',
            'cal_id' => $calId,
            'summary' => $data['summary'],
            'calendar_type' => $calendarType,
            'message' => "{$typeLabel}「{$data['summary']}」创建成功",
        ];

        // 返回 API 中失败的 shares 信息（如有）
        $failResult = $apiResult['fail_result'] ?? [];
        if (! empty($failResult['shares'])) {
            $result['failed_shares'] = $failResult['shares'];
        }

        Log::debug('CreateCalendarTool::handle 创建成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

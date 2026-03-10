<?php

namespace App\Mcp\Tools\Schedule;

use App\Services\ModuleConfigService;
use App\Wecom\WecomScheduleClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('query_calendars')]
#[Description('查询用户的日历列表。返回用户已创建的所有日历及其详情（标题、类型、颜色等）。
当用户说"我有哪些日历""查看日历列表""我的日历""看看我建了哪些日历"时使用此工具。
也可在 create_schedule 返回 need_calendar 后调用此工具，查看用户是否已有可用日历。
此工具仅查询日历信息，创建日历请使用 create_calendar，查询日历下的日程请使用 query_schedules。')]
class QueryCalendarsTool extends Tool
{
    /**
     * 定义 Tool 参数 schema
     *
     * @param  JsonSchema  $schema  JSON Schema 构建器
     * @return array schema 定义
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * 处理查询日历列表请求
     * 从模块配置读取 cal_id 列表 → 调用企微 API 获取详情 → 返回格式化结果
     *
     * @param  Request  $request  MCP 请求
     * @param  WecomScheduleClient  $wecomScheduleClient  企微日程服务
     * @param  ModuleConfigService  $moduleConfigService  模块配置服务
     * @param  string  $userId  当前用户 userid
     * @param  array  $moduleConfig  模块配置（自动注入）
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomScheduleClient $wecomScheduleClient, ModuleConfigService $moduleConfigService, string $userId, array $moduleConfig = []): Response
    {
        Log::debug('QueryCalendarsTool::handle 收到请求', ['userId' => $userId]);

        // 从模块配置读取用户的日历 ID 列表
        $calIdList = $moduleConfigService->getList($userId, 'schedule', 'cal_id');

        if (empty($calIdList)) {
            $result = [
                'status' => 'empty',
                'count' => 0,
                'calendars' => [],
                'message' => '暂无日历，可通过 create_calendar 创建一个新日历',
            ];

            Log::debug('QueryCalendarsTool::handle 无日历记录', $result);

            return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        // 调用企微 API 获取日历详情
        $apiResult = $wecomScheduleClient->getCalendars($calIdList);
        $calendarList = $apiResult['calendar_list'] ?? [];

        if (empty($calendarList)) {
            $result = [
                'status' => 'empty',
                'count' => 0,
                'calendars' => [],
                'message' => '暂无可用日历（已保存的日历可能已被删除），可通过 create_calendar 创建一个新日历',
            ];

            Log::debug('QueryCalendarsTool::handle API 返回空日历列表');

            return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        // 格式化日历列表
        $calendars = collect($calendarList)->map(fn (array $cal, int $index) => [
            'index' => $index + 1,
            'cal_id' => $cal['cal_id'] ?? '',
            'summary' => $cal['summary'] ?? '',
            'calendar_type' => $this->resolveCalendarType($cal),
            'color' => $cal['color'] ?? '',
            'description' => $cal['description'] ?? '',
        ])->values()->toArray();

        $result = [
            'status' => 'success',
            'count' => count($calendars),
            'calendars' => $calendars,
            'message' => '找到 '.count($calendars).' 个日历',
        ];

        Log::debug('QueryCalendarsTool::handle 查询成功', ['count' => count($calendars)]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
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
}

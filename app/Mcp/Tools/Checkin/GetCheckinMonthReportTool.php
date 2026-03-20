<?php

namespace App\Mcp\Tools\Checkin;

use App\Services\CheckinService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_checkin_month_report')]
#[Description('查询员工的考勤月报或周报。月报返回应出勤天数、实际天数、异常天数/次数、各类假勤统计、加班时长。周报基于日报聚合，维度相同。典型场景："这个月的考勤汇总""李四2月出勤情况""上周大家的考勤怎么样""本月谁请假最多"。需要先通过 search_contacts 确认人员信息。若不指定人员则查询全员。')]
class GetCheckinMonthReportTool extends Tool
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
            'names' => $schema->array('要查询的员工姓名列表，不传则查询全员')->items($schema->string()),
            'period_type' => $schema->string('统计周期类型：month（月报，默认）或 week（周报）'),
            'month' => $schema->string('查询月份，格式 Y-m，默认当月。period_type=month 时使用'),
            'week_start' => $schema->string('周起始日期，格式 Y-m-d，默认本周一。period_type=week 时使用'),
        ];
    }

    /**
     * 处理考勤月报/周报查询请求
     *
     * @param  Request  $request  MCP 请求
     * @param  CheckinService  $checkinService  考勤服务
     * @return Response MCP 响应
     */
    public function handle(Request $request, CheckinService $checkinService): Response
    {
        $data = $request->validate([
            'names' => 'nullable|array',
            'names.*' => 'string',
            'period_type' => 'nullable|string|in:month,week',
            'month' => 'nullable|date_format:Y-m',
            'week_start' => 'nullable|date_format:Y-m-d',
        ]);

        Log::debug('GetCheckinMonthReportTool::handle 收到请求', $data);

        $periodType = $data['period_type'] ?? 'month';

        // 解析用户
        $userIds = [];
        if (! empty($data['names'])) {
            $resolveResult = $checkinService->resolveUserIds($data['names']);

            if (! empty($resolveResult['ambiguous'])) {
                return Response::text(json_encode([
                    'status' => 'need_clarification',
                    'message' => '部分姓名匹配到多个联系人，请确认',
                    'ambiguous' => $resolveResult['ambiguous'],
                ], JSON_UNESCAPED_UNICODE));
            }

            if (! empty($resolveResult['not_found'])) {
                return Response::text(json_encode([
                    'status' => 'error',
                    'message' => '未找到以下人员：'.implode('、', $resolveResult['not_found']),
                ], JSON_UNESCAPED_UNICODE));
            }

            $userIds = array_column($resolveResult['resolved'], 'userid');
        } else {
            $userIds = $checkinService->getAllUserIds();
        }

        if (empty($userIds)) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '未找到可查询的员工',
            ], JSON_UNESCAPED_UNICODE));
        }

        try {
            if ($periodType === 'week') {
                $weekStart = Carbon::parse($data['week_start'] ?? now('Asia/Shanghai')->startOfWeek()->toDateString(), 'Asia/Shanghai');
                $report = $checkinService->getWeekReport($userIds, $weekStart);
                $periodLabel = $weekStart->toDateString().' ~ '.$weekStart->copy()->endOfWeek()->toDateString();
            } else {
                $month = $data['month'] ?? now('Asia/Shanghai')->format('Y-m');
                $startDate = Carbon::parse($month.'-01', 'Asia/Shanghai')->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();
                $report = $checkinService->getMonthReport($userIds, $startDate, $endDate);
                $periodLabel = $month;
            }
        } catch (\Throwable $e) {
            Log::error('GetCheckinMonthReportTool 查询失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '查询考勤报表失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }

        if (empty($report)) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => "{$periodLabel} 没有考勤记录",
            ], JSON_UNESCAPED_UNICODE));
        }

        $result = [
            'status' => 'success',
            'period_type' => $periodType === 'week' ? '周报' : '月报',
            'period' => $periodLabel,
            'count' => count($report),
            'report' => $report,
        ];

        Log::debug('GetCheckinMonthReportTool::handle 查询成功', ['count' => count($report)]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

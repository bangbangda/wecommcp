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

#[Name('get_checkin_day_report')]
#[Description('查询员工某天的考勤日报。返回当日打卡次数、实际工作时长、标准工作时长、异常明细（迟到/早退/缺卡/旷工等）、请假信息、加班情况。典型场景："查下张三今天的考勤""昨天谁迟到了""小王今天打卡了吗""查一下今天大家的出勤情况"。需要先通过 search_contacts 确认人员信息。若不指定人员则查询全员。')]
class GetCheckinDayReportTool extends Tool
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
            'date' => $schema->string('查询日期，格式 Y-m-d，默认今天'),
        ];
    }

    /**
     * 处理考勤日报查询请求
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
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        Log::debug('GetCheckinDayReportTool::handle 收到请求', $data);

        $date = Carbon::parse($data['date'] ?? now('Asia/Shanghai')->toDateString(), 'Asia/Shanghai');

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
            $report = $checkinService->getDayReport($userIds, $date);
        } catch (\Throwable $e) {
            Log::error('GetCheckinDayReportTool 查询失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '查询考勤日报失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }

        if (empty($report)) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => "{$date->toDateString()} 没有考勤记录",
            ], JSON_UNESCAPED_UNICODE));
        }

        $result = [
            'status' => 'success',
            'date' => $date->toDateString(),
            'count' => count($report),
            'report' => $report,
        ];

        Log::debug('GetCheckinDayReportTool::handle 查询成功', ['count' => count($report)]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

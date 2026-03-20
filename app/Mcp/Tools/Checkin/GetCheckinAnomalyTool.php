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

#[Name('get_checkin_anomaly')]
#[Description('查询考勤异常记录。自动标记并按严重程度排序：旷工 > 缺卡 > 迟到 > 早退 > 地点异常 > 设备异常。返回按人分组的异常明细和汇总统计。典型场景："最近一周有没有考勤异常""查下谁经常迟到""这个月的旷工记录""今天有异常考勤吗"。需要先通过 search_contacts 确认人员信息。若不指定人员则查询全员。')]
class GetCheckinAnomalyTool extends Tool
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
            'start_date' => $schema->string('开始日期，格式 Y-m-d，默认7天前'),
            'end_date' => $schema->string('结束日期，格式 Y-m-d，默认今天'),
        ];
    }

    /**
     * 处理考勤异常查询请求
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
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        Log::debug('GetCheckinAnomalyTool::handle 收到请求', $data);

        $now = now('Asia/Shanghai');
        $startDate = Carbon::parse($data['start_date'] ?? $now->copy()->subDays(7)->toDateString(), 'Asia/Shanghai');
        $endDate = Carbon::parse($data['end_date'] ?? $now->toDateString(), 'Asia/Shanghai');

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
            $anomalyData = $checkinService->getAnomalies($userIds, $startDate, $endDate);
        } catch (\Throwable $e) {
            Log::error('GetCheckinAnomalyTool 查询失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '查询考勤异常失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }

        if ($anomalyData['total_anomaly_count'] === 0) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => "{$startDate->toDateString()} 至 {$endDate->toDateString()} 期间无考勤异常",
                'period' => $startDate->toDateString().' ~ '.$endDate->toDateString(),
            ], JSON_UNESCAPED_UNICODE));
        }

        $result = [
            'status' => 'success',
            'period' => $startDate->toDateString().' ~ '.$endDate->toDateString(),
            'total_anomaly_count' => $anomalyData['total_anomaly_count'],
            'summary' => $anomalyData['summary'],
            'anomalies' => $anomalyData['anomalies'],
        ];

        Log::debug('GetCheckinAnomalyTool::handle 查询成功', [
            'total' => $anomalyData['total_anomaly_count'],
        ]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

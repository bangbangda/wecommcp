<?php

namespace App\Mcp\Tools\Schedule;

use App\Wecom\WecomScheduleClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_schedule_detail')]
#[Description('获取企业微信日程的完整详情。当用户需要查看日程的参与者回执状态、提醒设置、
重复规则等详细信息时使用此工具。
当用户说"那个日程的详情""都有谁参加了""日程具体什么时候""看看这个安排的详细信息"时使用此工具。
需要提供 schedule_id，可从 query_schedules 的结果中获取。
此工具从企微 API 获取实时数据，包含最新的参与者应答状态。')]
class GetScheduleDetailTool extends Tool
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
            'schedule_id' => $schema->string('日程 ID，从 query_schedules 结果获取')->required(),
        ];
    }

    /**
     * 处理获取日程详情请求
     * 通过 schedule_id 调用企微 API 获取实时详情
     *
     * @param  Request  $request  MCP 请求
     * @param  WecomScheduleClient  $wecomScheduleClient  企微日程服务
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomScheduleClient $wecomScheduleClient): Response
    {
        $data = $request->validate([
            'schedule_id' => 'required|string',
        ]);

        Log::debug('GetScheduleDetailTool::handle 收到请求', $data);

        $apiResult = $wecomScheduleClient->getScheduleDetail([$data['schedule_id']]);

        $scheduleList = $apiResult['schedule_list'] ?? [];

        if (empty($scheduleList)) {
            return Response::text(json_encode([
                'status' => 'not_found',
                'message' => "未找到 schedule_id={$data['schedule_id']} 的日程",
            ], JSON_UNESCAPED_UNICODE));
        }

        $schedule = $scheduleList[0];

        $result = [
            'status' => 'success',
            'schedule_id' => $data['schedule_id'],
            'detail' => $schedule,
            'message' => '日程「'.($schedule['summary'] ?? '').'」的详情已获取',
        ];

        Log::debug('GetScheduleDetailTool::handle 查询成功', ['schedule_id' => $data['schedule_id']]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

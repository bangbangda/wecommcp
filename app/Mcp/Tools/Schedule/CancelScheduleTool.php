<?php

namespace App\Mcp\Tools\Schedule;

use App\Models\RecentSchedule;
use App\Wecom\WecomScheduleClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('cancel_schedule')]
#[Description('取消企业微信日程。取消后系统自动通知所有参与者。
当用户说"取消明天的安排""取消那个面试""这个日程不要了""取消需求评审"时使用此工具。
需要提供 schedule_id。如果用户描述的是日程标题或时间而非 ID，
应先调用 query_schedules 搜索匹配的日程，确认后再取消。
多个匹配结果时必须向用户确认具体是哪一个，不要自行决定。
取消操作不可撤销，请在执行前确认用户意图。')]
class CancelScheduleTool extends Tool
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
     * 处理取消日程请求
     * 通过 schedule_id 调用企微 API 取消日程 → 软删除本地记录
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

        Log::debug('CancelScheduleTool::handle 收到请求', $data);

        // 查找本地记录
        $schedule = RecentSchedule::where('schedule_id', $data['schedule_id'])->first();

        if (! $schedule) {
            return Response::text(json_encode([
                'status' => 'not_found',
                'message' => "未找到 schedule_id={$data['schedule_id']} 的日程",
            ], JSON_UNESCAPED_UNICODE));
        }

        // 调用企微 API 取消日程
        $apiRequest = ['schedule_id' => $data['schedule_id']];
        $apiResult = $wecomScheduleClient->cancelSchedule($data['schedule_id']);

        // 软删除本地记录，保存 API 请求和响应
        $schedule->update([
            'api_request' => $apiRequest,
            'api_response' => $apiResult,
        ]);
        $schedule->delete();

        $result = [
            'status' => 'success',
            'summary' => $schedule->summary,
            'schedule_id' => $schedule->schedule_id,
            'message' => "日程「{$schedule->summary}」已取消",
        ];

        Log::debug('CancelScheduleTool::handle 取消成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

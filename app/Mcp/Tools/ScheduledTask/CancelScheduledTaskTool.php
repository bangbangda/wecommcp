<?php

namespace App\Mcp\Tools\ScheduledTask;

use App\Services\ScheduledTaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('cancel_scheduled_task')]
#[Description('取消定时任务。当用户说"取消那个定时提醒""不需要每天发消息了""停掉那个任务""取消定时"时使用。
需要 task_id，如果不知道，先用 query_scheduled_tasks 查询。')]
class CancelScheduledTaskTool extends Tool
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
            'task_id' => $schema->integer('要取消的定时任务 ID')->required(),
        ];
    }

    /**
     * 处理取消定时任务请求
     *
     * @param  Request  $request  MCP 请求
     * @param  ScheduledTaskService  $service  定时任务服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, ScheduledTaskService $service, string $userId): Response
    {
        $data = $request->validate([
            'task_id' => 'required|integer',
        ]);

        Log::debug('CancelScheduledTaskTool::handle 收到请求', $data);

        $result = $service->cancel($userId, $data['task_id']);

        Log::debug('CancelScheduledTaskTool::handle 取消结果', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

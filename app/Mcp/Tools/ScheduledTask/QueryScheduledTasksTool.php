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

#[Name('query_scheduled_tasks')]
#[Description('查询用户的定时任务列表。当用户说"我有哪些定时任务""看看我的提醒""定时任务列表""我的定时消息"时使用。
可按标题关键词和启用状态筛选。返回任务列表包含 task_id、标题、调度描述、下次执行时间等信息。')]
class QueryScheduledTasksTool extends Tool
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
            'keyword' => $schema->string('按标题关键词筛选'),
            'is_active' => $schema->boolean('筛选启用状态：true=仅启用，false=仅停用，不填=全部'),
        ];
    }

    /**
     * 处理查询定时任务列表请求
     *
     * @param  Request  $request  MCP 请求
     * @param  ScheduledTaskService  $service  定时任务服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, ScheduledTaskService $service, string $userId): Response
    {
        $data = $request->validate([
            'keyword' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        Log::debug('QueryScheduledTasksTool::handle 收到请求', $data);

        $tasks = $service->getByUser(
            $userId,
            $data['keyword'] ?? null,
            $data['is_active'] ?? null,
        );

        if ($tasks->isEmpty()) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => '没有找到定时任务',
            ], JSON_UNESCAPED_UNICODE));
        }

        $taskList = $tasks->map(fn ($task) => [
            'task_id' => $task->id,
            'title' => $task->title,
            'action_type' => $task->action_type,
            'schedule_description' => $service->formatScheduleDescription($task),
            'next_run_at' => $task->next_run_at?->timezone('Asia/Shanghai')->format('Y-m-d H:i'),
            'is_active' => $task->is_active,
        ])->values()->toArray();

        $result = [
            'status' => 'success',
            'count' => count($taskList),
            'tasks' => $taskList,
        ];

        Log::debug('QueryScheduledTasksTool::handle 查询结果', ['count' => count($taskList)]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

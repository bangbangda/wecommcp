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

#[Name('create_recurring_task')]
#[Description('创建周期性定时任务。
当用户说"每天早上9点...""每个工作日...""每周五下午4点...""每月1号..."等
涉及重复执行的任务时使用此工具。
action_type：send_group_message（群消息，需要 chatid，不知道时先用 query_group_chats 查询）、
send_user_message（提醒自己）。
schedule_type：daily（每天）、weekdays（工作日，周一至周五）、weekly（每周某天）、monthly（每月某号）。')]
class CreateRecurringTaskTool extends Tool
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
            'title' => $schema->string('任务标题，简要描述任务内容')->required(),
            'action_type' => $schema->string('动作类型：send_group_message（发群消息）/ send_user_message（提醒自己）')->required(),
            'target_id' => $schema->string('目标 chatid，action_type 为 send_group_message 时必填'),
            'content' => $schema->string('消息内容')->required(),
            'msg_type' => $schema->string('消息类型：text（默认）或 markdown，仅群消息有效'),
            'schedule_type' => $schema->string('调度类型：daily（每天）/ weekdays（工作日）/ weekly（每周）/ monthly（每月）')->required(),
            'execute_time' => $schema->string('执行时间 HH:mm')->required(),
            'day_of_week' => $schema->integer('星期几（1=周一…7=周日），schedule_type 为 weekly 时必填'),
            'day_of_month' => $schema->integer('每月几号（1-31），schedule_type 为 monthly 时必填'),
        ];
    }

    /**
     * 处理创建周期性定时任务请求
     * 验证参数 → 校验类型特有配置 → 组装数据 → 调用 Service 创建
     *
     * @param  Request  $request  MCP 请求
     * @param  ScheduledTaskService  $service  定时任务服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, ScheduledTaskService $service, string $userId): Response
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'action_type' => 'required|string|in:send_group_message,send_user_message',
            'target_id' => 'nullable|string',
            'content' => 'required|string|max:2048',
            'msg_type' => 'nullable|string|in:text,markdown',
            'schedule_type' => 'required|string|in:daily,weekdays,weekly,monthly',
            'execute_time' => 'required|string|regex:/^\d{2}:\d{2}$/',
            'day_of_week' => 'nullable|integer|between:1,7',
            'day_of_month' => 'nullable|integer|between:1,31',
        ]);

        Log::debug('CreateRecurringTaskTool::handle 收到请求', $data);

        // 群消息必须提供 target_id
        if ($data['action_type'] === 'send_group_message' && empty($data['target_id'])) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '发送群消息需要指定 target_id（chatid），请先用 query_group_chats 查询群聊',
            ], JSON_UNESCAPED_UNICODE));
        }

        // weekly 必须指定 day_of_week
        if ($data['schedule_type'] === 'weekly' && empty($data['day_of_week'])) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => 'weekly 类型必须指定 day_of_week（1=周一…7=周日）',
            ], JSON_UNESCAPED_UNICODE));
        }

        // monthly 必须指定 day_of_month
        if ($data['schedule_type'] === 'monthly' && empty($data['day_of_month'])) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => 'monthly 类型必须指定 day_of_month（1-31）',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 组装 action_params
        $actionParams = ['content' => $data['content']];
        if ($data['action_type'] === 'send_group_message') {
            $actionParams['chatid'] = $data['target_id'];
            $actionParams['msg_type'] = $data['msg_type'] ?? 'text';
        }

        // 组装 schedule_config
        $scheduleConfig = null;
        if ($data['schedule_type'] === 'weekly') {
            $scheduleConfig = ['day_of_week' => $data['day_of_week']];
        } elseif ($data['schedule_type'] === 'monthly') {
            $scheduleConfig = ['day_of_month' => $data['day_of_month']];
        }

        $result = $service->create($userId, [
            'title' => $data['title'],
            'action_type' => $data['action_type'],
            'action_params' => $actionParams,
            'schedule_type' => $data['schedule_type'],
            'execute_time' => $data['execute_time'],
            'schedule_config' => $scheduleConfig,
        ]);

        Log::debug('CreateRecurringTaskTool::handle 创建结果', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

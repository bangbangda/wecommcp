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

#[Name('create_onetime_task')]
#[Description('创建一次性定时任务。
当用户说"30分钟后提醒我...""明天上午10点发条消息到群里""下午3点提醒我开会""2小时后通知我确认报价"等
涉及单次、未来某个时间点执行的任务时使用此工具。
action_type：send_group_message（群消息，需要 chatid，不知道时先用 query_group_chats 查询）、
send_user_message（提醒自己）。
重要：需将相对时间（"30分钟后""明天10点"）转为绝对的 execute_date + execute_time。')]
class CreateOnetimeTaskTool extends Tool
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
            'execute_date' => $schema->string('执行日期 YYYY-MM-DD')->required(),
            'execute_time' => $schema->string('执行时间 HH:mm')->required(),
        ];
    }

    /**
     * 处理创建一次性定时任务请求
     * 验证参数 → 组装数据 → 调用 Service 创建
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
            'execute_date' => 'required|string|date_format:Y-m-d',
            'execute_time' => 'required|string|regex:/^\d{2}:\d{2}$/',
        ]);

        Log::debug('CreateOnetimeTaskTool::handle 收到请求', $data);

        // 群消息必须提供 target_id
        if ($data['action_type'] === 'send_group_message' && empty($data['target_id'])) {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '发送群消息需要指定 target_id（chatid），请先用 query_group_chats 查询群聊',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 组装 action_params
        $actionParams = ['content' => $data['content']];
        if ($data['action_type'] === 'send_group_message') {
            $actionParams['chatid'] = $data['target_id'];
            $actionParams['msg_type'] = $data['msg_type'] ?? 'text';
        }

        $result = $service->create($userId, [
            'title' => $data['title'],
            'action_type' => $data['action_type'],
            'action_params' => $actionParams,
            'schedule_type' => 'once',
            'execute_time' => $data['execute_time'],
            'schedule_config' => ['execute_date' => $data['execute_date']],
        ]);

        Log::debug('CreateOnetimeTaskTool::handle 创建结果', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

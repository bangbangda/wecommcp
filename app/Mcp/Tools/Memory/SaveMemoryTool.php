<?php

namespace App\Mcp\Tools\Memory;

use App\Services\UserMemoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('save_memory')]
#[Description(
    '保存用户偏好或习惯到长期记忆。'.
    '当用户表达偏好（"记住""以后默认""我习惯""每次都""我喜欢"）或透露人际关系（"张三是我领导""经常和李明开会"）、'.
    '日程安排（"每周五下午有周会""下个月出差"）时使用此工具。'.
    '模块分类：preferences（偏好，如默认时长、偏好时段）、relationships（人际关系，如上下级、常见协作者）、'.
    'schedule（日程习惯，如固定周会、出差计划）、general（其他零散信息）。'.
    '每条记忆应为一个原子事实（如"默认会议时长 30 分钟"），不要合并多个事实。'.
    '已有相似记忆时会自动更新，无需先删除。'
)]
class SaveMemoryTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string('记忆模块：preferences（偏好）、relationships（人际关系）、schedule（日程习惯）、general（通用）')->required(),
            'content' => $schema->string('要记住的原子事实，如"默认会议时长 30 分钟"，最多 500 字符')->required(),
        ];
    }

    /**
     * 处理保存记忆请求
     *
     * @param  Request  $request  MCP 请求
     * @param  UserMemoryService  $memoryService  记忆服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, UserMemoryService $memoryService, string $userId): Response
    {
        $data = $request->validate([
            'module' => 'required|string|in:preferences,relationships,schedule,general',
            'content' => 'required|string|max:500',
        ]);

        Log::debug('SaveMemoryTool::handle 收到请求', ['user_id' => $userId, ...$data]);

        $result = $memoryService->save($userId, $data['module'], $data['content']);

        Log::debug('SaveMemoryTool::handle 执行结果', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

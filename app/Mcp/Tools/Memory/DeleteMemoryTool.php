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

#[Name('delete_memory')]
#[Description(
    '删除用户的一条长期记忆。'.
    '当用户说"忘掉""不要再记""删掉那条记忆"时使用此工具。'.
    '通过 memory_id 定位要删除的记忆，ID 来自 system prompt 中用户记忆列表的 [Mn] 标签（n 即为 memory_id）。'.
    '只能删除当前用户的记忆。'
)]
class DeleteMemoryTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_id' => $schema->integer('要删除的记忆 ID，对应 system prompt 中 [Mn] 标签的 n')->required(),
        ];
    }

    /**
     * 处理删除记忆请求
     *
     * @param  Request  $request  MCP 请求
     * @param  UserMemoryService  $memoryService  记忆服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, UserMemoryService $memoryService, string $userId): Response
    {
        $data = $request->validate([
            'memory_id' => 'required|integer',
        ]);

        Log::debug('DeleteMemoryTool::handle 收到请求', ['user_id' => $userId, 'memory_id' => $data['memory_id']]);

        $result = $memoryService->delete($userId, $data['memory_id']);

        Log::debug('DeleteMemoryTool::handle 执行结果', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

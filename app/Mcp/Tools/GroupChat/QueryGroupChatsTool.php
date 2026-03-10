<?php

namespace App\Mcp\Tools\GroupChat;

use App\Models\GroupChat;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('query_group_chats')]
#[Description('查询用户的群聊列表。支持查看"我创建的群"或"我参与的群"。
当用户说"我有哪些群""我的群聊""我在哪些群里""查一下我的群"时使用此工具。
支持按群名关键词搜索。如果用户想修改或发消息到某个群但不知道 chatid，先用此工具查询。')]
class QueryGroupChatsTool extends Tool
{
    // 单次查询返回的最大数量
    private const MAX_RESULTS = 20;

    /**
     * 定义 Tool 参数 schema
     *
     * @param  JsonSchema  $schema  JSON Schema 构建器
     * @return array schema 定义
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string('群名关键词搜索'),
            'role' => $schema->string('查询角色：owner（我创建的群）、member（我参与的群，默认）'),
        ];
    }

    /**
     * 处理查询群聊列表请求
     * 根据角色和关键词从本地数据库查询
     *
     * @param  Request  $request  MCP 请求
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, string $userId): Response
    {
        $data = $request->validate([
            'keyword' => 'nullable|string|max:255',
            'role' => 'nullable|string|in:owner,member',
        ]);

        Log::debug('QueryGroupChatsTool::handle 收到请求', array_merge($data, ['userId' => $userId]));

        $role = $data['role'] ?? 'member';

        $query = GroupChat::query();

        if ($role === 'owner') {
            $query->where('owner_userid', $userId);
        } else {
            $query->whereJsonContains('userlist', $userId);
        }

        if (! empty($data['keyword'])) {
            $query->where('name', 'like', "%{$data['keyword']}%");
        }

        $groupChats = $query->latest()->take(self::MAX_RESULTS)->get();

        if ($groupChats->isEmpty()) {
            $roleLabel = $role === 'owner' ? '创建的' : '参与的';

            return Response::text(json_encode([
                'status' => 'empty',
                'count' => 0,
                'group_chats' => [],
                'message' => "没有找到您{$roleLabel}群聊",
            ], JSON_UNESCAPED_UNICODE));
        }

        $result = [
            'status' => 'success',
            'count' => $groupChats->count(),
            'group_chats' => $groupChats->map(fn (GroupChat $g, int $index) => [
                'index' => $index + 1,
                'chatid' => $g->chatid,
                'name' => $g->name ?? '(未命名)',
                'owner' => $g->owner_userid,
                'member_count' => count($g->userlist ?? []),
            ])->values()->toArray(),
            'message' => "找到 {$groupChats->count()} 个群聊",
        ];

        Log::debug('QueryGroupChatsTool::handle 查询成功', ['count' => $groupChats->count()]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

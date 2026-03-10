<?php

namespace App\Mcp\Tools\GroupChat;

use App\Models\GroupChat;
use App\Wecom\WecomGroupChatClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_group_chat')]
#[Description('获取企业微信群聊的详细信息，包括群名、群主、成员列表等。
当用户说"看看这个群的信息""群里有哪些人""这个群谁是群主"时使用此工具。
需要 chatid，如果用户没提供，先用 query_group_chats 查询。
此工具会从企微 API 获取最新信息并同步更新本地记录。')]
class GetGroupChatTool extends Tool
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
            'chatid' => $schema->string('群聊 ID')->required(),
        ];
    }

    /**
     * 处理获取群聊详情请求
     * 调用企微 API 获取最新信息 → 同步更新本地记录 → 返回详情
     *
     * @param  Request  $request  MCP 请求
     * @param  WecomGroupChatClient  $wecomGroupChatClient  群聊服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, WecomGroupChatClient $wecomGroupChatClient, string $userId): Response
    {
        $data = $request->validate([
            'chatid' => 'required|string',
        ]);

        Log::debug('GetGroupChatTool::handle 收到请求', $data);

        $apiResult = $wecomGroupChatClient->getGroupChat($data['chatid']);
        $chatInfo = $apiResult['chat_info'] ?? [];

        if (empty($chatInfo)) {
            return Response::text(json_encode([
                'status' => 'not_found',
                'message' => "未找到群聊「{$data['chatid']}」",
            ], JSON_UNESCAPED_UNICODE));
        }

        // 同步更新本地记录
        GroupChat::updateOrCreate(
            ['chatid' => $chatInfo['chatid']],
            [
                'name' => $chatInfo['name'] ?? null,
                'owner_userid' => $chatInfo['owner'] ?? '',
                'creator_userid' => GroupChat::where('chatid', $chatInfo['chatid'])->value('creator_userid') ?? $userId,
                'userlist' => $chatInfo['userlist'] ?? [],
            ],
        );

        $result = [
            'status' => 'success',
            'chatid' => $chatInfo['chatid'],
            'name' => $chatInfo['name'] ?? '',
            'owner' => $chatInfo['owner'] ?? '',
            'member_count' => count($chatInfo['userlist'] ?? []),
            'userlist' => $chatInfo['userlist'] ?? [],
            'message' => '群聊详情获取成功',
        ];

        Log::debug('GetGroupChatTool::handle 查询成功', ['chatid' => $chatInfo['chatid']]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

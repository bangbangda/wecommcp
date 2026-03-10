<?php

namespace App\Mcp\Tools\GroupChat;

use App\Models\GroupChat;
use App\Services\ContactsService;
use App\Wecom\WecomGroupChatClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_group_chat')]
#[Description('创建企业微信群聊。用于快速拉群沟通、项目协作、通知推送等场景。
当用户说"建个群""拉个群聊""创建一个项目群""把我和小王小李拉个群"时使用此工具。
成员传入中文姓名即可，系统自动匹配为企微用户（支持同音字模糊匹配），
匹配到多个候选时会返回 need_clarification 供确认。
当前用户自动作为群主加入，members 只填其他成员（至少 1 人）。
创建成功后可通过 send_group_message 向群内推送消息。')]
class CreateGroupChatTool extends Tool
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
            'name' => $schema->string('群聊名称，最多 50 个字符'),
            'members' => $schema->array('其他群成员的中文姓名列表（不需要包含当前用户），至少 1 人')->items($schema->string())->required(),
        ];
    }

    /**
     * 处理创建群聊请求
     * 解析成员姓名 → 调用企微 API 创建群 → 写入本地记录
     *
     * @param  Request  $request  MCP 请求
     * @param  ContactsService  $contactsService  通讯录服务
     * @param  WecomGroupChatClient  $wecomGroupChatClient  群聊服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, ContactsService $contactsService, WecomGroupChatClient $wecomGroupChatClient, string $userId): Response
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:50',
            'members' => 'required|array|min:1',
            'members.*' => 'string',
        ]);

        Log::debug('CreateGroupChatTool::handle 收到请求', $data);

        // 解析成员姓名 → userid
        $resolvedMembers = [];
        $ambiguous = [];

        foreach ($data['members'] as $name) {
            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $contact = $matches->first();
                $resolvedMembers[] = [
                    'userid' => $contact->userid,
                    'name' => $contact->name,
                ];
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'input' => $name,
                    'candidates' => $matches->map(fn ($c) => [
                        'name' => $c->name,
                        'department' => $c->department,
                        'position' => $c->position,
                    ])->toArray(),
                ];
            } else {
                $ambiguous[] = [
                    'input' => $name,
                    'candidates' => [],
                    'message' => "未找到联系人「{$name}」",
                ];
            }
        }

        if (! empty($ambiguous)) {
            Log::debug('CreateGroupChatTool::handle 成员歧义', ['resolved' => $resolvedMembers, 'ambiguous' => $ambiguous]);

            return Response::text(json_encode([
                'status' => 'need_clarification',
                'resolved' => $resolvedMembers,
                'ambiguous' => $ambiguous,
                'message' => '部分群成员需要确认，请向用户询问具体是哪一位',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 构建 userlist：当前用户 + 其他成员
        $memberUserids = collect($resolvedMembers)->pluck('userid')->toArray();
        $userlist = array_unique(array_merge([$userId], $memberUserids));

        // 调用企微 API
        $apiResult = $wecomGroupChatClient->createGroupChat(
            userlist: array_values($userlist),
            name: $data['name'] ?? '',
            owner: $userId,
        );

        $chatid = $apiResult['chatid'] ?? '';

        // 写入本地记录
        GroupChat::create([
            'chatid' => $chatid,
            'name' => $data['name'] ?? null,
            'owner_userid' => $userId,
            'creator_userid' => $userId,
            'userlist' => array_values($userlist),
        ]);

        $result = [
            'status' => 'success',
            'chatid' => $chatid,
            'name' => $data['name'] ?? '',
            'owner' => $userId,
            'members' => $resolvedMembers,
            'message' => '群聊创建成功'.($data['name'] ? "「{$data['name']}」" : ''),
        ];

        Log::debug('CreateGroupChatTool::handle 创建成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

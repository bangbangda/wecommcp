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

#[Name('update_group_chat')]
#[Description('修改企业微信群聊。支持改名、换群主、添加/踢出成员。
当用户说"把小王加到群里""把小李踢出群""改个群名""换个群主"时使用此工具。
成员姓名传中文即可，系统自动匹配。需要 chatid，如果用户没提供，先用 query_group_chats 查询。
只能修改本应用创建的群聊。')]
class UpdateGroupChatTool extends Tool
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
            'name' => $schema->string('新的群名称'),
            'new_owner' => $schema->string('新群主的中文姓名'),
            'add_members' => $schema->array('要添加的成员中文姓名列表')->items($schema->string()),
            'remove_members' => $schema->array('要踢出的成员中文姓名列表')->items($schema->string()),
        ];
    }

    /**
     * 处理修改群聊请求
     * 解析姓名 → 调用企微 API → 同步更新本地记录
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
            'chatid' => 'required|string',
            'name' => 'nullable|string|max:50',
            'new_owner' => 'nullable|string',
            'add_members' => 'nullable|array',
            'add_members.*' => 'string',
            'remove_members' => 'nullable|array',
            'remove_members.*' => 'string',
        ]);

        Log::debug('UpdateGroupChatTool::handle 收到请求', $data);

        // 查本地记录
        $groupChat = GroupChat::where('chatid', $data['chatid'])->first();
        if (! $groupChat) {
            return Response::text(json_encode([
                'status' => 'not_found',
                'message' => "未找到群聊「{$data['chatid']}」，请确认 chatid 是否正确",
            ], JSON_UNESCAPED_UNICODE));
        }

        $ambiguous = [];
        $newOwnerUserid = '';
        $addUserids = [];
        $delUserids = [];

        // 解析新群主
        if (! empty($data['new_owner'])) {
            $matches = $contactsService->searchByName($data['new_owner']);

            if ($matches->count() === 1) {
                $newOwnerUserid = $matches->first()->userid;
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'input' => $data['new_owner'],
                    'role' => '新群主',
                    'candidates' => $matches->map(fn ($c) => [
                        'name' => $c->name,
                        'department' => $c->department,
                    ])->toArray(),
                ];
            } else {
                $ambiguous[] = ['input' => $data['new_owner'], 'candidates' => [], 'message' => "未找到联系人「{$data['new_owner']}」"];
            }
        }

        // 解析要添加的成员
        foreach ($data['add_members'] ?? [] as $name) {
            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $addUserids[] = $matches->first()->userid;
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'input' => $name,
                    'role' => '添加成员',
                    'candidates' => $matches->map(fn ($c) => [
                        'name' => $c->name,
                        'department' => $c->department,
                    ])->toArray(),
                ];
            } else {
                $ambiguous[] = ['input' => $name, 'candidates' => [], 'message' => "未找到联系人「{$name}」"];
            }
        }

        // 解析要踢出的成员
        foreach ($data['remove_members'] ?? [] as $name) {
            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $delUserids[] = $matches->first()->userid;
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'input' => $name,
                    'role' => '踢出成员',
                    'candidates' => $matches->map(fn ($c) => [
                        'name' => $c->name,
                        'department' => $c->department,
                    ])->toArray(),
                ];
            } else {
                $ambiguous[] = ['input' => $name, 'candidates' => [], 'message' => "未找到联系人「{$name}」"];
            }
        }

        if (! empty($ambiguous)) {
            return Response::text(json_encode([
                'status' => 'need_clarification',
                'ambiguous' => $ambiguous,
                'message' => '部分成员需要确认，请向用户询问具体是哪一位',
            ], JSON_UNESCAPED_UNICODE));
        }

        // 调用企微 API
        $wecomGroupChatClient->updateGroupChat(
            chatid: $data['chatid'],
            name: $data['name'] ?? '',
            owner: $newOwnerUserid,
            addUserList: $addUserids,
            delUserList: $delUserids,
        );

        // 同步更新本地记录
        $currentUserlist = $groupChat->userlist ?? [];
        $currentUserlist = array_unique(array_merge($currentUserlist, $addUserids));
        $currentUserlist = array_values(array_diff($currentUserlist, $delUserids));

        $updateData = ['userlist' => $currentUserlist];
        if (! empty($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if ($newOwnerUserid !== '') {
            $updateData['owner_userid'] = $newOwnerUserid;
        }
        $groupChat->update($updateData);

        $changes = [];
        if (! empty($data['name'])) {
            $changes[] = "群名改为「{$data['name']}」";
        }
        if ($newOwnerUserid !== '') {
            $changes[] = "群主变更为 {$data['new_owner']}";
        }
        if (! empty($addUserids)) {
            $changes[] = '添加了 '.count($addUserids).' 名成员';
        }
        if (! empty($delUserids)) {
            $changes[] = '移出了 '.count($delUserids).' 名成员';
        }

        $result = [
            'status' => 'success',
            'chatid' => $data['chatid'],
            'changes' => $changes,
            'message' => '群聊修改成功：'.implode('，', $changes),
        ];

        Log::debug('UpdateGroupChatTool::handle 修改成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

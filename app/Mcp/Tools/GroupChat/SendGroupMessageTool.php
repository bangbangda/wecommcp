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

#[Name('send_group_message')]
#[Description('向企业微信群聊推送消息。支持 text（纯文本）和 markdown（结构化排版）两种格式。
当用户说"在群里发个消息""通知一下群成员""给群里发个提醒""推送一条消息到群"时使用此工具。
text 格式支持 @群成员（通过 mentioned_list 指定），markdown 格式支持 font 颜色标签。
重要：content 只填纯正文，不要包含"@人名"文字。@提醒通过 mentioned_list 参数单独指定，API 会自动渲染 @效果。
需要 chatid，如果用户没提供，先用 query_group_chats 查询。
只能向本应用创建的群聊推送消息。')]
class SendGroupMessageTool extends Tool
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
            'content' => $schema->string('纯正文内容，不要包含"@人名"文字（@提醒由 mentioned_list 处理）。最长 2048 字节。text 格式支持 \\n 换行；markdown 格式支持 markdown 子集语法')->required(),
            'msg_type' => $schema->string('消息类型：text（默认）或 markdown'),
            'mentioned_list' => $schema->array('要 @ 的群成员中文姓名列表，仅 text 类型有效。传"所有人"可 @all')->items($schema->string()),
        ];
    }

    /**
     * 处理发送群消息请求
     * 解析 @ 成员 → 构建消息体 → 调用企微 API
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
            'content' => 'required|string|max:2048',
            'msg_type' => 'nullable|string|in:text,markdown',
            'mentioned_list' => 'nullable|array',
            'mentioned_list.*' => 'string',
        ]);

        Log::debug('SendGroupMessageTool::handle 收到请求', $data);

        // 验证群聊存在
        $groupChat = GroupChat::where('chatid', $data['chatid'])->first();
        if (! $groupChat) {
            return Response::text(json_encode([
                'status' => 'not_found',
                'message' => "未找到群聊「{$data['chatid']}」，请确认 chatid 是否正确",
            ], JSON_UNESCAPED_UNICODE));
        }

        $msgType = $data['msg_type'] ?? 'text';

        if ($msgType === 'text') {
            $messageContent = $this->buildTextMessage($data, $contactsService);
        } else {
            $messageContent = ['content' => $data['content']];
        }

        // 如果构建消息时遇到歧义，直接返回
        if ($messageContent instanceof Response) {
            return $messageContent;
        }

        // 调用企微 API
        $wecomGroupChatClient->sendMessage(
            chatid: $data['chatid'],
            msgtype: $msgType,
            content: $messageContent,
        );

        $result = [
            'status' => 'success',
            'chatid' => $data['chatid'],
            'msg_type' => $msgType,
            'message' => "消息已发送到群聊「{$groupChat->name}」",
        ];

        Log::debug('SendGroupMessageTool::handle 发送成功', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 构建 text 类型消息体，解析 mentioned_list 中的姓名为 userid
     *
     * @param  array  $data  请求数据
     * @param  ContactsService  $contactsService  通讯录服务
     * @return array|Response 消息体数组，或歧义时返回 Response
     */
    private function buildTextMessage(array $data, ContactsService $contactsService): array|Response
    {
        $mentionedNames = $data['mentioned_list'] ?? [];
        if (empty($mentionedNames)) {
            return ['content' => $data['content']];
        }

        // 从正文中移除 @人名 文字，避免与 mentioned_list 重复
        $cleanedContent = $this->stripMentionText($data['content'], $mentionedNames);
        $content = ['content' => $cleanedContent];

        $mentionedUserids = [];
        $ambiguous = [];

        foreach ($mentionedNames as $name) {
            // "所有人" 或 "@all" 映射为 @all
            if ($name === '所有人' || $name === '@all') {
                $mentionedUserids[] = '@all';

                continue;
            }

            $matches = $contactsService->searchByName($name);

            if ($matches->count() === 1) {
                $mentionedUserids[] = $matches->first()->userid;
            } elseif ($matches->count() > 1) {
                $ambiguous[] = [
                    'input' => $name,
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
                'message' => '@ 的成员中部分需要确认，请向用户询问具体是哪一位',
            ], JSON_UNESCAPED_UNICODE));
        }

        if (! empty($mentionedUserids)) {
            $content['mentioned_list'] = $mentionedUserids;
        }

        return $content;
    }

    /**
     * 从消息正文中移除 @人名 文字，避免与 mentioned_list API 渲染重复
     * 匹配 @人名（含可选空格），清除后整理多余空白
     *
     * @param  string  $content  原始消息内容
     * @param  array  $mentionedNames  mentioned_list 中的姓名列表
     * @return string 清理后的消息内容
     */
    private function stripMentionText(string $content, array $mentionedNames): string
    {
        foreach ($mentionedNames as $name) {
            // 移除 @人名（@后可带可选空格，人名后的空格也一并清除）
            $pattern = '/@\s*'.preg_quote($name, '/').'\s*/u';
            $content = preg_replace($pattern, '', $content);
        }

        // 整理：合并连续空格、去除首尾空白
        $content = preg_replace('/\s{2,}/', ' ', $content);

        return trim($content);
    }
}

<?php

namespace App\Mcp\Tools\Contact;

use App\Services\ContactsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search_contacts')]
#[Description('搜索企业微信通讯录联系人，支持同音字拼音模糊匹配。当用户想查询某人的联系方式、部门、职位等信息时使用。典型场景："张三的电话号码""小王是哪个部门的""查一下李四的邮箱"。注意：创建会议时不需要单独调用此工具，create_meeting 内部会自动匹配参会人姓名。')]
class SearchContactsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string('要搜索的联系人姓名（中文）')->required(),
        ];
    }

    public function handle(Request $request, ContactsService $contactsService): Response
    {
        $data = $request->validate([
            'name' => 'required|string',
        ]);

        Log::debug('SearchContactsTool::handle 收到请求', $data);

        $results = $contactsService->searchByName($data['name']);

        Log::debug('SearchContactsTool::handle 搜索结果', [
            'query' => $data['name'],
            'count' => $results->count(),
        ]);

        if ($results->isEmpty()) {
            return Response::text(json_encode([
                'status' => 'not_found',
                'message' => "未找到与「{$data['name']}」匹配的联系人",
            ], JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'status' => 'found',
            'count' => $results->count(),
            'contacts' => $results->map(fn ($c) => [
                'userid' => $c->userid,
                'name' => $c->name,
                'department' => $c->department,
                'position' => $c->position,
                'mobile' => $c->mobile,
                'email' => $c->email,
            ])->values()->toArray(),
        ], JSON_UNESCAPED_UNICODE));
    }
}

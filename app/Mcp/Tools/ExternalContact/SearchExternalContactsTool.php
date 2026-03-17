<?php

namespace App\Mcp\Tools\ExternalContact;

use App\Services\ExternalContactService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search_external_contacts')]
#[Description('搜索企业微信外部联系人（客户），支持同音字拼音模糊匹配和备注名搜索。当用户想查询外部客户的信息、所在公司、跟进人等时使用。典型场景："查一下客户张总""外部联系人李四是哪个公司的""我的客户王总的信息"。注意：此工具搜索的是外部联系人（客户），不是企业内部通讯录，内部通讯录请使用 search_contacts。')]
class SearchExternalContactsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string('要搜索的外部联系人姓名或备注名（中文）')->required(),
        ];
    }

    /**
     * 搜索外部联系人，返回匹配结果及跟进人信息
     */
    public function handle(Request $request, ExternalContactService $service): Response
    {
        $data = $request->validate([
            'name' => 'required|string',
        ]);

        Log::debug('SearchExternalContactsTool::handle 收到请求', $data);

        $results = $service->searchByName($data['name']);

        Log::debug('SearchExternalContactsTool::handle 搜索结果', [
            'query' => $data['name'],
            'count' => $results->count(),
        ]);

        if ($results->isEmpty()) {
            return Response::text(json_encode([
                'status' => 'not_found',
                'message' => "未找到与「{$data['name']}」匹配的外部联系人",
            ], JSON_UNESCAPED_UNICODE));
        }

        // 预加载活跃的跟进关系
        $results->load('activeFollowUsers');

        return Response::text(json_encode([
            'status' => 'found',
            'count' => $results->count(),
            'external_contacts' => $results->map(fn ($c) => [
                'external_userid' => $c->external_userid,
                'name' => $c->name,
                'type' => $c->type === 1 ? '微信用户' : ($c->type === 2 ? '企业微信用户' : '未知'),
                'corp_name' => $c->corp_name,
                'position' => $c->position,
                'follow_users' => $c->activeFollowUsers->map(fn ($f) => [
                    'userid' => $f->userid,
                    'remark' => $f->remark,
                    'description' => $f->description,
                ])->values()->toArray(),
            ])->values()->toArray(),
        ], JSON_UNESCAPED_UNICODE));
    }
}

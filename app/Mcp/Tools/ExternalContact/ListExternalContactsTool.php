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

#[Name('list_external_contacts')]
#[Description('列出外部联系人（客户）列表，支持按员工和时间范围筛选。典型场景："我的客户列表""张三跟进了哪些客户""今天新增了多少客户""我这周添加了几个客户""查询昨天所有人新增的客户"。可通过 userid 限定某个员工（需先通过 search_contacts 获取），也可不传 userid 查询全公司。支持 start_date/end_date 按添加时间筛选。')]
class ListExternalContactsTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'userid' => $schema->string('内部员工的 userid，不传则查询全部员工'),
            'start_date' => $schema->string('开始日期，格式 YYYY-MM-DD，筛选该日期之后添加的客户'),
            'end_date' => $schema->string('结束日期，格式 YYYY-MM-DD，筛选该日期之前添加的客户'),
        ];
    }

    /**
     * 列出外部联系人列表，支持按员工和时间范围筛选
     */
    public function handle(Request $request, ExternalContactService $service): Response
    {
        $data = $request->validate([
            'userid' => 'nullable|string',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
        ]);

        $userid = $data['userid'] ?? null;
        $startDate = $data['start_date'] ?? null;
        $endDate = $data['end_date'] ?? null;

        Log::debug('ListExternalContactsTool::handle 收到请求', $data);

        // 有时间范围筛选时，使用 getByDateRange（支持全公司或指定员工）
        if ($startDate || $endDate) {
            $startDate = $startDate ?? '2000-01-01';
            $endDate = $endDate ?? date('Y-m-d');
            $results = $service->getByDateRange($startDate, $endDate, $userid);
        } elseif ($userid) {
            $results = $service->getByFollowUser($userid);
        } else {
            return Response::text(json_encode([
                'status' => 'error',
                'message' => '请提供 userid（查询某人的客户）或 start_date/end_date（查询时间范围内新增的客户）',
            ], JSON_UNESCAPED_UNICODE));
        }

        Log::debug('ListExternalContactsTool::handle 查询结果', [
            'userid' => $userid,
            'start_date' => $startDate ?? null,
            'end_date' => $endDate ?? null,
            'count' => $results->count(),
        ]);

        if ($results->isEmpty()) {
            $message = $userid
                ? "员工 [{$userid}] 在该条件下没有外部联系人"
                : '该条件下没有找到外部联系人';

            return Response::text(json_encode([
                'status' => 'empty',
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'status' => 'found',
            'count' => $results->count(),
            'external_contacts' => $results->map(function ($c) {
                $item = [
                    'external_userid' => $c->external_userid,
                    'name' => $c->name,
                    'type' => $c->type === 1 ? '微信用户' : ($c->type === 2 ? '企业微信用户' : '未知'),
                    'corp_name' => $c->corp_name,
                    'position' => $c->position,
                ];

                // 如果预加载了跟进关系，附带跟进人信息
                if ($c->relationLoaded('activeFollowUsers') && $c->activeFollowUsers->isNotEmpty()) {
                    $item['follow_users'] = $c->activeFollowUsers->map(fn ($f) => [
                        'userid' => $f->userid,
                        'remark' => $f->remark,
                        'follow_at' => $f->follow_at?->format('Y-m-d H:i:s'),
                    ])->values()->toArray();
                }

                return $item;
            })->values()->toArray(),
        ], JSON_UNESCAPED_UNICODE));
    }
}

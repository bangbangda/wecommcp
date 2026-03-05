<?php

namespace App\Mcp\Tools\Meeting;

use App\Models\RecentMeeting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('query_meetings')]
#[Description('查询用户的会议列表。当用户想取消、查看会议但没有提供明确的会议ID时，应先调用此工具获取会议列表。支持关键词模糊搜索（如"周会"可匹配"产品周会""技术周会"），支持按时间范围筛选和排序。典型场景："我今天有什么会""取消我刚刚创建的会""上午那个会是几点"。此工具仅返回会议列表，不执行任何修改或删除操作。')]
class QueryMeetingsTool extends Tool
{
    // 单次查询返回的最大会议数
    private const MAX_RESULTS = 10;

    /**
     * 定义 Tool 参数 schema
     *
     * @param  JsonSchema  $schema  JSON Schema 构建器
     * @return array schema 定义
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keyword' => $schema->string('会议标题关键词搜索'),
            'time_range' => $schema->string('时间范围：today(今天) / tomorrow(明天) / this_week(本周) / recent(最近创建的) / all(全部)。默认 today'),
            'sort_by' => $schema->string('排序字段：start_time（默认）或 created_at'),
        ];
    }

    /**
     * 处理查询会议列表请求
     * 根据关键词、日期过滤当前用户的会议，返回编号列表
     *
     * @param  Request  $request  MCP 请求
     * @param  string|null  $userId  当前用户 ID（从 ChatService 透传）
     * @return Response MCP 响应
     */
    public function handle(Request $request, ?string $userId = null): Response
    {
        $data = $request->validate([
            'keyword' => 'nullable|string|max:255',
            'time_range' => 'nullable|date',
            'sort_by' => 'nullable|string|in:start_time,created_at',
        ]);

        Log::debug('QueryMeetingsTool::handle 收到请求', array_merge($data, ['userId' => $userId]));

        $query = RecentMeeting::query();

        // 始终限定当前用户
        if ($userId) {
            $query->where('creator_userid', $userId);
        }

        // 关键词过滤
        if (! empty($data['keyword'])) {
            $query->where('title', 'like', "%{$data['keyword']}%");
        }

        // 日期过滤
        if (! empty($data['time_range'])) {
            $date = Carbon::parse($data['time_range']);
            $query->whereDate('start_time', $date->toDateString());
        }

        // 排序
        $sortBy = $data['sort_by'] ?? 'start_time';
        $query->latest($sortBy);

        $meetings = $query->take(self::MAX_RESULTS)->get();

        if ($meetings->isEmpty()) {
            $result = [
                'status' => 'empty',
                'count' => 0,
                'meetings' => [],
                'message' => '没有找到会议记录',
            ];

            Log::debug('QueryMeetingsTool::handle 无结果', $result);

            return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        $result = [
            'status' => 'success',
            'count' => $meetings->count(),
            'meetings' => $meetings->map(fn (RecentMeeting $m, int $index) => [
                'index' => $index + 1,
                'title' => $m->title,
                'start_time' => $m->start_time->toIso8601String(),
                'duration_minutes' => $m->duration_minutes,
                'invitees' => $m->invitees,
                'meetingid' => $m->meetingid,
            ])->values()->toArray(),
            'message' => "找到 {$meetings->count()} 个会议",
        ];

        Log::debug('QueryMeetingsTool::handle 查询成功', ['count' => $meetings->count()]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

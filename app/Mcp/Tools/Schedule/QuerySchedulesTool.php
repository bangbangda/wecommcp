<?php

namespace App\Mcp\Tools\Schedule;

use App\Models\RecentSchedule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('query_schedules')]
#[Description('查询用户的日程列表。当用户想了解近期安排或需要定位某个日程时使用此工具。
当用户说"我今天有什么安排""明天有什么事""这周的日程""查一下最近的日程"
"我有没有和张三的日程""上午那个面试是几点"时使用此工具。
支持按关键词搜索日程标题，支持按日期筛选。
如果用户想取消某个日程但没有提供明确的 schedule_id，应先调用此工具获取日程列表。
此工具仅返回日程列表概要，查看完整详情请使用 get_schedule_detail。')]
class QuerySchedulesTool extends Tool
{
    // 单次查询返回的最大日程数
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
            'keyword' => $schema->string('日程标题关键词搜索'),
            'time_range' => $schema->string('日期过滤，ISO 8601 日期字符串，如 2026-03-05'),
            'sort_by' => $schema->string('排序字段：start_time（默认）或 created_at'),
        ];
    }

    /**
     * 处理查询日程列表请求
     * 根据关键词、日期过滤当前用户的日程，返回编号列表
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

        Log::debug('QuerySchedulesTool::handle 收到请求', array_merge($data, ['userId' => $userId]));

        $query = RecentSchedule::query();

        // 始终限定当前用户
        if ($userId) {
            $query->where('creator_userid', $userId);
        }

        // 关键词过滤
        if (! empty($data['keyword'])) {
            $query->where('summary', 'like', "%{$data['keyword']}%");
        }

        // 日期过滤
        if (! empty($data['time_range'])) {
            $date = Carbon::parse($data['time_range']);
            $query->whereDate('start_time', $date->toDateString());
        }

        // 排序
        $sortBy = $data['sort_by'] ?? 'start_time';
        $query->latest($sortBy);

        $schedules = $query->take(self::MAX_RESULTS)->get();

        if ($schedules->isEmpty()) {
            $result = [
                'status' => 'empty',
                'count' => 0,
                'schedules' => [],
                'message' => '没有找到日程记录',
            ];

            Log::debug('QuerySchedulesTool::handle 无结果', $result);

            return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        $result = [
            'status' => 'success',
            'count' => $schedules->count(),
            'schedules' => $schedules->map(fn (RecentSchedule $s, int $index) => [
                'index' => $index + 1,
                'summary' => $s->summary,
                'start_time' => $s->start_time->toIso8601String(),
                'end_time' => $s->end_time->toIso8601String(),
                'location' => $s->location,
                'attendees' => $s->attendees,
                'schedule_id' => $s->schedule_id,
            ])->values()->toArray(),
            'message' => "找到 {$schedules->count()} 个日程",
        ];

        Log::debug('QuerySchedulesTool::handle 查询成功', ['count' => $schedules->count()]);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

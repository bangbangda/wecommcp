<?php

namespace App\Mcp\Tools\Meeting\Concerns;

use App\Models\RecentMeeting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;

trait MeetingLookupTrait
{
    // 返回用户最近会议的数量上限
    private const RECENT_MEETINGS_LIMIT = 5;

    /**
     * 根据标题、时间和用户查找本地会议记录
     * 始终限定 creator_userid，仅查询当前用户创建的会议
     *
     * @param  string|null  $title  会议主题关键词（可选，为空则返回用户最近会议）
     * @param  string|null  $startTime  开始时间（用于缩小到同一天）
     * @param  string|null  $creatorUserId  当前用户 ID
     * @return Collection<RecentMeeting> 匹配的会议列表
     */
    protected function lookupMeetings(?string $title, ?string $startTime, ?string $creatorUserId = null): Collection
    {
        $query = RecentMeeting::query();

        if ($creatorUserId) {
            $query->where('creator_userid', $creatorUserId);
        }

        if ($title) {
            $query->where('title', 'like', "%{$title}%");
        }

        if ($startTime) {
            $date = Carbon::parse($startTime);
            $query->whereDate('start_time', $date->toDateString());
        }

        return $query->latest('start_time')->take(self::RECENT_MEETINGS_LIMIT)->get();
    }

    /**
     * 处理会议查找结果，返回唯一匹配或错误响应
     * 0 条 → not_found（建议使用 query_meetings 查看会议列表）
     * 多条 → need_clarification
     * 1 条 → null（由调用方继续处理）
     *
     * @param  Collection  $meetings  查找到的会议列表
     * @return Response|null 错误响应或 null（表示唯一匹配）
     */
    protected function resolveOrRespond(Collection $meetings): ?Response
    {
        if ($meetings->isEmpty()) {
            $result = [
                'status' => 'not_found',
                'message' => '未找到匹配的会议，建议调用 query_meetings 查看用户的会议列表',
            ];

            Log::debug('MeetingLookupTrait not_found', $result);

            return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
        }

        if ($meetings->count() > 1) {
            return Response::text(json_encode([
                'status' => 'need_clarification',
                'message' => '找到多个匹配的会议，请向用户确认具体是哪一个',
                'candidates' => $meetings->map(fn (RecentMeeting $m) => [
                    'title' => $m->title,
                    'start_time' => $m->start_time->toIso8601String(),
                    'duration_minutes' => $m->duration_minutes,
                    'meetingid' => $m->meetingid,
                ])->values()->toArray(),
            ], JSON_UNESCAPED_UNICODE));
        }

        return null;
    }
}

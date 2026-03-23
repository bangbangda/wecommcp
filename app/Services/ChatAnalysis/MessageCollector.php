<?php

namespace App\Services\ChatAnalysis;

use App\Models\ChatRecord;
use App\Models\Contact;
use App\Models\ExternalContact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 聊天消息采集器
 * 从外部 MySQL 拉取指定日期的单聊文本消息，并解析用户身份
 */
class MessageCollector
{
    /**
     * 采集指定日期的单聊文本消息
     * 过滤：单聊 + 文本 + action=send + 排除机器人（wb 开头）
     *
     * @param  string  $date  日期（Y-m-d）
     * @return Collection 原始消息集合
     */
    public function collectByDate(string $date): Collection
    {
        $startMs = Carbon::parse($date, 'Asia/Shanghai')->startOfDay()->getTimestampMs();
        $endMs = Carbon::parse($date, 'Asia/Shanghai')->endOfDay()->getTimestampMs();

        Log::info('MessageCollector::collectByDate 开始采集', [
            'date' => $date,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
        ]);

        $records = ChatRecord::where('type', 'text')
            ->where('action', 'send')
            ->where('send_time', '>=', $startMs)
            ->where('send_time', '<', $endMs)
            ->where(function ($query) {
                $query->whereNull('room_id')->orWhere('room_id', '');
            })  // 单聊（room_id 为空或空字符串）
            ->where('from', 'not like', 'wb%')  // 排除机器人
            ->orderBy('send_time')
            ->get();

        Log::info('MessageCollector::collectByDate 采集完成', [
            'date' => $date,
            'count' => $records->count(),
        ]);

        return $records;
    }

    /**
     * 采集指定日期指定用户参与的所有消息（单聊 + 群聊）
     * 用于实时工作总结，不再过滤 room_id
     *
     * @param  string  $date  日期（Y-m-d）
     * @param  string  $userid  用户 userid
     * @return Collection 原始消息集合
     */
    public function collectByDateForUser(string $date, string $userid): Collection
    {
        $startMs = Carbon::parse($date, 'Asia/Shanghai')->startOfDay()->getTimestampMs();
        $endMs = Carbon::parse($date, 'Asia/Shanghai')->endOfDay()->getTimestampMs();

        Log::info('MessageCollector::collectByDateForUser 开始采集', [
            'date' => $date,
            'userid' => $userid,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
        ]);

        $records = ChatRecord::where('type', 'text')
            ->where('action', 'send')
            ->where('send_time', '>=', $startMs)
            ->where('send_time', '<', $endMs)
            ->where('from', 'not like', 'wb%')  // 排除机器人
            ->where(function ($query) use ($userid) {
                $query->where('from', $userid)
                    ->orWhereJsonContains('to', $userid);
            })
            ->orderBy('send_time')
            ->get();

        Log::info('MessageCollector::collectByDateForUser 采集完成', [
            'date' => $date,
            'userid' => $userid,
            'count' => $records->count(),
        ]);

        return $records;
    }

    /**
     * 将消息按对话对（A↔B）分组
     * 对话对的 key 按字母序排列，确保 A↔B 和 B↔A 归入同一组
     *
     * @param  Collection  $records  原始消息集合
     * @return Collection<string, Collection> key 为 "userA|userB"，value 为该对话对的消息集合
     */
    public function groupByConversationPair(Collection $records): Collection
    {
        return $records->groupBy(function ($record) {
            $from = $record->from;
            // to 是 JSON 数组，单聊时只有一个接收者
            $to = is_array($record->to) ? ($record->to[0] ?? '') : $record->to;

            // 按字母序排列，确保双向对话归入同一组
            $pair = [$from, $to];
            sort($pair);

            return implode('|', $pair);
        });
    }

    /**
     * 将消息集合格式化为 AI 可读的对话文本
     *
     * @param  Collection  $messages  单个对话对的消息集合（已按时间排序）
     * @param  array  $nameMap  userid → 姓名的映射
     * @return string 格式化后的对话文本
     */
    public function formatConversation(Collection $messages, array $nameMap): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $time = Carbon::createFromTimestampMs($message->send_time, 'Asia/Shanghai')
                ->format('H:i');
            $from = $message->from;
            $name = $nameMap[$from] ?? $from;
            $content = $message->content ?? '';

            $lines[] = "[{$time}] {$name}: {$content}";
        }

        return implode("\n", $lines);
    }

    /**
     * 为一组对话对构建 userid → 姓名映射
     * 优先级：contacts 表 → external_contacts 表 → 消息自带的 from_name/to_name
     *
     * @param  Collection  $messages  消息集合
     * @return array<string, string> userid → 姓名
     */
    public function resolveNames(Collection $messages): array
    {
        // 收集所有涉及的 userid
        $userids = collect();
        foreach ($messages as $message) {
            $userids->push($message->from);
            $to = is_array($message->to) ? ($message->to[0] ?? '') : $message->to;
            if (! empty($to)) {
                $userids->push($to);
            }
        }
        $userids = $userids->unique()->values();

        $nameMap = [];

        // 1. 从 contacts 表查内部员工
        $contacts = Contact::whereIn('userid', $userids)->pluck('name', 'userid');
        foreach ($contacts as $userid => $name) {
            $nameMap[$userid] = $name;
        }

        // 2. 未匹配的尝试 external_contacts 表
        $remaining = $userids->diff(array_keys($nameMap));
        if ($remaining->isNotEmpty()) {
            $externals = ExternalContact::whereIn('external_userid', $remaining)->pluck('name', 'external_userid');
            foreach ($externals as $userid => $name) {
                $nameMap[$userid] = $name;
            }
        }

        // 3. 仍未匹配的，回退用消息自带的 from_name / to_name
        $remaining = $userids->diff(array_keys($nameMap));
        if ($remaining->isNotEmpty()) {
            foreach ($messages as $message) {
                if ($remaining->contains($message->from) && ! empty($message->from_name)) {
                    $nameMap[$message->from] = $message->from_name;
                }
                $to = is_array($message->to) ? ($message->to[0] ?? '') : $message->to;
                if ($remaining->contains($to) && ! empty($message->to_name)) {
                    $nameMap[$to] = $message->to_name;
                }
            }
        }

        return $nameMap;
    }

    /**
     * 判断 userid 是否为内部员工
     *
     * @param  string  $userid  用户 ID
     * @return bool 内部员工返回 true
     */
    public function isInternalUser(string $userid): bool
    {
        // 外部联系人以 wo/wm 开头，机器人以 wb 开头
        return ! str_starts_with($userid, 'wo')
            && ! str_starts_with($userid, 'wm')
            && ! str_starts_with($userid, 'wb');
    }

    /**
     * 从对话对 key 中提取两个 userid
     *
     * @param  string  $pairKey  格式 "userA|userB"
     * @return array{0: string, 1: string}
     */
    public function parsePairKey(string $pairKey): array
    {
        return explode('|', $pairKey, 2);
    }
}

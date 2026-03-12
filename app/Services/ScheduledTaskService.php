<?php

namespace App\Services;

use App\Jobs\ExecuteScheduledTaskJob;
use App\Models\ScheduledTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ScheduledTaskService
{
    /** 有效的动作类型 */
    public const VALID_ACTION_TYPES = ['send_group_message', 'send_user_message'];

    /** 有效的调度类型 */
    public const VALID_SCHEDULE_TYPES = ['once', 'daily', 'weekdays', 'weekly', 'monthly'];

    /** 周期性调度类型（Scheduler 处理） */
    public const RECURRING_SCHEDULE_TYPES = ['daily', 'weekdays', 'weekly', 'monthly'];

    /** 调度类型中文描述 */
    private const SCHEDULE_TYPE_LABELS = [
        'once' => '一次性',
        'daily' => '每天',
        'weekdays' => '工作日',
        'weekly' => '每周',
        'monthly' => '每月',
    ];

    /** 星期中文映射 */
    private const WEEKDAY_LABELS = [
        1 => '周一',
        2 => '周二',
        3 => '周三',
        4 => '周四',
        5 => '周五',
        6 => '周六',
        7 => '周日',
    ];

    /**
     * 创建定时任务
     * once 类型通过 Queue 延迟执行，recurring 类型由 Scheduler 每分钟检查
     *
     * @param  string  $userId  创建者 userid
     * @param  array  $data  任务数据
     * @return array{status: string, task_id?: int, next_run_at?: string, message: string}
     */
    public function create(string $userId, array $data): array
    {
        $nextRunAt = $this->calculateNextRunAt(
            $data['schedule_type'],
            $data['execute_time'],
            $data['schedule_config'] ?? null,
        );

        if (! $nextRunAt) {
            return [
                'status' => 'error',
                'message' => '执行时间已过期，请设置一个未来的时间',
            ];
        }

        $task = ScheduledTask::create([
            'user_id' => $userId,
            'title' => $data['title'],
            'action_type' => $data['action_type'],
            'action_params' => $data['action_params'],
            'schedule_type' => $data['schedule_type'],
            'execute_time' => $data['execute_time'],
            'schedule_config' => $data['schedule_config'] ?? null,
            'next_run_at' => $nextRunAt,
            'is_active' => true,
        ]);

        Log::info('ScheduledTaskService::create 创建定时任务', [
            'task_id' => $task->id,
            'user_id' => $userId,
            'schedule_type' => $data['schedule_type'],
            'next_run_at' => $nextRunAt->toDateTimeString(),
        ]);

        // once 类型通过 Queue 延迟执行
        if ($data['schedule_type'] === 'once') {
            ExecuteScheduledTaskJob::dispatch($task->id)->delay($nextRunAt);

            Log::debug('ScheduledTaskService::create 已派发延迟 Job', [
                'task_id' => $task->id,
                'delay_until' => $nextRunAt->toDateTimeString(),
            ]);
        }

        return [
            'status' => 'success',
            'task_id' => $task->id,
            'next_run_at' => $nextRunAt->timezone('Asia/Shanghai')->format('Y-m-d H:i'),
            'schedule_description' => $this->formatScheduleDescription($task),
            'message' => "定时任务「{$task->title}」已创建",
        ];
    }

    /**
     * 取消定时任务
     * 设置 is_active=false，Job 执行时会自检跳过
     *
     * @param  string  $userId  用户 userid
     * @param  int  $taskId  任务 ID
     * @return array{status: string, message: string}
     */
    public function cancel(string $userId, int $taskId): array
    {
        $task = ScheduledTask::where('id', $taskId)
            ->where('user_id', $userId)
            ->first();

        if (! $task) {
            return [
                'status' => 'not_found',
                'message' => "未找到 ID 为 {$taskId} 的定时任务，或该任务不属于当前用户",
            ];
        }

        if (! $task->is_active) {
            return [
                'status' => 'already_cancelled',
                'message' => "任务「{$task->title}」已经是停用状态",
            ];
        }

        $task->update(['is_active' => false]);

        Log::info('ScheduledTaskService::cancel 取消定时任务', [
            'task_id' => $taskId,
            'user_id' => $userId,
            'title' => $task->title,
        ]);

        return [
            'status' => 'success',
            'message' => "已取消定时任务「{$task->title}」",
        ];
    }

    /**
     * 查询用户的定时任务列表
     *
     * @param  string  $userId  用户 userid
     * @param  string|null  $keyword  按标题关键词筛选
     * @param  bool|null  $isActive  筛选状态，null=全部
     * @return Collection<int, ScheduledTask>
     */
    public function getByUser(string $userId, ?string $keyword = null, ?bool $isActive = null): Collection
    {
        $query = ScheduledTask::where('user_id', $userId);

        if ($keyword !== null) {
            $query->where('title', 'like', "%{$keyword}%");
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->orderBy('next_run_at')->get();
    }

    /**
     * 执行定时任务
     * 根据 action_type 调用对应的企微 API
     *
     * @param  ScheduledTask  $task  任务记录
     */
    public function executeTask(ScheduledTask $task): void
    {
        Log::info('ScheduledTaskService::executeTask 开始执行', [
            'task_id' => $task->id,
            'action_type' => $task->action_type,
            'title' => $task->title,
        ]);

        $params = $task->action_params;

        match ($task->action_type) {
            'send_group_message' => $this->executeSendGroupMessage($params),
            'send_user_message' => $this->executeSendUserMessage($task->user_id, $params),
            default => Log::error('ScheduledTaskService::executeTask 未知的动作类型', [
                'task_id' => $task->id,
                'action_type' => $task->action_type,
            ]),
        };

        $this->markExecuted($task);

        Log::info('ScheduledTaskService::executeTask 执行完成', [
            'task_id' => $task->id,
        ]);
    }

    /**
     * 计算下次执行时间
     * 所有计算使用 Asia/Shanghai 时区
     *
     * @param  string  $scheduleType  调度类型
     * @param  string  $executeTime  执行时间 HH:mm
     * @param  array|null  $scheduleConfig  类型特有配置
     * @return Carbon|null 下次执行时间，已过期返回 null
     */
    public function calculateNextRunAt(string $scheduleType, string $executeTime, ?array $scheduleConfig = null): ?Carbon
    {
        $now = now('Asia/Shanghai');
        [$hour, $minute] = explode(':', $executeTime);

        return match ($scheduleType) {
            'once' => $this->calculateOnceNextRun($scheduleConfig, (int) $hour, (int) $minute, $now),
            'daily' => $this->calculateDailyNextRun((int) $hour, (int) $minute, $now),
            'weekdays' => $this->calculateWeekdaysNextRun((int) $hour, (int) $minute, $now),
            'weekly' => $this->calculateWeeklyNextRun($scheduleConfig, (int) $hour, (int) $minute, $now),
            'monthly' => $this->calculateMonthlyNextRun($scheduleConfig, (int) $hour, (int) $minute, $now),
            default => null,
        };
    }

    /**
     * 标记任务已执行
     * once 类型停用，recurring 类型重算 next_run_at
     *
     * @param  ScheduledTask  $task  任务记录
     */
    public function markExecuted(ScheduledTask $task): void
    {
        if ($task->schedule_type === 'once') {
            $task->update([
                'last_run_at' => now(),
                'is_active' => false,
            ]);

            return;
        }

        // recurring: 重算 next_run_at
        $nextRunAt = $this->calculateNextRunAt(
            $task->schedule_type,
            $task->execute_time,
            $task->schedule_config,
        );

        $task->update([
            'last_run_at' => now(),
            'next_run_at' => $nextRunAt,
        ]);
    }

    /**
     * 生成任务的可读调度描述
     *
     * @param  ScheduledTask  $task  任务记录
     * @return string 如 "每天 09:00"、"每周五 16:00"
     */
    public function formatScheduleDescription(ScheduledTask $task): string
    {
        $time = $task->execute_time;
        $label = self::SCHEDULE_TYPE_LABELS[$task->schedule_type] ?? $task->schedule_type;

        return match ($task->schedule_type) {
            'once' => ($task->schedule_config['execute_date'] ?? '')." {$time}（一次性）",
            'daily' => "{$label} {$time}",
            'weekdays' => "{$label} {$time}（周一至周五）",
            'weekly' => "{$label}".(self::WEEKDAY_LABELS[$task->schedule_config['day_of_week'] ?? 0] ?? '')." {$time}",
            'monthly' => "{$label}".($task->schedule_config['day_of_month'] ?? '')."号 {$time}",
            default => "{$label} {$time}",
        };
    }

    /**
     * 执行群消息发送
     *
     * @param  array  $params  动作参数 {chatid, content, msg_type?}
     */
    private function executeSendGroupMessage(array $params): void
    {
        $chatid = $params['chatid'];
        $msgtype = $params['msg_type'] ?? 'text';
        $content = ['content' => $params['content']];

        Log::debug('ScheduledTaskService 发送群消息', [
            'chatid' => $chatid,
            'msgtype' => $msgtype,
        ]);

        app(\App\Wecom\WecomGroupChatClient::class)->sendMessage(
            chatid: $chatid,
            msgtype: $msgtype,
            content: $content,
        );
    }

    /**
     * 执行用户提醒消息发送
     * 优先发给 action_params 中指定的 target_userid，未指定则发给任务创建者
     *
     * @param  string  $userId  任务创建者 userid
     * @param  array  $params  动作参数 {content, target_userid?}
     */
    private function executeSendUserMessage(string $userId, array $params): void
    {
        $targetUserId = $params['target_userid'] ?? $userId;
        $content = $params['content'];

        Log::debug('ScheduledTaskService 发送用户提醒', [
            'targetUserId' => $targetUserId,
            'creatorUserId' => $userId,
            'content' => $content,
        ]);

        app(\App\Wecom\WecomMessageClient::class)->sendText($targetUserId, $content);
    }

    /**
     * 计算 once 类型的下次执行时间
     */
    private function calculateOnceNextRun(?array $config, int $hour, int $minute, Carbon $now): ?Carbon
    {
        $date = $config['execute_date'] ?? null;
        if (! $date) {
            return null;
        }

        $runAt = Carbon::parse($date, 'Asia/Shanghai')->setTime($hour, $minute);

        return $runAt->gt($now) ? $runAt : null;
    }

    /**
     * 计算 daily 类型的下次执行时间
     */
    private function calculateDailyNextRun(int $hour, int $minute, Carbon $now): Carbon
    {
        $today = $now->copy()->setTime($hour, $minute);

        return $today->gt($now) ? $today : $today->addDay();
    }

    /**
     * 计算 weekdays 类型的下次执行时间（跳过周末）
     */
    private function calculateWeekdaysNextRun(int $hour, int $minute, Carbon $now): Carbon
    {
        $candidate = $now->copy()->setTime($hour, $minute);

        // 如果今天是工作日且时间未过
        if ($candidate->gt($now) && $candidate->isWeekday()) {
            return $candidate;
        }

        // 找下一个工作日
        $candidate = $now->copy()->addDay()->setTime($hour, $minute);
        while ($candidate->isWeekend()) {
            $candidate->addDay();
        }

        return $candidate;
    }

    /**
     * 计算 weekly 类型的下次执行时间
     */
    private function calculateWeeklyNextRun(?array $config, int $hour, int $minute, Carbon $now): Carbon
    {
        $dayOfWeek = $config['day_of_week'] ?? 1;

        // Carbon: 1=Monday ... 7=Sunday（ISO format）
        $candidate = $now->copy()->setTime($hour, $minute);

        // 调整到目标星期
        $currentDayOfWeek = $candidate->dayOfWeekIso;
        if ($currentDayOfWeek === $dayOfWeek && $candidate->gt($now)) {
            return $candidate;
        }

        $daysUntil = ($dayOfWeek - $currentDayOfWeek + 7) % 7;
        if ($daysUntil === 0) {
            $daysUntil = 7; // 今天已过，下周同一天
        }

        return $candidate->addDays($daysUntil);
    }

    /**
     * 计算 monthly 类型的下次执行时间
     */
    private function calculateMonthlyNextRun(?array $config, int $hour, int $minute, Carbon $now): Carbon
    {
        $dayOfMonth = $config['day_of_month'] ?? 1;

        // 尝试本月
        $candidate = $now->copy()->setTime($hour, $minute);
        $candidate->day = min($dayOfMonth, $candidate->daysInMonth);

        if ($candidate->gt($now)) {
            return $candidate;
        }

        // 下个月
        $candidate = $now->copy()->addMonth()->startOfMonth()->setTime($hour, $minute);
        $candidate->day = min($dayOfMonth, $candidate->daysInMonth);

        return $candidate;
    }
}

<?php

namespace App\Jobs;

use App\Models\ScheduledTask;
use App\Services\ScheduledTaskService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 执行一次性定时任务的 Queue Job
 * 通过 dispatch()->delay() 在指定时间执行
 * 执行前检查 is_active 状态，已取消则静默跳过
 */
class ExecuteScheduledTaskJob implements ShouldQueue
{
    use Queueable;

    /** 允许一次重试（API 瞬时失败） */
    public int $tries = 2;

    /** 发消息很快，不需要长超时 */
    public int $timeout = 30;

    /**
     * @param  int  $taskId  定时任务 ID
     */
    public function __construct(public readonly int $taskId) {}

    /**
     * 执行任务
     * 检查任务是否存在且处于启用状态，满足条件时调用 Service 执行
     *
     * @param  ScheduledTaskService  $service  定时任务服务
     */
    public function handle(ScheduledTaskService $service): void
    {
        $task = ScheduledTask::find($this->taskId);

        if (! $task || ! $task->is_active) {
            Log::info('ExecuteScheduledTaskJob 任务已取消或不存在，跳过', [
                'task_id' => $this->taskId,
                'exists' => (bool) $task,
                'is_active' => $task?->is_active,
            ]);

            return;
        }

        $service->executeTask($task);
    }
}

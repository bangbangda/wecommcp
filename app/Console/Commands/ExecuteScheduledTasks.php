<?php

namespace App\Console\Commands;

use App\Models\ScheduledTask;
use App\Services\ScheduledTaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 执行到期的周期性定时任务
 * 由 Scheduler 每分钟调度，查询到期的 recurring 任务并逐个执行
 */
class ExecuteScheduledTasks extends Command
{
    protected $signature = 'scheduled-tasks:execute';

    protected $description = '执行到期的周期性定时任务';

    /**
     * 查询并执行所有到期的周期性任务
     * 每个任务独立 try/catch，一个失败不影响其他
     *
     * @param  ScheduledTaskService  $service  定时任务服务
     */
    public function handle(ScheduledTaskService $service): void
    {
        $tasks = ScheduledTask::where('is_active', true)
            ->whereNotIn('schedule_type', ['once'])
            ->where('next_run_at', '<=', now())
            ->get();

        if ($tasks->isEmpty()) {
            return;
        }

        Log::info('ExecuteScheduledTasks 开始执行到期任务', [
            'count' => $tasks->count(),
        ]);

        foreach ($tasks as $task) {
            try {
                $service->executeTask($task);
            } catch (\Throwable $e) {
                Log::error('ExecuteScheduledTasks 任务执行失败', [
                    'task_id' => $task->id,
                    'title' => $task->title,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ExecuteScheduledTasks 执行完毕', [
            'count' => $tasks->count(),
        ]);
    }
}

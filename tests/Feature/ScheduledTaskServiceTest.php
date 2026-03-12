<?php

use App\Jobs\ExecuteScheduledTaskJob;
use App\Models\ScheduledTask;
use App\Services\ScheduledTaskService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('calculateNextRunAt once 类型返回指定日期时间', function () {
    $service = app(ScheduledTaskService::class);

    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $result = $service->calculateNextRunAt('once', '15:00', ['execute_date' => '2026-03-12']);

    expect($result)->not->toBeNull()
        ->and($result->format('Y-m-d H:i'))->toBe('2026-03-12 15:00');

    Carbon::setTestNow();
});

test('calculateNextRunAt once 类型已过期返回 null', function () {
    $service = app(ScheduledTaskService::class);

    Carbon::setTestNow(Carbon::parse('2026-03-12 16:00:00', 'Asia/Shanghai'));

    $result = $service->calculateNextRunAt('once', '15:00', ['execute_date' => '2026-03-12']);

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

test('calculateNextRunAt daily 类型今天未到返回今天', function () {
    $service = app(ScheduledTaskService::class);

    Carbon::setTestNow(Carbon::parse('2026-03-12 08:00:00', 'Asia/Shanghai'));

    $result = $service->calculateNextRunAt('daily', '09:00');

    expect($result->format('Y-m-d H:i'))->toBe('2026-03-12 09:00');

    Carbon::setTestNow();
});

test('calculateNextRunAt daily 类型已过返回明天', function () {
    $service = app(ScheduledTaskService::class);

    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $result = $service->calculateNextRunAt('daily', '09:00');

    expect($result->format('Y-m-d H:i'))->toBe('2026-03-13 09:00');

    Carbon::setTestNow();
});

test('calculateNextRunAt weekdays 类型跳过周末', function () {
    $service = app(ScheduledTaskService::class);

    // 2026-03-13 是周五
    Carbon::setTestNow(Carbon::parse('2026-03-13 10:00:00', 'Asia/Shanghai'));

    $result = $service->calculateNextRunAt('weekdays', '09:00');

    // 周五已过，下个工作日是周一 2026-03-16
    expect($result->format('Y-m-d'))->toBe('2026-03-16')
        ->and($result->isWeekday())->toBeTrue();

    Carbon::setTestNow();
});

test('calculateNextRunAt weekly 类型返回正确的下周某天', function () {
    $service = app(ScheduledTaskService::class);

    // 2026-03-12 是周四
    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    // day_of_week=5 是周五
    $result = $service->calculateNextRunAt('weekly', '16:00', ['day_of_week' => 5]);

    expect($result->format('Y-m-d'))->toBe('2026-03-13')
        ->and($result->dayOfWeekIso)->toBe(5);

    Carbon::setTestNow();
});

test('calculateNextRunAt monthly 类型返回正确的下月某号', function () {
    $service = app(ScheduledTaskService::class);

    Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00', 'Asia/Shanghai'));

    // day_of_month=1，本月1号已过，应返回下月1号
    $result = $service->calculateNextRunAt('monthly', '09:00', ['day_of_month' => 1]);

    expect($result->format('Y-m-d'))->toBe('2026-04-01');

    Carbon::setTestNow();
});

test('create once 类型 dispatch 延迟 Job', function () {
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $service = app(ScheduledTaskService::class);

    $result = $service->create('user1', [
        'title' => '提醒确认报价',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '记得确认报价'],
        'schedule_type' => 'once',
        'execute_time' => '15:00',
        'schedule_config' => ['execute_date' => '2026-03-12'],
    ]);

    expect($result['status'])->toBe('success')
        ->and($result['task_id'])->toBeInt();

    Queue::assertPushed(ExecuteScheduledTaskJob::class, function ($job) use ($result) {
        return $job->taskId === $result['task_id'];
    });

    Carbon::setTestNow();
});

test('create daily 类型不 dispatch Job', function () {
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-03-12 08:00:00', 'Asia/Shanghai'));

    $service = app(ScheduledTaskService::class);

    $result = $service->create('user1', [
        'title' => '日报提醒',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '记得提交日报'],
        'schedule_type' => 'daily',
        'execute_time' => '17:00',
    ]);

    expect($result['status'])->toBe('success');

    Queue::assertNotPushed(ExecuteScheduledTaskJob::class);

    Carbon::setTestNow();
});

test('cancel 设置 is_active 为 false', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '测试任务',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '测试'],
        'schedule_type' => 'daily',
        'execute_time' => '09:00',
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $service = app(ScheduledTaskService::class);
    $result = $service->cancel('user1', $task->id);

    expect($result['status'])->toBe('success');

    $task->refresh();
    expect($task->is_active)->toBeFalse();

    Carbon::setTestNow();
});

test('cancel 不存在返回 not_found', function () {
    $service = app(ScheduledTaskService::class);
    $result = $service->cancel('user1', 9999);

    expect($result['status'])->toBe('not_found');
});

test('cancel 跨用户隔离', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '测试任务',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '测试'],
        'schedule_type' => 'daily',
        'execute_time' => '09:00',
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $service = app(ScheduledTaskService::class);
    $result = $service->cancel('user2', $task->id);

    expect($result['status'])->toBe('not_found');

    Carbon::setTestNow();
});

test('executeTask send_group_message 调用 WecomGroupChatClient', function () {
    $mockClient = Mockery::mock(\App\Wecom\WecomGroupChatClient::class);
    $mockClient->shouldReceive('sendMessage')
        ->once()
        ->with('chatid_001', 'text', ['content' => '日报提醒']);
    app()->instance(\App\Wecom\WecomGroupChatClient::class, $mockClient);

    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '日报提醒',
        'action_type' => 'send_group_message',
        'action_params' => ['chatid' => 'chatid_001', 'content' => '日报提醒', 'msg_type' => 'text'],
        'schedule_type' => 'daily',
        'execute_time' => '09:00',
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $service = app(ScheduledTaskService::class);
    $service->executeTask($task);

    $task->refresh();
    expect($task->last_run_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('executeTask send_user_message 调用 WecomMessageClient', function () {
    $mockClient = Mockery::mock(\App\Wecom\WecomMessageClient::class);
    $mockClient->shouldReceive('sendText')
        ->once()
        ->with('user1', '记得确认报价');
    app()->instance(\App\Wecom\WecomMessageClient::class, $mockClient);

    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '确认报价',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '记得确认报价'],
        'schedule_type' => 'once',
        'execute_time' => '15:00',
        'schedule_config' => ['execute_date' => '2026-03-12'],
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $service = app(ScheduledTaskService::class);
    $service->executeTask($task);

    $task->refresh();
    expect($task->is_active)->toBeFalse()
        ->and($task->last_run_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('markExecuted once 类型设置 is_active 为 false', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 15:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '一次性任务',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '测试'],
        'schedule_type' => 'once',
        'execute_time' => '15:00',
        'schedule_config' => ['execute_date' => '2026-03-12'],
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $service = app(ScheduledTaskService::class);
    $service->markExecuted($task);

    $task->refresh();
    expect($task->is_active)->toBeFalse()
        ->and($task->last_run_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('markExecuted daily 类型重算 next_run_at', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 09:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '每日任务',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '测试'],
        'schedule_type' => 'daily',
        'execute_time' => '09:00',
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $service = app(ScheduledTaskService::class);
    $service->markExecuted($task);

    $task->refresh();
    expect($task->is_active)->toBeTrue()
        ->and($task->next_run_at->format('Y-m-d'))->toBe('2026-03-13')
        ->and($task->last_run_at)->not->toBeNull();

    Carbon::setTestNow();
});

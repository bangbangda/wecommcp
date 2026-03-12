<?php

use App\Jobs\ExecuteScheduledTaskJob;
use App\Models\ScheduledTask;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

test('create_onetime_task 创建成功', function () {
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $tool = app(\App\Mcp\Tools\ScheduledTask\CreateOnetimeTaskTool::class);
    $request = new Request([
        'title' => '提醒确认报价',
        'action_type' => 'send_user_message',
        'content' => '记得确认报价信息',
        'execute_date' => '2026-03-12',
        'execute_time' => '15:00',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('success')
        ->and($result['task_id'])->toBeInt()
        ->and($result['next_run_at'])->toBe('2026-03-12 15:00');

    $this->assertDatabaseHas('scheduled_tasks', [
        'user_id' => 'user1',
        'title' => '提醒确认报价',
        'schedule_type' => 'once',
    ]);

    Queue::assertPushed(ExecuteScheduledTaskJob::class);

    Carbon::setTestNow();
});

test('create_onetime_task 提醒其他用户创建成功', function () {
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $tool = app(\App\Mcp\Tools\ScheduledTask\CreateOnetimeTaskTool::class);
    $request = new Request([
        'title' => '提醒张三交报告',
        'action_type' => 'send_user_message',
        'target_id' => 'zhangsan',
        'content' => '记得交项目报告',
        'execute_date' => '2026-03-13',
        'execute_time' => '10:00',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('success')
        ->and($result['task_id'])->toBeInt();

    $task = ScheduledTask::find($result['task_id']);
    expect($task->action_params['target_userid'])->toBe('zhangsan')
        ->and($task->user_id)->toBe('user1');

    Carbon::setTestNow();
});

test('create_onetime_task 过期时间返回错误', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 16:00:00', 'Asia/Shanghai'));

    $tool = app(\App\Mcp\Tools\ScheduledTask\CreateOnetimeTaskTool::class);
    $request = new Request([
        'title' => '过期任务',
        'action_type' => 'send_user_message',
        'content' => '测试',
        'execute_date' => '2026-03-12',
        'execute_time' => '15:00',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('过期');

    Carbon::setTestNow();
});

test('create_recurring_task 创建 daily 任务成功', function () {
    Queue::fake();

    Carbon::setTestNow(Carbon::parse('2026-03-12 08:00:00', 'Asia/Shanghai'));

    $tool = app(\App\Mcp\Tools\ScheduledTask\CreateRecurringTaskTool::class);
    $request = new Request([
        'title' => '每日日报提醒',
        'action_type' => 'send_user_message',
        'content' => '记得提交日报',
        'schedule_type' => 'daily',
        'execute_time' => '17:00',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('success')
        ->and($result['schedule_description'])->toContain('每天');

    $this->assertDatabaseHas('scheduled_tasks', [
        'user_id' => 'user1',
        'schedule_type' => 'daily',
    ]);

    // daily 类型不 dispatch Job
    Queue::assertNotPushed(ExecuteScheduledTaskJob::class);

    Carbon::setTestNow();
});

test('create_recurring_task weekly 无 day_of_week 返回错误', function () {
    $tool = app(\App\Mcp\Tools\ScheduledTask\CreateRecurringTaskTool::class);
    $request = new Request([
        'title' => '周报提醒',
        'action_type' => 'send_user_message',
        'content' => '记得写周报',
        'schedule_type' => 'weekly',
        'execute_time' => '16:00',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('day_of_week');
});

test('create_onetime_task 群消息无 target_id 返回错误', function () {
    $tool = app(\App\Mcp\Tools\ScheduledTask\CreateOnetimeTaskTool::class);
    $request = new Request([
        'title' => '群消息测试',
        'action_type' => 'send_group_message',
        'content' => '测试',
        'execute_date' => '2026-03-12',
        'execute_time' => '15:00',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('target_id');
});

test('query_scheduled_tasks 返回用户任务列表', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '日报提醒',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '记得提交日报'],
        'schedule_type' => 'daily',
        'execute_time' => '17:00',
        'next_run_at' => Carbon::parse('2026-03-12 17:00:00', 'Asia/Shanghai'),
        'is_active' => true,
    ]);

    $tool = app(\App\Mcp\Tools\ScheduledTask\QueryScheduledTasksTool::class);
    $request = new Request([]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('success')
        ->and($result['count'])->toBe(1)
        ->and($result['tasks'][0]['title'])->toBe('日报提醒');

    Carbon::setTestNow();
});

test('query_scheduled_tasks 无任务返回 empty', function () {
    $tool = app(\App\Mcp\Tools\ScheduledTask\QueryScheduledTasksTool::class);
    $request = new Request([]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('empty');
});

test('cancel_scheduled_task 取消成功', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '待取消任务',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '测试'],
        'schedule_type' => 'daily',
        'execute_time' => '09:00',
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $tool = app(\App\Mcp\Tools\ScheduledTask\CancelScheduledTaskTool::class);
    $request = new Request(['task_id' => $task->id]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('success');

    $task->refresh();
    expect($task->is_active)->toBeFalse();

    Carbon::setTestNow();
});

test('cancel_scheduled_task 跨用户隔离', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-12 10:00:00', 'Asia/Shanghai'));

    $task = ScheduledTask::create([
        'user_id' => 'user1',
        'title' => '用户1的任务',
        'action_type' => 'send_user_message',
        'action_params' => ['content' => '测试'],
        'schedule_type' => 'daily',
        'execute_time' => '09:00',
        'next_run_at' => now(),
        'is_active' => true,
    ]);

    $tool = app(\App\Mcp\Tools\ScheduledTask\CancelScheduledTaskTool::class);
    $request = new Request(['task_id' => $task->id]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user2']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('not_found');

    Carbon::setTestNow();
});

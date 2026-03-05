<?php

use App\Jobs\ProcessWecomMessage;
use App\Models\WecomBotMessage;
use App\Services\ChatService;
use App\Wecom\WecomMessageClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('ProcessWecomMessage Job 成功时写入 Cache 和 DB', function () {
    // 创建 DB 记录
    WecomBotMessage::create([
        'msgid' => 'msg_job_001',
        'aibotid' => 'bot001',
        'chattype' => 'single',
        'userid' => 'test_user',
        'msgtype' => 'text',
        'content' => '你好',
        'stream_id' => 'stream_job_001',
        'response_url' => 'https://example.com/callback',
    ]);

    $mockChat = Mockery::mock(ChatService::class);
    $mockChat->shouldReceive('chat')
        ->with('test_user', '你好', Mockery::type('Closure'))
        ->once()
        ->andReturn('你好！有什么可以帮你的？');

    $job = new ProcessWecomMessage('test_user', '你好', 'stream_job_001', 'https://example.com/callback');
    $job->handle($mockChat);

    // 验证 Cache 写入
    $cached = Cache::get('wecom_stream:stream_job_001');
    expect($cached['finish'])->toBeTrue();
    expect($cached['content'])->toBe('你好！有什么可以帮你的？');

    // 验证 DB 更新
    $record = WecomBotMessage::where('stream_id', 'stream_job_001')->first();
    expect($record->reply)->toBe('你好！有什么可以帮你的？');
    expect($record->replied_at)->not->toBeNull();
});

test('ProcessWecomMessage Job 进度回调更新 Cache', function () {
    // 创建 DB 记录
    WecomBotMessage::create([
        'msgid' => 'msg_progress_001',
        'aibotid' => 'bot001',
        'chattype' => 'single',
        'userid' => 'test_user',
        'msgtype' => 'text',
        'content' => '帮我创建会议',
        'stream_id' => 'stream_progress_001',
        'response_url' => 'https://example.com/callback',
    ]);

    $progressMessages = [];

    $mockChat = Mockery::mock(ChatService::class);
    $mockChat->shouldReceive('chat')
        ->with('test_user', '帮我创建会议', Mockery::type('Closure'))
        ->once()
        ->andReturnUsing(function ($userId, $content, $onProgress) use (&$progressMessages) {
            // 模拟 ChatService 内部调用 onProgress
            $onProgress('正在分析...');
            $onProgress('正在创建会议...');

            // 验证 Cache 中间状态
            $cached = Cache::get('wecom_stream:stream_progress_001');
            expect($cached['finish'])->toBeFalse();
            expect($cached['content'])->toBe('正在创建会议...');

            return '会议创建成功';
        });

    $job = new ProcessWecomMessage('test_user', '帮我创建会议', 'stream_progress_001', 'https://example.com/callback');
    $job->handle($mockChat);

    // 验证最终 Cache 状态
    $cached = Cache::get('wecom_stream:stream_progress_001');
    expect($cached['finish'])->toBeTrue();
    expect($cached['content'])->toBe('会议创建成功');
});

test('ProcessWecomMessage Job 失败时写入 error 到 Cache', function () {
    $mockChat = Mockery::mock(ChatService::class);
    $mockChat->shouldReceive('chat')
        ->once()
        ->andThrow(new RuntimeException('AI 服务异常'));

    // Mock WecomMessageClient 兜底推送
    $mockMessage = Mockery::mock(WecomMessageClient::class);
    $mockMessage->shouldReceive('sendText')
        ->with('test_user', '抱歉，消息处理失败，请稍后重试。')
        ->once();
    app()->instance(WecomMessageClient::class, $mockMessage);

    $job = new ProcessWecomMessage('test_user', '你好', 'stream_fail_001', 'https://example.com/callback');
    $job->handle($mockChat);

    // 验证 Cache 写入错误状态
    $cached = Cache::get('wecom_stream:stream_fail_001');
    expect($cached['finish'])->toBeTrue();
    expect($cached['error'])->toBe('抱歉，消息处理失败，请稍后重试。');
});

test('ProcessWecomMessage Job 可推入队列', function () {
    Queue::fake();

    ProcessWecomMessage::dispatch('test_user', '你好', 'stream_queue_001', 'https://example.com/callback');

    Queue::assertPushed(ProcessWecomMessage::class, function ($job) {
        return $job->userId === 'test_user'
            && $job->content === '你好'
            && $job->streamId === 'stream_queue_001'
            && $job->responseUrl === 'https://example.com/callback';
    });
});

test('ProcessWecomMessage Job 配置正确', function () {
    $job = new ProcessWecomMessage('test_user', '你好', 'stream_config_001', 'https://example.com/callback');

    expect($job->tries)->toBe(1);
    expect($job->timeout)->toBe(120);
});

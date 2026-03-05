<?php

use App\Jobs\ProcessWecomMessage;
use App\Models\WecomBotMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * 创建 Mock WecomApp + Server，捕获 with() 注册的 handler 并在 serve() 时执行
 * 绕过加解密，直接测试 handler 逻辑
 *
 * @param  \EasyWeChat\Work\Message  $message  测试消息对象
 */
function mockWecomServer(\EasyWeChat\Work\Message $message): void
{
    $handler = null;

    $mockServer = Mockery::mock(\EasyWeChat\Work\Server::class);
    $mockServer->shouldReceive('with')->once()->andReturnUsing(function ($callback) use ($mockServer, &$handler) {
        $handler = $callback;

        return $mockServer;
    });
    $mockServer->shouldReceive('serve')->once()->andReturnUsing(function () use (&$handler, $message) {
        $result = $handler($message, fn ($m) => null);
        $body = $result ? json_encode($result, JSON_UNESCAPED_UNICODE) : 'success';

        return new \Nyholm\Psr7\Response(200, [], $body);
    });

    $mockApp = Mockery::mock(\EasyWeChat\Work\Application::class);
    $mockApp->shouldReceive('setRequestFromSymfonyRequest')->once();
    $mockApp->shouldReceive('getServer')->with('json')->once()->andReturn($mockServer);

    app()->instance('wecom.bot', $mockApp);
}

test('文本消息返回 stream 响应并派发 Job', function () {
    Queue::fake();

    $message = new \EasyWeChat\Work\Message([
        'msgtype' => 'text',
        'msgid' => 'msg_text_001',
        'text' => ['content' => '帮我创建会议'],
        'from' => ['userid' => 'user001'],
        'aibotid' => 'bot001',
        'chattype' => 'single',
        'response_url' => 'https://example.com/callback',
    ]);

    mockWecomServer($message);

    $response = $this->post('/wecom/callback');

    $response->assertStatus(200);

    $json = json_decode($response->getContent(), true);
    expect($json['msgtype'])->toBe('stream');
    expect($json['stream']['finish'])->toBeFalse();
    expect($json['stream']['content'])->toBe('正在思考中...');
    expect($json['stream']['id'])->not->toBeEmpty();

    // 验证 Job 已派发
    Queue::assertPushed(ProcessWecomMessage::class, function ($job) {
        return $job->userId === 'user001'
            && $job->content === '帮我创建会议'
            && $job->streamId !== ''
            && $job->responseUrl === 'https://example.com/callback';
    });

    // 验证 DB 记录
    $this->assertDatabaseHas('wecom_bot_messages', [
        'msgid' => 'msg_text_001',
        'userid' => 'user001',
        'msgtype' => 'text',
        'content' => '帮我创建会议',
        'aibotid' => 'bot001',
    ]);
});

test('语音消息返回 stream 响应并派发 Job', function () {
    Queue::fake();

    $message = new \EasyWeChat\Work\Message([
        'msgtype' => 'voice',
        'msgid' => 'msg_voice_001',
        'voice' => ['content' => '帮我查一下张三的联系方式'],
        'from' => ['userid' => 'user002'],
        'aibotid' => 'bot001',
        'chattype' => 'single',
        'response_url' => 'https://example.com/callback',
    ]);

    mockWecomServer($message);

    $response = $this->post('/wecom/callback');

    $response->assertStatus(200);

    $json = json_decode($response->getContent(), true);
    expect($json['msgtype'])->toBe('stream');
    expect($json['stream']['finish'])->toBeFalse();

    Queue::assertPushed(ProcessWecomMessage::class, function ($job) {
        return $job->userId === 'user002'
            && $job->content === '帮我查一下张三的联系方式';
    });

    $this->assertDatabaseHas('wecom_bot_messages', [
        'msgid' => 'msg_voice_001',
        'msgtype' => 'voice',
        'content' => '帮我查一下张三的联系方式',
    ]);
});

test('stream 刷新返回缓存状态（pending）', function () {
    $streamId = 'test_stream_pending';

    Cache::put("wecom_stream:{$streamId}", [
        'finish' => false,
        'content' => '正在思考中...',
    ], 600);

    $message = new \EasyWeChat\Work\Message([
        'msgtype' => 'stream',
        'stream' => ['id' => $streamId],
    ]);

    mockWecomServer($message);

    $response = $this->post('/wecom/callback');

    $json = json_decode($response->getContent(), true);
    expect($json['stream']['id'])->toBe($streamId);
    expect($json['stream']['finish'])->toBeFalse();
    expect($json['stream']['content'])->toBe('正在思考中...');
});

test('stream 刷新返回缓存状态（done）', function () {
    $streamId = 'test_stream_done';

    Cache::put("wecom_stream:{$streamId}", [
        'finish' => true,
        'content' => '会议已创建成功！',
    ], 600);

    $message = new \EasyWeChat\Work\Message([
        'msgtype' => 'stream',
        'stream' => ['id' => $streamId],
    ]);

    mockWecomServer($message);

    $response = $this->post('/wecom/callback');

    $json = json_decode($response->getContent(), true);
    expect($json['stream']['id'])->toBe($streamId);
    expect($json['stream']['finish'])->toBeTrue();
    expect($json['stream']['content'])->toBe('会议已创建成功！');
});

test('stream 缓存过期返回会话已过期', function () {
    $streamId = 'test_stream_expired';

    // 不写入 Cache，模拟过期

    $message = new \EasyWeChat\Work\Message([
        'msgtype' => 'stream',
        'stream' => ['id' => $streamId],
    ]);

    mockWecomServer($message);

    $response = $this->post('/wecom/callback');

    $json = json_decode($response->getContent(), true);
    expect($json['stream']['id'])->toBe($streamId);
    expect($json['stream']['finish'])->toBeTrue();
    expect($json['stream']['content'])->toBe('会话已过期，请重新发送消息。');
});

test('MsgId 去重返回已有 stream 状态', function () {
    Queue::fake();

    // 先创建已有记录
    $existingStreamId = 'existing_stream_001';
    WecomBotMessage::create([
        'msgid' => 'msg_dup_001',
        'aibotid' => 'bot001',
        'chattype' => 'single',
        'userid' => 'user001',
        'msgtype' => 'text',
        'content' => '你好',
        'stream_id' => $existingStreamId,
        'response_url' => 'https://example.com/callback',
    ]);

    // 模拟已有 cache
    Cache::put("wecom_stream:{$existingStreamId}", [
        'finish' => false,
        'content' => '正在思考中...',
    ], 600);

    // 模拟去重 cache
    Cache::put('wecom_msg:msg_dup_001', true, 300);

    $message = new \EasyWeChat\Work\Message([
        'msgtype' => 'text',
        'msgid' => 'msg_dup_001',
        'text' => ['content' => '你好'],
        'from' => ['userid' => 'user001'],
        'aibotid' => 'bot001',
        'chattype' => 'single',
        'response_url' => 'https://example.com/callback',
    ]);

    mockWecomServer($message);

    $response = $this->post('/wecom/callback');

    $json = json_decode($response->getContent(), true);
    expect($json['stream']['id'])->toBe($existingStreamId);
    expect($json['stream']['finish'])->toBeFalse();

    // 不应重复派发 Job
    Queue::assertNotPushed(ProcessWecomMessage::class);
});

test('非文本/语音/stream 消息返回 success', function () {
    Queue::fake();

    $message = new \EasyWeChat\Work\Message([
        'msgtype' => 'image',
        'msgid' => 'msg_image_001',
        'from' => ['userid' => 'user001'],
    ]);

    mockWecomServer($message);

    $response = $this->post('/wecom/callback');

    $response->assertStatus(200);
    expect($response->getContent())->toBe('success');

    Queue::assertNotPushed(ProcessWecomMessage::class);
});

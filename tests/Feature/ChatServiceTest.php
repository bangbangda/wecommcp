<?php

use App\Ai\Contracts\AiDriver;
use App\Ai\Dto\AiResponse;
use App\Ai\Dto\ToolCall;
use App\Models\Contact;
use App\Models\UserMemory;
use App\Services\ChatService;
use App\Services\ContactsService;
use App\Wecom\WecomMeetingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $service = new ContactsService;
    $contacts = [
        ['userid' => 'liming', 'name' => '李明', 'department' => '技术部', 'position' => '前端开发'],
        ['userid' => 'zhangsan', 'name' => '张三', 'department' => '市场部', 'position' => '市场总监'],
    ];
    foreach ($contacts as $data) {
        $pinyin = $service->generatePinyin($data['name']);
        Contact::create(array_merge($data, [
            'name_pinyin' => $pinyin['pinyin'],
            'name_initials' => $pinyin['initials'],
        ]));
    }
});

test('AI 返回纯文本时直接返回', function () {
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')->once()->andReturn(new AiResponse(
        text: '你好！有什么可以帮你的？',
        toolCalls: [],
        wantsTool: false,
        rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '你好！有什么可以帮你的？']]],
    ));

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);

    $reply = $chat->chat('user1', '你好');
    expect($reply)->toBe('你好！有什么可以帮你的？');
});

test('AI 调用 tool 后返回最终文本', function () {
    $mockDriver = Mockery::mock(AiDriver::class);

    // 第一次调用：AI 返回 tool_use
    $mockDriver->shouldReceive('chat')->once()->andReturn(new AiResponse(
        text: '',
        toolCalls: [new ToolCall(id: 'call_1', name: 'search_contacts', input: ['name' => '李明'])],
        wantsTool: true,
        rawAssistantMessage: ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'search_contacts', 'input' => ['name' => '李明']],
        ]],
    ));

    // 第二次调用：AI 拿到 tool 结果后返回文本
    $mockDriver->shouldReceive('chat')->once()->andReturn(new AiResponse(
        text: '找到了李明，技术部前端开发。',
        toolCalls: [],
        wantsTool: false,
        rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '找到了李明，技术部前端开发。']]],
    ));

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);

    $reply = $chat->chat('user1', '搜索李明');
    expect($reply)->toBe('找到了李明，技术部前端开发。');
});

test('AI 服务不可用时返回友好提示', function () {
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')->once()->andReturnNull();

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);

    $reply = $chat->chat('user1', '你好');
    expect($reply)->toContain('不可用');
});

test('clearHistory 清除 Cache 中的对话历史后重新开始', function () {
    $messagesCaptured = [];
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')->andReturnUsing(function ($system, $messages) use (&$messagesCaptured) {
        $messagesCaptured = $messages;

        return new AiResponse(
            text: '消息数:'.count($messages),
            toolCalls: [],
            wantsTool: false,
            rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '消息数:'.count($messages)]]],
        );
    });

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);

    // 第一次对话：1条 user 消息
    $chat->chat('user1', '第一条');
    expect($messagesCaptured)->toHaveCount(1);

    // 第二次对话（不清除）：user + assistant + user = 3条
    $chat->chat('user1', '第二条');
    expect($messagesCaptured)->toHaveCount(3);

    // 清除后重新对话：应该只有1条 user 消息
    $chat->clearHistory('user1');
    $chat->chat('user1', '清除后的消息');
    expect($messagesCaptured)->toHaveCount(1);
    expect($messagesCaptured[0]['content'])->toBe('清除后的消息');

    // 验证 Cache key 被清除
    expect(\Illuminate\Support\Facades\Cache::get('chat_history:user1'))->not->toBeNull();
    $chat->clearHistory('user1');
    expect(\Illuminate\Support\Facades\Cache::get('chat_history:user1'))->toBeNull();
});

test('跨实例对话历史通过 Cache 持久化', function () {
    $messagesCaptured = [];
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')->andReturnUsing(function ($system, $messages) use (&$messagesCaptured) {
        $messagesCaptured = $messages;

        return new AiResponse(
            text: '回复:'.count($messages),
            toolCalls: [],
            wantsTool: false,
            rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '回复:'.count($messages)]]],
        );
    });

    $this->app->instance(AiDriver::class, $mockDriver);

    // 第一个实例发送消息
    $chat1 = app(ChatService::class);
    $chat1->chat('user1', '你好');
    expect($messagesCaptured)->toHaveCount(1);

    // 创建新实例（模拟不同 Job），对话历史应从 Cache 恢复
    $chat2 = app(ChatService::class);
    $chat2->chat('user1', '产品评审会');
    expect($messagesCaptured)->toHaveCount(3); // user + assistant + user
    expect($messagesCaptured[0]['content'])->toBe('你好');
    expect($messagesCaptured[2]['content'])->toBe('产品评审会');
});

test('滑动窗口裁剪超过 MAX_MESSAGES 时保留最新消息且首条为 user', function () {
    // 预填充超过 20 条消息到 Cache
    $messages = [];
    for ($i = 0; $i < 12; $i++) {
        $messages[] = ['role' => 'user', 'content' => "消息{$i}"];
        $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => "回复{$i}"]]];
    }
    // 24 条消息，超过 MAX_MESSAGES(20)
    \Illuminate\Support\Facades\Cache::put('chat_history:user1', $messages, 7200);

    $messagesCaptured = [];
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')->andReturnUsing(function ($system, $messages) use (&$messagesCaptured) {
        $messagesCaptured = $messages;

        return new AiResponse(
            text: 'ok',
            toolCalls: [],
            wantsTool: false,
            rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ok']]],
        );
    });

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);
    $chat->chat('user1', '新消息');

    // 加载 24 条 + 1 条新 user = 25 条，发送给 AI 时是 25 条
    // 保存后裁剪为 ≤20 条
    $saved = \Illuminate\Support\Facades\Cache::get('chat_history:user1');
    expect(count($saved))->toBeLessThanOrEqual(20);

    // 首条必须是 user 角色
    expect($saved[0]['role'])->toBe('user');

    // 最后两条应是最新的 user 消息和 assistant 回复
    $lastTwo = array_slice($saved, -2);
    expect($lastTwo[0]['content'])->toBe('新消息');
    expect($lastTwo[1]['role'])->toBe('assistant');
});

test('AI 调用 create_meeting tool 实际写入数据库', function () {
    // Mock WecomMeetingClient，避免真实 API 调用
    $mockWecom = Mockery::mock(WecomMeetingClient::class);
    $mockWecom->shouldReceive('createMeeting')->andReturn([
        'errcode' => 0,
        'errmsg' => 'ok',
        'meetingid' => 'mock_meeting_001',
    ]);
    app()->instance(WecomMeetingClient::class, $mockWecom);

    $futureTime = now()->addDay()->setHour(9)->setMinute(0)->setSecond(0)->toIso8601String();
    $mockDriver = Mockery::mock(AiDriver::class);

    // AI 返回 create_meeting tool_use
    $mockDriver->shouldReceive('chat')->once()->andReturn(new AiResponse(
        text: '',
        toolCalls: [new ToolCall(id: 'call_m1', name: 'create_meeting', input: [
            'title' => '站会',
            'start_time' => $futureTime,
            'duration_minutes' => 30,
            'invitees' => ['李明'],
        ])],
        wantsTool: true,
        rawAssistantMessage: ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'call_m1', 'name' => 'create_meeting', 'input' => [
                'title' => '站会', 'start_time' => $futureTime, 'duration_minutes' => 30, 'invitees' => ['李明'],
            ]],
        ]],
    ));

    // AI 拿到结果后返回确认文本
    $mockDriver->shouldReceive('chat')->once()->andReturn(new AiResponse(
        text: '站会已创建成功。',
        toolCalls: [],
        wantsTool: false,
        rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => '站会已创建成功。']]],
    ));

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);

    $reply = $chat->chat('user1', '创建一个站会');
    expect($reply)->toBe('站会已创建成功。');

    $this->assertDatabaseHas('recent_meetings', [
        'title' => '站会',
        'duration_minutes' => 30,
    ]);
});

test('无记忆时 system prompt 不含记忆块', function () {
    $systemCaptured = '';
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')->andReturnUsing(function ($system) use (&$systemCaptured) {
        $systemCaptured = $system;

        return new AiResponse(
            text: 'ok',
            toolCalls: [],
            wantsTool: false,
            rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ok']]],
        );
    });

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);
    $chat->chat('user1', '你好');

    expect($systemCaptured)->not->toContain('## 用户记忆');
});

test('有记忆时 system prompt 包含 [Mn] 标签', function () {
    $m1 = UserMemory::create([
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
    ]);

    $systemCaptured = '';
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')->andReturnUsing(function ($system) use (&$systemCaptured) {
        $systemCaptured = $system;

        return new AiResponse(
            text: 'ok',
            toolCalls: [],
            wantsTool: false,
            rawAssistantMessage: ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ok']]],
        );
    });

    $this->app->instance(AiDriver::class, $mockDriver);
    $chat = app(ChatService::class);
    $chat->chat('user1', '你好');

    expect($systemCaptured)
        ->toContain('## 用户记忆')
        ->toContain('### 用户偏好')
        ->toContain("[M{$m1->id}] 默认会议时长 30 分钟");
});

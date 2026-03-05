<?php

use App\Ai\Drivers\OpenAiCompatibleDriver;
use Illuminate\Support\Facades\Http;

// === 消息格式转换 ===

test('convertMessages 将 system prompt 转为 system message', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');
    $driver->chat('你是助手', [['role' => 'user', 'content' => '你好']]);

    Http::assertSent(function ($request) {
        $messages = $request->data()['messages'];

        return $messages[0]['role'] === 'system'
            && $messages[0]['content'] === '你是助手'
            && $messages[1]['role'] === 'user'
            && $messages[1]['content'] === '你好';
    });
});

test('convertMessages 将 tool_result 转为 role:tool', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');

    $messages = [
        ['role' => 'user', 'content' => '搜索李明'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'search', 'input' => ['name' => '李明']],
        ]],
        ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{"found": true}'],
        ]],
    ];

    $driver->chat('系统提示', $messages);

    Http::assertSent(function ($request) {
        $msgs = $request->data()['messages'];
        // system, user, assistant(with tool_calls), tool
        $toolMsg = collect($msgs)->firstWhere('role', 'tool');

        return $toolMsg !== null
            && $toolMsg['tool_call_id'] === 'call_1'
            && $toolMsg['content'] === '{"found": true}';
    });
});

test('convertMessages 将 assistant tool_use 转为 tool_calls', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');

    $messages = [
        ['role' => 'user', 'content' => '搜索'],
        ['role' => 'assistant', 'content' => [
            ['type' => 'text', 'text' => '正在搜索'],
            ['type' => 'tool_use', 'id' => 'tc_1', 'name' => 'search_contacts', 'input' => ['name' => '张三']],
        ]],
    ];

    $driver->chat('系统', $messages);

    Http::assertSent(function ($request) {
        $msgs = $request->data()['messages'];
        $assistant = collect($msgs)->firstWhere('role', 'assistant');

        return $assistant['content'] === '正在搜索'
            && $assistant['tool_calls'][0]['id'] === 'tc_1'
            && $assistant['tool_calls'][0]['type'] === 'function'
            && $assistant['tool_calls'][0]['function']['name'] === 'search_contacts';
    });
});

// === 工具定义格式转换 ===

test('convertToolDefinitions 将 Claude 格式转为 OpenAI 格式', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');

    $tools = [
        [
            'name' => 'search_contacts',
            'description' => '搜索联系人',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => '姓名'],
                ],
                'required' => ['name'],
            ],
        ],
    ];

    $driver->chat('系统', [['role' => 'user', 'content' => '测试']], $tools);

    Http::assertSent(function ($request) {
        $tools = $request->data()['tools'];

        return $tools[0]['type'] === 'function'
            && $tools[0]['function']['name'] === 'search_contacts'
            && $tools[0]['function']['description'] === '搜索联系人'
            && $tools[0]['function']['parameters']['type'] === 'object';
    });
});

// === 响应解析 ===

test('parseResponse 解析纯文本响应', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => '你好！'],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');
    $response = $driver->chat('系统', [['role' => 'user', 'content' => '你好']]);

    expect($response->text)->toBe('你好！');
    expect($response->hasToolCalls())->toBeFalse();
    expect($response->wantsTool)->toBeFalse();
    expect($response->rawAssistantMessage['role'])->toBe('assistant');
    expect($response->rawAssistantMessage['content'][0]['type'])->toBe('text');
});

test('parseResponse 解析 tool_calls 响应', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_abc',
                        'type' => 'function',
                        'function' => [
                            'name' => 'search_contacts',
                            'arguments' => '{"name":"李明"}',
                        ],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');
    $response = $driver->chat('系统', [['role' => 'user', 'content' => '搜索李明']]);

    expect($response->hasToolCalls())->toBeTrue();
    expect($response->wantsTool)->toBeTrue();
    expect($response->toolCalls)->toHaveCount(1);
    expect($response->toolCalls[0]->name)->toBe('search_contacts');
    expect($response->toolCalls[0]->input)->toBe(['name' => '李明']);
    expect($response->toolCalls[0]->id)->toBe('call_abc');

    // rawAssistantMessage 应为 Claude 内部格式
    $raw = $response->rawAssistantMessage;
    expect($raw['role'])->toBe('assistant');
    expect($raw['content'][0]['type'])->toBe('tool_use');
    expect($raw['content'][0]['name'])->toBe('search_contacts');
});

// === 思考标签过滤 ===

test('stripThinkingTags 去除 qwen3 思考标签', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => '<think>让我想想...</think>你好！有什么可以帮你的？',
                ],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');
    $response = $driver->chat('系统', [['role' => 'user', 'content' => '你好']]);

    expect($response->text)->toBe('你好！有什么可以帮你的？');
});

test('stripThinkingTags 处理多行思考内容', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => "<think>\n用户想搜索联系人\n我应该调用工具\n</think>好的，我来帮你搜索。",
                ],
                'finish_reason' => 'stop',
            ]],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');
    $response = $driver->chat('系统', [['role' => 'user', 'content' => '搜索']]);

    expect($response->text)->toBe('好的，我来帮你搜索。');
});

// === API 错误处理 ===

test('API 请求失败时返回 null', function () {
    Http::fake([
        '*/chat/completions' => Http::response('Internal Server Error', 500),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://fake/v1', model: 'test');
    $response = $driver->chat('系统', [['role' => 'user', 'content' => '你好']]);

    expect($response)->toBeNull();
});

// === 无 API key 时不发送 Authorization 头 ===

test('Ollama 场景不发送 Authorization 头', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://localhost:11434/v1', model: 'qwen3:8b', apiKey: '');
    $driver->chat('系统', [['role' => 'user', 'content' => '测试']]);

    Http::assertSent(function ($request) {
        return ! $request->hasHeader('Authorization');
    });
});

test('有 API key 时发送 Bearer token', function () {
    Http::fake([
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ]),
    ]);

    $driver = new OpenAiCompatibleDriver(baseUrl: 'http://api.example.com/v1', model: 'gpt-4o', apiKey: 'sk-test123');
    $driver->chat('系统', [['role' => 'user', 'content' => '测试']]);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer sk-test123');
    });
});

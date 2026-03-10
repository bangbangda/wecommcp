<?php

use App\Mcp\Tools\GroupChat\SendGroupMessageTool;

test('stripMentionText 移除 @人名（有空格）', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    $result = $method->invoke($tool, '@robot 请明天上午准备好演示环境', ['robot']);

    expect($result)->toBe('请明天上午准备好演示环境');
});

test('stripMentionText 移除 @人名（无空格）', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    $result = $method->invoke($tool, '@robot请明天上午准备好演示环境', ['robot']);

    expect($result)->toBe('请明天上午准备好演示环境');
});

test('stripMentionText 移除 @中文人名（无空格）', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    $result = $method->invoke($tool, '@张三请明天上午准备好演示环境', ['张三']);

    expect($result)->toBe('请明天上午准备好演示环境');
});

test('stripMentionText @在句中（有空格）', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    $result = $method->invoke($tool, '请 @张三 明天上午准备好演示环境', ['张三']);

    expect($result)->toBe('请 明天上午准备好演示环境');
});

test('stripMentionText @在句中（无空格）', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    $result = $method->invoke($tool, '请@张三明天上午准备好演示环境', ['张三']);

    expect($result)->toBe('请明天上午准备好演示环境');
});

test('stripMentionText 多个@人名', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    $result = $method->invoke($tool, '@张三 @李四 请明天上午准备好演示环境', ['张三', '李四']);

    expect($result)->toBe('请明天上午准备好演示环境');
});

test('stripMentionText @所有人不处理', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    // "所有人" 不在 mentionedNames 中传入（在 buildTextMessage 中已映射为 @all）
    $result = $method->invoke($tool, '@所有人 请注意明天的会议', []);

    expect($result)->toBe('@所有人 请注意明天的会议');
});

test('stripMentionText @在句尾', function () {
    $tool = app(SendGroupMessageTool::class);
    $method = new ReflectionMethod($tool, 'stripMentionText');

    $result = $method->invoke($tool, '请明天上午准备好演示环境 @robot', ['robot']);

    expect($result)->toBe('请明天上午准备好演示环境');
});

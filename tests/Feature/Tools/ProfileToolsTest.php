<?php

use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

test('set_profile 设置 bot_name 成功', function () {
    $tool = app(\App\Mcp\Tools\Profile\SetProfileTool::class);
    $request = new Request([
        'field' => 'bot_name',
        'value' => '小微',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('success')
        ->and($result['value'])->toBe('小微');

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => 'user1',
        'bot_name' => '小微',
    ]);
});

test('set_profile 无效字段返回验证错误', function () {
    $tool = app(\App\Mcp\Tools\Profile\SetProfileTool::class);
    $request = new Request([
        'field' => 'invalid_field',
        'value' => '测试',
    ]);

    app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
})->throws(\Illuminate\Validation\ValidationException::class);

test('get_profile 返回当前配置', function () {
    UserProfile::create([
        'user_id' => 'user1',
        'bot_name' => '小微',
        'user_nickname' => '老板',
    ]);

    $tool = app(\App\Mcp\Tools\Profile\GetProfileTool::class);
    $request = new Request([]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('success')
        ->and($result['profile'])->toHaveKey('机器人昵称', '小微')
        ->and($result['profile'])->toHaveKey('用户称呼', '老板');
});

test('get_profile 无配置时返回 empty', function () {
    $tool = app(\App\Mcp\Tools\Profile\GetProfileTool::class);
    $request = new Request([]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('empty');
});

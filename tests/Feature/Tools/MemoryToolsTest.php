<?php

use App\Models\UserMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;

uses(RefreshDatabase::class);

test('save_memory 保存成功', function () {
    $tool = app(\App\Mcp\Tools\Memory\SaveMemoryTool::class);
    $request = new Request([
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
    ]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('created')
        ->and($result)->toHaveKey('memory_id');

    $this->assertDatabaseHas('user_memories', [
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
    ]);
});

test('save_memory 无效模块抛出验证异常', function () {
    $tool = app(\App\Mcp\Tools\Memory\SaveMemoryTool::class);
    $request = new Request([
        'module' => 'invalid',
        'content' => '测试',
    ]);

    app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
})->throws(\Illuminate\Validation\ValidationException::class);

test('delete_memory 删除成功', function () {
    $memory = UserMemory::create([
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
    ]);

    $tool = app(\App\Mcp\Tools\Memory\DeleteMemoryTool::class);
    $request = new Request(['memory_id' => $memory->id]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('deleted');
    $this->assertSoftDeleted('user_memories', ['id' => $memory->id]);
});

test('delete_memory 不存在返回 not_found', function () {
    $tool = app(\App\Mcp\Tools\Memory\DeleteMemoryTool::class);
    $request = new Request(['memory_id' => 9999]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('not_found');
});

test('delete_memory 跨用户隔离', function () {
    $memory = UserMemory::create([
        'user_id' => 'user2',
        'module' => 'preferences',
        'content' => '其他用户的记忆',
    ]);

    $tool = app(\App\Mcp\Tools\Memory\DeleteMemoryTool::class);
    $request = new Request(['memory_id' => $memory->id]);

    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => 'user1']);
    $result = json_decode($response->content(), true);

    expect($result['status'])->toBe('not_found');
    $this->assertDatabaseHas('user_memories', ['id' => $memory->id, 'deleted_at' => null]);
});

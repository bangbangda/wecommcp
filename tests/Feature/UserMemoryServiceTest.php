<?php

use App\Models\UserMemory;
use App\Services\UserMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('save 创建新记忆', function () {
    $service = new UserMemoryService;

    $result = $service->save('user1', 'preferences', '默认会议时长 30 分钟');

    expect($result['status'])->toBe('created')
        ->and($result)->toHaveKey('memory_id');

    $this->assertDatabaseHas('user_memories', [
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
        'source' => 'explicit',
    ]);
});

test('save 更新相似记忆而非重复创建', function () {
    $service = new UserMemoryService;

    $service->save('user1', 'preferences', '默认会议时长 30 分钟');
    $result = $service->save('user1', 'preferences', '默认会议时长 45 分钟');

    expect($result['status'])->toBe('updated');
    expect(UserMemory::where('user_id', 'user1')->count())->toBe(1);

    $this->assertDatabaseHas('user_memories', [
        'content' => '默认会议时长 45 分钟',
    ]);
});

test('save 不同模块不去重', function () {
    $service = new UserMemoryService;

    $service->save('user1', 'preferences', '默认会议时长 30 分钟');
    $service->save('user1', 'general', '默认会议时长 30 分钟');

    expect(UserMemory::where('user_id', 'user1')->count())->toBe(2);
});

test('save 超出上限时返回错误', function () {
    $service = new UserMemoryService;

    for ($i = 0; $i < UserMemoryService::MAX_MEMORIES_PER_USER; $i++) {
        UserMemory::create([
            'user_id' => 'user1',
            'module' => 'general',
            'content' => "记忆 {$i}",
        ]);
    }

    $result = $service->save('user1', 'general', '超出上限的记忆');

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('上限');
});

test('save 无效模块返回错误', function () {
    $service = new UserMemoryService;

    $result = $service->save('user1', 'invalid_module', '测试');

    expect($result['status'])->toBe('error')
        ->and($result['message'])->toContain('无效');
});

test('delete 成功删除记忆（软删除）', function () {
    $service = new UserMemoryService;

    $memory = UserMemory::create([
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
    ]);

    $result = $service->delete('user1', $memory->id);

    expect($result['status'])->toBe('deleted');
    $this->assertSoftDeleted('user_memories', ['id' => $memory->id]);
});

test('delete 不存在的记忆返回 not_found', function () {
    $service = new UserMemoryService;

    $result = $service->delete('user1', 9999);

    expect($result['status'])->toBe('not_found');
});

test('delete 不能删除其他用户的记忆', function () {
    $service = new UserMemoryService;

    $memory = UserMemory::create([
        'user_id' => 'user2',
        'module' => 'preferences',
        'content' => '其他用户的记忆',
    ]);

    $result = $service->delete('user1', $memory->id);

    expect($result['status'])->toBe('not_found');
    $this->assertDatabaseHas('user_memories', ['id' => $memory->id, 'deleted_at' => null]);
});

test('formatForPrompt 无记忆时返回空字符串', function () {
    $service = new UserMemoryService;

    $result = $service->formatForPrompt('user1');

    expect($result)->toBe('');
});

test('formatForPrompt 有记忆时返回带 [Mn] 标签的格式化文本', function () {
    $service = new UserMemoryService;

    $m1 = UserMemory::create([
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
    ]);

    $m2 = UserMemory::create([
        'user_id' => 'user1',
        'module' => 'relationships',
        'content' => '张三是直属上级',
    ]);

    $result = $service->formatForPrompt('user1');

    expect($result)
        ->toContain('## 用户记忆')
        ->toContain('### 用户偏好')
        ->toContain("[M{$m1->id}] 默认会议时长 30 分钟")
        ->toContain('### 人际关系')
        ->toContain("[M{$m2->id}] 张三是直属上级");
});

test('formatForPrompt 超出字符限制时截断', function () {
    $service = new UserMemoryService;

    // 创建足够多的记忆来超出限制
    for ($i = 0; $i < 50; $i++) {
        UserMemory::create([
            'user_id' => 'user1',
            'module' => 'general',
            'content' => str_repeat('这是一条较长的记忆内容用于测试截断', 3)." - {$i}",
        ]);
    }

    $result = $service->formatForPrompt('user1');

    expect(mb_strlen($result))->toBeLessThanOrEqual(UserMemoryService::MAX_PROMPT_CHARS + 100);
});

test('formatForPrompt 更新 hit_count 和 last_hit_at', function () {
    $service = new UserMemoryService;

    $memory = UserMemory::create([
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => '默认会议时长 30 分钟',
    ]);

    $memory->refresh();
    expect($memory->hit_count)->toBe(0);

    $service->formatForPrompt('user1');
    $memory->refresh();

    expect($memory->hit_count)->toBe(1)
        ->and($memory->last_hit_at)->not->toBeNull();
});

test('用户间记忆完全隔离', function () {
    $service = new UserMemoryService;

    UserMemory::create([
        'user_id' => 'user1',
        'module' => 'preferences',
        'content' => 'user1 的偏好',
    ]);

    UserMemory::create([
        'user_id' => 'user2',
        'module' => 'preferences',
        'content' => 'user2 的偏好',
    ]);

    $user1Memories = $service->getByUser('user1');
    $user2Memories = $service->getByUser('user2');

    expect($user1Memories->flatten()->pluck('content')->toArray())->toBe(['user1 的偏好']);
    expect($user2Memories->flatten()->pluck('content')->toArray())->toBe(['user2 的偏好']);

    $prompt1 = $service->formatForPrompt('user1');
    expect($prompt1)->toContain('user1 的偏好')->not->toContain('user2 的偏好');
});

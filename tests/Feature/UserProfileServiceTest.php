<?php

use App\Ai\Contracts\AiDriver;
use App\Ai\Dto\AiResponse;
use App\Models\UserProfile;
use App\Services\UserProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('首次设置单个字段自动创建 profile', function () {
    $service = app(UserProfileService::class);

    $result = $service->updateField('user1', 'bot_name', '小微');

    expect($result['status'])->toBe('success')
        ->and($result['field'])->toBe('bot_name')
        ->and($result['value'])->toBe('小微');

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => 'user1',
        'bot_name' => '小微',
    ]);
});

test('更新已有字段', function () {
    UserProfile::create(['user_id' => 'user1', 'bot_name' => '小微']);

    $service = app(UserProfileService::class);
    $result = $service->updateField('user1', 'bot_name', '小助手');

    expect($result['status'])->toBe('success')
        ->and($result['value'])->toBe('小助手');

    $this->assertDatabaseHas('user_profiles', [
        'user_id' => 'user1',
        'bot_name' => '小助手',
    ]);

    // 仍然只有一行记录
    expect(UserProfile::where('user_id', 'user1')->count())->toBe(1);
});

test('无效字段返回错误', function () {
    $service = app(UserProfileService::class);
    $result = $service->updateField('user1', 'invalid_field', '值');

    expect($result['status'])->toBe('error');
});

test('获取不存在的 profile 返回 null', function () {
    $service = app(UserProfileService::class);

    expect($service->get('nonexistent'))->toBeNull();
});

test('formatForPrompt 无 profile 返回空字符串', function () {
    $service = app(UserProfileService::class);

    expect($service->formatForPrompt('nonexistent'))->toBe('');
});

test('formatForPrompt 有 profile 返回正确格式', function () {
    UserProfile::create([
        'user_id' => 'user1',
        'bot_name' => '小微',
        'user_nickname' => '老板',
        'persona' => '轻松随和的助理，说话不用敬语。',
        'catchphrases' => "确认时说收到\n完成后说搞定了",
        'taboos' => '不要用 emoji',
    ]);

    $service = app(UserProfileService::class);
    $result = $service->formatForPrompt('user1');

    expect($result)
        ->toContain('「小微」')
        ->toContain('老板 的专属企微 AI 助手')
        ->toContain('## 你的性格')
        ->toContain('轻松随和的助理')
        ->toContain('## 常用语')
        ->toContain('- 确认时说收到')
        ->toContain('- 完成后说搞定了')
        ->toContain('## 禁忌')
        ->toContain('- 不要用 emoji');
});

test('formatForPrompt 部分字段为空时只渲染非空字段', function () {
    UserProfile::create([
        'user_id' => 'user1',
        'bot_name' => '小微',
    ]);

    $service = app(UserProfileService::class);
    $result = $service->formatForPrompt('user1');

    expect($result)
        ->toContain('「小微」')
        ->not->toContain('## 你的性格')
        ->not->toContain('## 常用语')
        ->not->toContain('## 禁忌');
});

test('polishPersona 返回润色后文本', function () {
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')
        ->once()
        ->andReturn(new AiResponse(
            text: '你是一个轻松随和的助理，说话不用敬语，语气自然亲切，像朋友聊天一样。',
            toolCalls: [],
            wantsTool: false,
            rawAssistantMessage: [],
        ));

    $service = new UserProfileService($mockDriver);
    $result = $service->polishPersona('轻松点别太正式');

    expect($result)->toBe('你是一个轻松随和的助理，说话不用敬语，语气自然亲切，像朋友聊天一样。');
});

test('polishPersona AI 失败时返回原文', function () {
    $mockDriver = Mockery::mock(AiDriver::class);
    $mockDriver->shouldReceive('chat')
        ->once()
        ->andReturn(null);

    $service = new UserProfileService($mockDriver);
    $result = $service->polishPersona('轻松点');

    expect($result)->toBe('轻松点');
});

test('buildWelcomeMessage 无 profile 返回首次引导欢迎语', function () {
    $service = app(UserProfileService::class);
    $result = $service->buildWelcomeMessage('nonexistent');

    expect($result)
        ->toContain('👋')
        ->toContain('第一次见面')
        ->toContain('给我起个名字')
        ->toContain('会议 & 日程');
});

test('buildWelcomeMessage 无 profile 有通讯录时包含用户姓名', function () {
    \App\Models\Contact::create([
        'userid' => 'user1',
        'name' => '张三',
        'name_pinyin' => 'zhangsan',
        'name_initials' => 'zs',
    ]);

    $service = app(UserProfileService::class);
    $result = $service->buildWelcomeMessage('user1');

    expect($result)
        ->toContain('张三')
        ->toContain('👋');
});

test('buildWelcomeMessage 有 greeting_template 使用模板变量', function () {
    UserProfile::create([
        'user_id' => 'user1',
        'bot_name' => '小微',
        'user_nickname' => '老板',
        'greeting_template' => '{nickname}好！今天是{weekday}，我是小微，随时为您服务！',
    ]);

    $service = app(UserProfileService::class);
    $result = $service->buildWelcomeMessage('user1');

    expect($result)
        ->toContain('老板好！')
        ->toContain('我是小微');
});

test('buildWelcomeMessage 有 profile 无 template 基于昵称生成', function () {
    UserProfile::create([
        'user_id' => 'user1',
        'bot_name' => '小微',
        'user_nickname' => '老板',
    ]);

    $service = app(UserProfileService::class);
    $result = $service->buildWelcomeMessage('user1');

    expect($result)
        ->toContain('老板')
        ->toContain('小微');
});

test('buildWelcomeMessage 有 bot_name 无 nickname', function () {
    UserProfile::create([
        'user_id' => 'user1',
        'bot_name' => '小微',
    ]);

    $service = app(UserProfileService::class);
    $result = $service->buildWelcomeMessage('user1');

    expect($result)
        ->toContain('小微')
        ->not->toContain('null');
});

test('用户隔离', function () {
    UserProfile::create(['user_id' => 'user1', 'bot_name' => '小微']);
    UserProfile::create(['user_id' => 'user2', 'bot_name' => '小助手']);

    $service = app(UserProfileService::class);

    expect($service->get('user1')->bot_name)->toBe('小微')
        ->and($service->get('user2')->bot_name)->toBe('小助手');
});

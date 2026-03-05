<?php

use App\Models\Contact;
use App\Models\RecentMeeting;
use App\Services\ContactsService;
use App\Wecom\WecomMeetingClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $service = new ContactsService;
    $contacts = [
        ['userid' => 'wangwei',  'name' => '王伟', 'department' => '产品部', 'position' => '产品经理'],
        ['userid' => 'wangwei2', 'name' => '汪伟', 'department' => '技术部', 'position' => '后端开发'],
        ['userid' => 'liming',   'name' => '李明', 'department' => '技术部', 'position' => '前端开发'],
        ['userid' => 'zhangsan', 'name' => '张三', 'department' => '市场部', 'position' => '市场总监'],
    ];
    foreach ($contacts as $data) {
        $pinyin = $service->generatePinyin($data['name']);
        Contact::create(array_merge($data, [
            'name_pinyin' => $pinyin['pinyin'],
            'name_initials' => $pinyin['initials'],
        ]));
    }

    // Mock WecomService，避免真实 API 调用
    $mock = Mockery::mock(WecomMeetingClient::class);
    $mock->shouldReceive('createMeeting')->andReturn([
        'meetingid' => 'mock_meeting_001',
        'errcode' => 0,
        'errmsg' => 'ok',
    ]);
    app()->instance(WecomMeetingClient::class, $mock);

    // 未来时间（测试用），使用 Asia/Shanghai 时区，避免硬编码过期时间
    $this->futureTime = now('Asia/Shanghai')->addDay()->setHour(15)->setMinute(0)->setSecond(0)->format('Y-m-d\TH:i:s');
});

function callTool(string $toolClass, array $input, string $userId = 'test_user'): array
{
    $tool = app($toolClass);
    $request = new \Laravel\Mcp\Request($input);
    $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => $userId]);

    return json_decode((string) $response->content(), true);
}

// === CreateMeetingTool ===

test('创建会议成功：参会人唯一匹配', function () {
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '产品评审会',
        'start_time' => $this->futureTime,
        'duration_minutes' => 60,
        'invitees' => ['李明', '张三'],
    ]);

    expect($result['status'])->toBe('success');
    expect($result['title'])->toBe('产品评审会');
    expect($result['duration_minutes'])->toBe(60);
    expect($result['invitees'])->toHaveCount(2);
    expect($result['invitees'][0]['name'])->toBe('李明');
    expect($result['invitees'][1]['name'])->toBe('张三');

    // 验证写入数据库
    $this->assertDatabaseHas('recent_meetings', [
        'title' => '产品评审会',
        'duration_minutes' => 60,
        'creator_userid' => 'test_user',
    ]);
});

test('创建会议：默认时长 60 分钟', function () {
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '站会',
        'start_time' => $this->futureTime,
        'invitees' => ['李明'],
    ]);

    expect($result['status'])->toBe('success');
    expect($result['duration_minutes'])->toBe(60);
});

test('创建会议：无参会人也可创建', function () {
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '个人复盘',
        'start_time' => $this->futureTime,
    ]);

    expect($result['status'])->toBe('success');
    expect($result['invitees'])->toBeEmpty();
});

test('创建会议：同音字歧义返回 need_clarification', function () {
    // "忘伟" 拼音匹配到 王伟+汪伟，产生歧义
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '技术分享会',
        'start_time' => $this->futureTime,
        'invitees' => ['忘伟'],
    ]);

    expect($result['status'])->toBe('need_clarification');
    expect($result['ambiguous'])->toHaveCount(1);
    expect($result['ambiguous'][0]['input'])->toBe('忘伟');
    expect($result['ambiguous'][0]['candidates'])->toHaveCount(2);

    // 不应写入数据库
    expect(RecentMeeting::count())->toBe(0);
});

test('创建会议：部分参会人歧义时返回已解析和歧义列表', function () {
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '项目同步会',
        'start_time' => $this->futureTime,
        'invitees' => ['李明', '忘伟'],
    ]);

    expect($result['status'])->toBe('need_clarification');
    // 李明已解析
    expect($result['resolved'])->toHaveCount(1);
    expect($result['resolved'][0]['name'])->toBe('李明');
    // 忘伟有歧义
    expect($result['ambiguous'])->toHaveCount(1);
    expect($result['ambiguous'][0]['input'])->toBe('忘伟');
});

test('创建会议：参会人不存在时返回 need_clarification', function () {
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '测试会议',
        'start_time' => $this->futureTime,
        'invitees' => ['不存在的人'],
    ]);

    expect($result['status'])->toBe('need_clarification');
    expect($result['ambiguous'][0]['candidates'])->toBeEmpty();
    expect($result['ambiguous'][0]['message'])->toContain('未找到');
});

test('创建会议：时间按 Asia/Shanghai 时区解析', function () {
    // 冻结时间为 CST 2026-02-26 09:00:00（即 UTC 2026-02-26 01:00:00）
    Carbon::setTestNow(Carbon::parse('2026-02-26 01:00:00', 'UTC'));

    // 无时区后缀的 ISO 8601，应按 Asia/Shanghai 解析为 10:00 CST = 02:00 UTC
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '时区测试会议',
        'start_time' => '2026-02-26T10:00:00',
    ]);

    expect($result['status'])->toBe('success');
    expect($result['title'])->toBe('时区测试会议');

    // 验证存入的时间戳确实是 CST 10:00（UTC 02:00）
    $meeting = RecentMeeting::latest()->first();
    $apiRequest = $meeting->api_request;
    $expectedTimestamp = Carbon::parse('2026-02-26 10:00:00', 'Asia/Shanghai')->getTimestamp();
    expect((int) $apiRequest['meeting_start'])->toBe($expectedTimestamp);

    Carbon::setTestNow(); // 恢复
});

test('创建会议：开始时间早于当前时间返回 error', function () {
    $pastTime = now('Asia/Shanghai')->subHour()->format('Y-m-d\TH:i:s');
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '过期会议',
        'start_time' => $pastTime,
    ]);

    expect($result['status'])->toBe('error');
    expect($result['message'])->toContain('大于当前时间');
});

test('创建会议：无法解析的时间格式返回 error', function () {
    $result = callTool(\App\Mcp\Tools\Meeting\CreateMeetingTool::class, [
        'title' => '格式错误会议',
        'start_time' => '不是时间',
    ]);

    expect($result['status'])->toBe('error');
    expect($result['message'])->toContain('无法解析');
});

// === SearchContactsTool ===

test('搜索联系人：精确匹配', function () {
    $result = callTool(\App\Mcp\Tools\Contact\SearchContactsTool::class, [
        'name' => '李明',
    ]);

    expect($result['status'])->toBe('found');
    expect($result['count'])->toBe(1);
    expect($result['contacts'][0]['name'])->toBe('李明');
    expect($result['contacts'][0]['department'])->toBe('技术部');
});

test('搜索联系人：同音字返回多个候选', function () {
    $result = callTool(\App\Mcp\Tools\Contact\SearchContactsTool::class, [
        'name' => '忘伟',
    ]);

    expect($result['status'])->toBe('found');
    expect($result['count'])->toBe(2);
    $names = collect($result['contacts'])->pluck('name')->toArray();
    expect($names)->toContain('王伟', '汪伟');
});

test('搜索联系人：不存在返回 not_found', function () {
    $result = callTool(\App\Mcp\Tools\Contact\SearchContactsTool::class, [
        'name' => '不存在的人',
    ]);

    expect($result['status'])->toBe('not_found');
    expect($result['message'])->toContain('不存在的人');
});

<?php

use App\Models\RecentMeeting;
use App\Wecom\WecomMeetingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock WecomMeetingClient，避免真实 API 调用
    $mock = Mockery::mock(WecomMeetingClient::class);
    $mock->shouldReceive('cancelMeeting')->andReturn([
        'errcode' => 0,
        'errmsg' => 'ok',
    ]);
    $mock->shouldReceive('getMeetingInfo')->andReturn([
        'errcode' => 0,
        'errmsg' => 'ok',
        'meeting_info' => [
            'meetingid' => 'mock_meeting_001',
            'title' => '产品评审会',
            'status' => 1,
        ],
    ]);
    app()->instance(WecomMeetingClient::class, $mock);

    // 预置会议记录
    RecentMeeting::create([
        'title' => '产品评审会',
        'start_time' => '2026-02-26T15:00:00',
        'duration_minutes' => 60,
        'invitees' => [['userid' => 'liming', 'name' => '李明']],
        'creator_userid' => 'current_user',
        'meetingid' => 'mock_meeting_001',
    ]);

    RecentMeeting::create([
        'title' => '技术分享会',
        'start_time' => '2026-02-26T14:00:00',
        'duration_minutes' => 90,
        'invitees' => [['userid' => 'zhangsan', 'name' => '张三']],
        'creator_userid' => 'current_user',
        'meetingid' => 'mock_meeting_002',
    ]);

    RecentMeeting::create([
        'title' => '技术分享会',
        'start_time' => '2026-02-27T14:00:00',
        'duration_minutes' => 60,
        'invitees' => [],
        'creator_userid' => 'current_user',
        'meetingid' => 'mock_meeting_003',
    ]);
});

/**
 * 调用 MCP Tool 并返回解码后的 JSON
 *
 * @param  string  $toolClass  Tool 类名
 * @param  array  $input  工具参数
 * @param  string|null  $userId  当前用户 ID
 */
function callMeetingTool(string $toolClass, array $input, ?string $userId = null): array
{
    $tool = app($toolClass);
    $request = new \Laravel\Mcp\Request($input);
    $params = ['request' => $request];
    if ($userId !== null) {
        $params['userId'] = $userId;
    }
    $response = app()->call([$tool, 'handle'], $params);

    return json_decode((string) $response->content(), true);
}

// === CancelMeetingTool ===

test('取消会议：标题精确匹配 → 成功取消', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\CancelMeetingTool::class, [
        'title' => '产品评审会',
    ]);

    expect($result['status'])->toBe('success');
    expect($result['title'])->toBe('产品评审会');
    expect($result['meetingid'])->toBe('mock_meeting_001');
    expect($result['message'])->toContain('已取消');

    // 验证本地记录已软删除（数据库仍存在但 deleted_at 不为空）
    $this->assertSoftDeleted('recent_meetings', [
        'meetingid' => 'mock_meeting_001',
    ]);
    // Eloquent 默认查询排除已软删除记录
    expect(RecentMeeting::where('meetingid', 'mock_meeting_001')->exists())->toBeFalse();
});

test('取消会议：标题 + 时间匹配 → 成功取消', function () {
    // "技术分享会" 有两条，加时间过滤后唯一匹配
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\CancelMeetingTool::class, [
        'title' => '技术分享会',
        'start_time' => '2026-02-26',
    ]);

    expect($result['status'])->toBe('success');
    expect($result['meetingid'])->toBe('mock_meeting_002');

    // 另一条记录仍在
    $this->assertDatabaseHas('recent_meetings', [
        'meetingid' => 'mock_meeting_003',
    ]);
});

test('取消会议：多条匹配 → need_clarification', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\CancelMeetingTool::class, [
        'title' => '技术分享会',
    ]);

    expect($result['status'])->toBe('need_clarification');
    expect($result['candidates'])->toHaveCount(2);

    // 记录不应被删除
    expect(RecentMeeting::where('title', 'like', '%技术分享会%')->count())->toBe(2);
});

test('取消会议：无匹配 → not_found，建议使用 query_meetings', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\CancelMeetingTool::class, [
        'title' => '不存在的会议',
    ], 'current_user');

    expect($result['status'])->toBe('not_found');
    expect($result['message'])->toContain('query_meetings');
    expect($result)->not->toHaveKey('recent_meetings');
});

test('取消会议：通过 meetingid 精确定位 → 成功取消', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\CancelMeetingTool::class, [
        'meetingid' => 'mock_meeting_002',
    ]);

    expect($result['status'])->toBe('success');
    expect($result['meetingid'])->toBe('mock_meeting_002');
    expect($result['title'])->toBe('技术分享会');

    $this->assertSoftDeleted('recent_meetings', ['meetingid' => 'mock_meeting_002']);
});

test('取消会议：meetingid 不存在 → not_found', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\CancelMeetingTool::class, [
        'meetingid' => 'nonexistent',
    ]);

    expect($result['status'])->toBe('not_found');
});

test('取消会议：无 meetingid 且无 title → error', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\CancelMeetingTool::class, []);

    expect($result['status'])->toBe('error');
    expect($result['message'])->toContain('meetingid');
});

// === GetMeetingInfoTool ===

test('查询会议：匹配 → 返回详情', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\GetMeetingInfoTool::class, [
        'title' => '产品评审会',
    ]);

    expect($result['status'])->toBe('success');
    expect($result['title'])->toBe('产品评审会');
    expect($result['meetingid'])->toBe('mock_meeting_001');
    expect($result['duration_minutes'])->toBe(60);
    expect($result['invitees'])->toHaveCount(1);
    expect($result['api_detail'])->toBeArray();
    expect($result['api_detail']['errcode'])->toBe(0);
});

test('查询会议：无匹配 → not_found，建议使用 query_meetings', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\GetMeetingInfoTool::class, [
        'title' => '不存在的会议',
    ], 'current_user');

    expect($result['status'])->toBe('not_found');
    expect($result['message'])->toContain('query_meetings');
    expect($result)->not->toHaveKey('recent_meetings');
});

test('查询会议：通过 meetingid 精确定位 → 返回详情', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\GetMeetingInfoTool::class, [
        'meetingid' => 'mock_meeting_001',
    ]);

    expect($result['status'])->toBe('success');
    expect($result['meetingid'])->toBe('mock_meeting_001');
    expect($result['title'])->toBe('产品评审会');
    expect($result['api_detail'])->toBeArray();
});

test('查询会议：meetingid 不存在 → not_found', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\GetMeetingInfoTool::class, [
        'meetingid' => 'nonexistent',
    ]);

    expect($result['status'])->toBe('not_found');
});

test('查询会议：无 meetingid 且无 title → error', function () {
    $result = callMeetingTool(\App\Mcp\Tools\Meeting\GetMeetingInfoTool::class, []);

    expect($result['status'])->toBe('error');
    expect($result['message'])->toContain('meetingid');
});

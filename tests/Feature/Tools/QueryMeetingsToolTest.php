<?php

use App\Mcp\Tools\Meeting\QueryMeetingsTool;
use App\Models\RecentMeeting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // 预置会议记录（current_user 的 3 条）
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

    // 另一个用户的会议
    RecentMeeting::create([
        'title' => '其他用户会议',
        'start_time' => '2026-02-26T10:00:00',
        'duration_minutes' => 30,
        'invitees' => [],
        'creator_userid' => 'other_user',
        'meetingid' => 'mock_meeting_other',
    ]);
});

/**
 * 调用 QueryMeetingsTool 并返回解码后的 JSON
 */
function callQueryMeetings(array $input, ?string $userId = null): array
{
    $tool = app(QueryMeetingsTool::class);
    $request = new \Laravel\Mcp\Request($input);
    $params = ['request' => $request];
    if ($userId !== null) {
        $params['userId'] = $userId;
    }
    $response = app()->call([$tool, 'handle'], $params);

    return json_decode((string) $response->content(), true);
}

test('无参数查询 → 返回用户所有会议', function () {
    $result = callQueryMeetings([], 'current_user');

    expect($result['status'])->toBe('success');
    expect($result['count'])->toBe(3);
    expect($result['meetings'])->toHaveCount(3);
    // 按 start_time 降序，index 从 1 开始
    expect($result['meetings'][0]['index'])->toBe(1);
    expect($result['meetings'][0])->toHaveKeys(['title', 'start_time', 'duration_minutes', 'invitees', 'meetingid']);
});

test('keyword 过滤 → 只返回标题匹配的会议', function () {
    $result = callQueryMeetings(['keyword' => '产品'], 'current_user');

    expect($result['status'])->toBe('success');
    expect($result['count'])->toBe(1);
    expect($result['meetings'][0]['title'])->toBe('产品评审会');
});

test('date 过滤 → 只返回当天的会议', function () {
    $result = callQueryMeetings(['time_range' => '2026-02-26'], 'current_user');

    expect($result['status'])->toBe('success');
    expect($result['count'])->toBe(2);
    // 2月27日的技术分享会不在结果中
    $meetingids = collect($result['meetings'])->pluck('meetingid')->toArray();
    expect($meetingids)->not->toContain('mock_meeting_003');
});

test('sort_by=created_at → 按创建时间排序', function () {
    // 确保 created_at 有明确先后顺序
    RecentMeeting::where('meetingid', 'mock_meeting_001')->update(['created_at' => '2026-02-25 10:00:00']);
    RecentMeeting::where('meetingid', 'mock_meeting_002')->update(['created_at' => '2026-02-25 11:00:00']);
    RecentMeeting::where('meetingid', 'mock_meeting_003')->update(['created_at' => '2026-02-25 12:00:00']);

    $result = callQueryMeetings(['sort_by' => 'created_at'], 'current_user');

    expect($result['status'])->toBe('success');
    expect($result['count'])->toBe(3);
    // 最后创建的排在前面
    expect($result['meetings'][0]['meetingid'])->toBe('mock_meeting_003');
});

test('无会议时返回 empty', function () {
    $result = callQueryMeetings(['keyword' => '不存在的会议'], 'current_user');

    expect($result['status'])->toBe('empty');
    expect($result['count'])->toBe(0);
    expect($result['meetings'])->toBeEmpty();
    expect($result['message'])->toBe('没有找到会议记录');
});

test('不同用户看不到其他人的会议', function () {
    $result = callQueryMeetings([], 'other_user');

    expect($result['status'])->toBe('success');
    expect($result['count'])->toBe(1);
    expect($result['meetings'][0]['title'])->toBe('其他用户会议');
});

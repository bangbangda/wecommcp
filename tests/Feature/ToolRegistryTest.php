<?php

use App\Mcp\ToolRegistry;
use App\Mcp\Tools\Contact\SearchContactsTool;
use App\Mcp\Tools\Meeting\CancelMeetingTool;
use App\Mcp\Tools\Meeting\CreateMeetingTool;
use App\Mcp\Tools\Meeting\GetMeetingInfoTool;
use App\Mcp\Tools\Meeting\QueryMeetingsTool;
use App\Mcp\Tools\Meeting\UpdateMeetingTool;
use App\Mcp\Tools\MeetingRoom\BookMeetingRoomTool;
use App\Mcp\Tools\MeetingRoom\CancelRoomBookingTool;
use App\Mcp\Tools\MeetingRoom\QueryMeetingRoomsTool;
use App\Mcp\Tools\MeetingRoom\QueryRoomBookingsTool;
use App\Mcp\Tools\Memory\DeleteMemoryTool;
use App\Mcp\Tools\Memory\SaveMemoryTool;
use App\Mcp\Tools\Schedule\CancelScheduleTool;
use App\Mcp\Tools\Schedule\CreateCalendarTool;
use App\Mcp\Tools\Schedule\CreateScheduleTool;
use App\Mcp\Tools\Schedule\GetScheduleDetailTool;
use App\Mcp\Tools\Schedule\QueryCalendarsTool;
use App\Mcp\Tools\Schedule\QuerySchedulesTool;

test('getToolClasses 返回所有注册的 Tool 类', function () {
    $registry = app(ToolRegistry::class);

    $classes = $registry->getToolClasses();

    expect($classes)->toHaveCount(18)
        ->toContain(CreateMeetingTool::class)
        ->toContain(CancelMeetingTool::class)
        ->toContain(UpdateMeetingTool::class)
        ->toContain(GetMeetingInfoTool::class)
        ->toContain(QueryMeetingsTool::class)
        ->toContain(SearchContactsTool::class)
        ->toContain(QueryMeetingRoomsTool::class)
        ->toContain(BookMeetingRoomTool::class)
        ->toContain(CancelRoomBookingTool::class)
        ->toContain(QueryRoomBookingsTool::class)
        ->toContain(SaveMemoryTool::class)
        ->toContain(DeleteMemoryTool::class)
        ->toContain(CreateCalendarTool::class)
        ->toContain(CreateScheduleTool::class)
        ->toContain(QuerySchedulesTool::class)
        ->toContain(GetScheduleDetailTool::class)
        ->toContain(CancelScheduleTool::class)
        ->toContain(QueryCalendarsTool::class);
});

test('getToolMap 返回正确的 name → class 映射', function () {
    $registry = app(ToolRegistry::class);

    $map = $registry->getToolMap();

    expect($map)->toHaveCount(18)
        ->toHaveKey('create_meeting', CreateMeetingTool::class)
        ->toHaveKey('cancel_meeting', CancelMeetingTool::class)
        ->toHaveKey('update_meeting', UpdateMeetingTool::class)
        ->toHaveKey('get_meeting_info', GetMeetingInfoTool::class)
        ->toHaveKey('query_meetings', QueryMeetingsTool::class)
        ->toHaveKey('search_contacts', SearchContactsTool::class)
        ->toHaveKey('query_meeting_rooms', QueryMeetingRoomsTool::class)
        ->toHaveKey('book_meeting_room', BookMeetingRoomTool::class)
        ->toHaveKey('cancel_room_booking', CancelRoomBookingTool::class)
        ->toHaveKey('query_room_bookings', QueryRoomBookingsTool::class)
        ->toHaveKey('save_memory', SaveMemoryTool::class)
        ->toHaveKey('delete_memory', DeleteMemoryTool::class)
        ->toHaveKey('create_calendar', CreateCalendarTool::class)
        ->toHaveKey('create_schedule', CreateScheduleTool::class)
        ->toHaveKey('query_schedules', QuerySchedulesTool::class)
        ->toHaveKey('get_schedule_detail', GetScheduleDetailTool::class)
        ->toHaveKey('cancel_schedule', CancelScheduleTool::class)
        ->toHaveKey('query_calendars', QueryCalendarsTool::class);
});

test('getClaudeToolDefinitions 格式正确', function () {
    $registry = app(ToolRegistry::class);

    $definitions = $registry->getClaudeToolDefinitions();

    expect($definitions)->toHaveCount(18);

    // 每个定义包含 name、description、input_schema
    foreach ($definitions as $def) {
        expect($def)->toHaveKeys(['name', 'description', 'input_schema']);
        expect($def['input_schema'])->toHaveKey('type')
            ->and($def['input_schema']['type'])->toBe('object');
    }

    // 验证 create_meeting 的 required 字段
    $createMeeting = collect($definitions)->firstWhere('name', 'create_meeting');
    expect($createMeeting['input_schema'])->toHaveKey('required')
        ->and($createMeeting['input_schema']['required'])->toContain('title')
        ->toContain('start_time');

    // 验证 search_contacts 的 required 字段
    $searchContacts = collect($definitions)->firstWhere('name', 'search_contacts');
    expect($searchContacts['input_schema'])->toHaveKey('required')
        ->and($searchContacts['input_schema']['required'])->toContain('name');
});

test('getCapabilitiesSummary 包含所有 tool 描述', function () {
    $registry = app(ToolRegistry::class);

    $summary = $registry->getCapabilitiesSummary();

    expect($summary)
        ->toContain('1.')
        ->toContain('2.')
        ->toContain('3.')
        ->toContain('4.')
        ->toContain('5.')
        ->toContain('6.')
        ->toContain('7.')
        ->toContain('8.')
        ->toContain('9.')
        ->toContain('10.')
        ->toContain('11.')
        ->toContain('12.')
        ->toContain('创建企业微信在线视频会议')
        ->toContain('修改企业微信会议的标题')
        ->toContain('取消企业微信会议')
        ->toContain('查询单个企业微信会议的完整详情')
        ->toContain('查询用户的会议列表')
        ->toContain('搜索企业微信通讯录联系人')
        ->toContain('查询企业微信会议室列表')
        ->toContain('预定企业微信会议室')
        ->toContain('取消企业微信会议室预定')
        ->toContain('查询企业微信会议室的预定信息')
        ->toContain('保存用户偏好或习惯到长期记忆')
        ->toContain('删除用户的一条长期记忆')
        ->toContain('查询用户的日历列表');
});

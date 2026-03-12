<?php

namespace App\Mcp;

use App\Mcp\Tools\Contact\SearchContactsTool;
use App\Mcp\Tools\GroupChat\CreateGroupChatTool;
use App\Mcp\Tools\GroupChat\GetGroupChatTool;
use App\Mcp\Tools\GroupChat\QueryGroupChatsTool;
use App\Mcp\Tools\GroupChat\SendGroupMessageTool;
use App\Mcp\Tools\GroupChat\UpdateGroupChatTool;
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
use App\Mcp\Tools\Profile\GetProfileTool;
use App\Mcp\Tools\Profile\SetProfileTool;
use App\Mcp\Tools\Schedule\CancelScheduleTool;
use App\Mcp\Tools\Schedule\CreateCalendarTool;
use App\Mcp\Tools\Schedule\CreateScheduleTool;
use App\Mcp\Tools\Schedule\GetScheduleDetailTool;
use App\Mcp\Tools\Schedule\QueryCalendarsTool;
use App\Mcp\Tools\Schedule\QuerySchedulesTool;

/**
 * 统一 Tool 注册中心
 * 新增 Tool 只需在 $tools 数组加一行，WecomServer 和 ChatService 自动同步
 */
class ToolRegistry
{
    /** @var array<string, string|null> Tool 名称 → 所属业务模块映射 */
    private const TOOL_MODULE_MAP = [
        'create_meeting' => 'meeting',
        'cancel_meeting' => 'meeting',
        'update_meeting' => 'meeting',
        'get_meeting_info' => 'meeting',
        'query_meetings' => 'meeting',
        'create_calendar' => 'schedule',
        'create_schedule' => 'schedule',
        'query_schedules' => 'schedule',
        'get_schedule_detail' => 'schedule',
        'cancel_schedule' => 'schedule',
        'query_calendars' => 'schedule',
        'query_meeting_rooms' => 'meeting_room',
        'book_meeting_room' => 'meeting_room',
        'cancel_room_booking' => 'meeting_room',
        'query_room_bookings' => 'meeting_room',
        'search_contacts' => null,
        'save_memory' => null,
        'delete_memory' => null,
        'create_group_chat' => null,
        'update_group_chat' => null,
        'get_group_chat' => null,
        'query_group_chats' => null,
        'send_group_message' => null,
        'set_profile' => null,
        'get_profile' => null,
    ];

    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> 注册的 Tool 类列表 */
    protected array $tools = [
        CreateMeetingTool::class,
        CancelMeetingTool::class,
        UpdateMeetingTool::class,
        GetMeetingInfoTool::class,
        QueryMeetingsTool::class,
        SearchContactsTool::class,
        QueryMeetingRoomsTool::class,
        BookMeetingRoomTool::class,
        CancelRoomBookingTool::class,
        QueryRoomBookingsTool::class,
        SaveMemoryTool::class,
        DeleteMemoryTool::class,
        CreateCalendarTool::class,
        CreateScheduleTool::class,
        QuerySchedulesTool::class,
        GetScheduleDetailTool::class,
        CancelScheduleTool::class,
        QueryCalendarsTool::class,
        CreateGroupChatTool::class,
        UpdateGroupChatTool::class,
        GetGroupChatTool::class,
        QueryGroupChatsTool::class,
        SendGroupMessageTool::class,
        SetProfileTool::class,
        GetProfileTool::class,
    ];

    /**
     * 返回 Tool 类名列表（WecomServer 用）
     *
     * @return array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    public function getToolClasses(): array
    {
        return $this->tools;
    }

    /**
     * 返回 name → class 映射（ChatService executeTool 用）
     *
     * @return array<string, class-string<\Laravel\Mcp\Server\Tool>>
     */
    public function getToolMap(): array
    {
        $map = [];

        foreach ($this->tools as $toolClass) {
            $tool = app($toolClass);
            $map[$tool->name()] = $toolClass;
        }

        return $map;
    }

    /**
     * 返回 Claude 格式的 tool 定义
     * 将 Tool::toArray() 的 inputSchema（camelCase）转换为 input_schema（snake_case）
     *
     * @return array<int, array{name: string, description: string, input_schema: array}>
     */
    public function getClaudeToolDefinitions(): array
    {
        $definitions = [];

        foreach ($this->tools as $toolClass) {
            $tool = app($toolClass);
            $toolArray = $tool->toArray();

            $definitions[] = [
                'name' => $toolArray['name'],
                'description' => $toolArray['description'],
                'input_schema' => $toolArray['inputSchema'],
            ];
        }

        return $definitions;
    }

    /**
     * 根据 Tool 名称获取所属业务模块
     *
     * @param  string  $toolName  工具名称
     * @return string|null 模块标识（schedule/meeting/meeting_room），无模块返回 null
     */
    public function getModuleForTool(string $toolName): ?string
    {
        return self::TOOL_MODULE_MAP[$toolName] ?? null;
    }

    /**
     * 从 #[Description] 自动生成编号能力列表（system prompt 用）
     *
     * @return string 格式如 "1. 创建会议：创建企业微信会议...\n2. ..."
     */
    public function getCapabilitiesSummary(): string
    {
        $lines = [];

        foreach ($this->tools as $index => $toolClass) {
            $tool = app($toolClass);
            $description = $tool->description();
            $lines[] = ($index + 1).". {$description}";
        }

        return implode("\n", $lines);
    }
}

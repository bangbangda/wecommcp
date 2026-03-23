<?php

namespace App\Services;

use App\Ai\Contracts\AiDriver;
use App\Mcp\ToolRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChatService
{
    // 滑动窗口：最近 20 条消息（约 5 轮完整 tool 交互）
    private const MAX_MESSAGES = 20;

    // 对话历史 TTL：2 小时，每次消息刷新
    private const HISTORY_TTL = 7200;

    // Cache key 前缀
    private const CACHE_PREFIX = 'chat_history:';

    public function __construct(
        private AiDriver $aiDriver,
        private ToolRegistry $toolRegistry,
        private UserMemoryService $memoryService,
        private UserProfileService $profileService,
    ) {}

    /**
     * 完整对话流程
     *
     * @param  string  $userId  用户 ID
     * @param  string  $message  用户消息
     * @param  \Closure|null  $onProgress  进度回调，接收 string 进度消息
     * @return string AI 回复文本
     */
    public function chat(string $userId, string $message, ?\Closure $onProgress = null): string
    {
        $messages = $this->loadHistory($userId);
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        $reply = $this->chatWithToolLoop($userId, $messages, $onProgress);
        $this->saveHistory($userId, $messages);

        return $reply;
    }

    /**
     * 组装 system prompt
     * 能力列表从 ToolRegistry 自动生成，注意事项手动维护
     */
    private function buildSystemPrompt(string $userId): string
    {
        $now = now('Asia/Shanghai')->format('Y-m-d H:i:s (l)');
        $capabilities = $this->toolRegistry->getCapabilitiesSummary();
        $memoryBlock = $this->buildMemoryBlock($userId);
        $profileBlock = $this->buildProfileBlock($userId);
        $hasProfile = ! empty($profileBlock);
        $identity = $profileBlock ?: '你是企业微信 AI 助手，帮助用户管理会议、日程、日历和查询联系人。';
        $profileGuide = $hasProfile ? '' : $this->buildProfileGuide();

        return <<<PROMPT
{$identity}

当前时间：{$now}
当前用户：{$userId}

你的能力：
{$capabilities}
{$memoryBlock}
## 核心行为准则

### 会议 vs 日程的区别
- **会议（Meeting）**：企微在线视频会议，有会议链接，参会人通过链接加入线上会议。当用户说"开个视频会""在线会议""线上会议"时使用会议工具。
- **日程（Schedule）**：日历上的时间安排，用于记录面试、线下会议、项目计划、提醒备忘等。当用户说"建个日程""安排一下""记个提醒""帮我排个时间"时使用日程工具。
- **判断依据**：用户明确说"日程""安排""提醒"→ 日程；用户明确说"视频会议""在线会议""线上开会"→ 会议；用户只说"开个会"且未明确线上 → 优先追问是在线会议还是日程安排。

### 链式推理
当用户指令不够具体时，主动分解任务，通过多步工具调用完成。
遇到"刚才那个""上午的""下午的"等模糊引用时，先查询再操作。

### 让用户选择而非描述
查询返回多个结果时，以编号列表展示（如"1. 产品周会 2/27 15:00\n2. 技术周会 2/28 10:00"），让用户回复编号，而不是要求用户重新描述。

### 参数不足时主动追问
缺少必要信息时询问用户，而不是猜测。例如"建个会"没说时间，应追问。

### 记忆管理
当用户表达偏好或习惯时（如"记住""以后默认""我习惯"），主动调用 save_memory 保存。
当用户要求忘掉某条信息时，根据记忆列表中的 [Mn] ID 调用 delete_memory 删除。
利用已有记忆提供个性化服务：如用户说"建个会"且记忆中有默认时长偏好，可直接使用。
不要重复保存已有的记忆。

### 定时任务
- 当用户提到"每天""每周""定时""提醒我""到时候""定期"等涉及定时或延迟的操作时，使用定时任务工具
- 区分一次性（"30分钟后""明天10点"）和周期性（"每天""每周五""工作日"）
- 一次性任务需将相对时间（"30分钟后"）转为绝对日期和时间
- 群消息任务需要 chatid，不确定时先用 query_group_chats 查询
- 提醒其他人时需要对方的 userid，不确定时先用 search_contacts 查询，多候选时让用户选择
- 创建成功后告知用户具体的下次执行时间

### 文档操作
- 创建文档前，必须先询问用户："是否需要在企微客户端编辑该文档？"
  - 如果需要编辑：追问由谁来编辑，通过 search_contacts 获取 userid，设置为 admin_users
  - 如果仅查看不编辑：不设置 admin_users
- 文档内容仅支持纯文本，不支持 Markdown 格式，写入时不要使用 Markdown 语法
- 创建文档后返回访问链接，写入内容使用 update_document_content 工具
- 读取文档内容后可以进行分析、总结等操作

### 数据分析
- 当用户想了解与某人的沟通情况、总结聊天内容、获取跟进建议时，使用 analyze_chat_with_contact 工具
- 需要先确定联系人身份：如果用户提供了姓名，可以直接传 contact_name 让工具内部搜索，也可以先用 search_contacts 或 search_external_contacts 获取 userid 后传入 contact_userid
- 默认分析最近 7 天，用户可指定时间范围
- 当前用户的 userid 通过系统上下文获取，传入 userid 参数

### 工作总结
- 当用户想了解自己某天的工作内容、总结日报、回顾一天做了什么时，使用 get_daily_work_summary
- 默认查询今天，用户可指定日期
- 此工具汇总所有单聊和群聊记录，适用于个人工作总结
- 如果用户想分析与某个特定人的沟通细节，引导使用 analyze_chat_with_contact

### 汇报分析
- 当领导想查看团队汇报情况时，使用 analyze_team_journals 分析汇报内容（工作概要、关注事项、质量评估、管理建议）
- 当领导想知道谁没交汇报时，使用 query_journal_stats 查看提交统计
- 支持按汇报类型筛选：daily=日报, weekly=周报, monthly=月报
- 系统通过汇报接收人关系自动识别团队成员，无需手动配置
{$profileGuide}
## 注意事项
- 时间转换为 ISO 8601 格式（如 2026-02-26T15:00:00）
- 创建会议时，当前用户自动作为管理员和参会人，invitees 只填其他参会人
- 创建日程时，当前用户自动作为组织者，attendees 只填其他参与者
- 联系人同音字匹配到多个候选时，列出姓名和部门让用户选择
- 用简洁友好的中文回复

## 推理链示例

用户: "取消下午的会"
→ 调用 query_meetings 查询今天会议
→ 筛选下午时段，以编号列表展示
→ 用户选择后，用 meetingid 调用 cancel_meeting

用户: "帮我约个明天的线上会议，叫上小王"
→ 追问会议主题和具体时间
→ 调用 create_meeting（内部自动匹配"小王"）
→ 如果匹配到多个候选，展示列表让用户确认

用户: "帮我创建一个日程，明天下午3点需求评审"
→ 追问结束时间（或使用默认 1 小时）
→ 调用 create_schedule
→ 回复日程创建成功

用户: "我今天有什么安排"
→ 调用 query_schedules 查询今天日程
→ 返回日程列表

用户: "以后开会默认 30 分钟"
→ 调用 save_memory(module: "preferences", content: "默认会议时长 30 分钟")
→ 回复确认已记住

用户: "每天早上9点在产品群发：大家记得提交日报"
→ 调用 query_group_chats(keyword: "产品") 查询 chatid
→ 调用 create_recurring_task(action_type=send_group_message, schedule_type=daily, execute_time=09:00, ...)
→ 回复确认，告知下次执行时间

用户: "30分钟后提醒我确认报价信息"
→ 计算 30 分钟后的绝对时间（如 15:30），拆为 execute_date + execute_time
→ 调用 create_onetime_task(action_type=send_user_message, execute_date=2026-03-12, execute_time=15:30, ...)
→ 回复确认，告知将在 15:30 提醒

用户: "明天上午10点提醒张三交项目报告"
→ 调用 search_contacts(name: "张三") 查询 userid
→ 如果匹配到多个候选，展示列表让用户选择
→ 调用 create_onetime_task(action_type=send_user_message, target_id=zhangsan_userid, execute_date=..., execute_time=10:00, ...)
→ 回复确认，告知将在明天 10:00 提醒张三

用户: "帮我创建一个工作总结文档"
→ 先询问用户："是否需要在企微客户端编辑该文档？如果需要，请告知由谁来编辑"
→ 用户回复"需要，我自己编辑" → search_contacts 获取用户 userid → create_document(admin_users=[userid])
→ 用户回复"不需要编辑" → create_document（不设 admin_users）
→ 创建成功后返回文档链接

用户: "把今天的分析结果保存为文档"
→ 先询问是否需要编辑
→ 调用 create_document 创建文档
→ 调用 update_document_content 写入分析内容（纯文本）
→ 返回文档链接

用户: "我和小王都聊什么了"
→ 调用 analyze_chat_with_contact(userid=当前用户, contact_name="小王")
→ 如果匹配到多个候选，展示列表让用户确认
→ 返回沟通分析报告

用户: "总结一下最近和张总的沟通"
→ 调用 analyze_chat_with_contact(userid=当前用户, contact_name="张总")
→ 返回综合分析（概要、话题、待跟进、建议）

用户: "我应该怎么跟进和李四的合作"
→ 调用 analyze_chat_with_contact(userid=当前用户, contact_name="李四")
→ 重点关注分析结果中的跟进建议部分

用户: "今天我都干了什么"
→ 调用 get_daily_work_summary(userid=当前用户)
→ 返回当天工作总结

用户: "帮我总结一下昨天的工作"
→ 调用 get_daily_work_summary(userid=当前用户, date=昨天日期)
→ 返回昨天的工作总结

用户: "帮我看看团队这周的日报"
→ 调用 analyze_team_journals(userid=当前用户, report_type="daily")
→ 返回团队汇报分析（工作概要、关注事项、质量评估）

用户: "谁还没交周报"
→ 调用 query_journal_stats(userid=当前用户, report_type="weekly")
→ 返回已提交/未提交列表和提交率
PROMPT;
    }

    /**
     * 构建个性化设置引导文本
     * 仅在用户无 profile 时注入，引导 AI 在合适时机提示用户设置
     *
     * @return string 引导文本
     */
    private function buildProfileGuide(): string
    {
        return <<<'GUIDE'

### 个性化引导
当前用户还没有个性化设置。在以下时机，可以**简短地**提一句个性化功能（一句话即可，不要列清单）：
- 首次对话（如打招呼、自我介绍时）
- 成功完成用户任务后，自然地附带提一句

示例："对了，你可以给我起个名字，或者告诉我你喜欢什么风格的回复，我可以调整哦~"

注意：
- **永远先完成用户的任务**，引导只是附带
- 同一轮对话只提一次，不要反复提醒
- 如果用户在聊正事（如连续操作会议/日程），不要打断
- 用户说"不需要""以后再说"时不再提起

GUIDE;
    }

    /**
     * 构建用户个性化 profile 注入块
     * 无 profile 时返回空字符串，有 profile 时返回个性化身份描述
     *
     * @param  string  $userId  用户 ID
     * @return string profile 块文本
     */
    private function buildProfileBlock(string $userId): string
    {
        return $this->profileService->formatForPrompt($userId);
    }

    /**
     * 构建用户记忆注入块
     * 无记忆时返回空字符串，有记忆时返回带换行的格式化文本
     *
     * @param  string  $userId  用户 ID
     * @return string 记忆块文本
     */
    private function buildMemoryBlock(string $userId): string
    {
        $memoryText = $this->memoryService->formatForPrompt($userId);

        if (empty($memoryText)) {
            return '';
        }

        return "\n{$memoryText}\n";
    }

    /**
     * Tool 定义（Claude 内部格式，驱动层负责转换为各 API 格式）
     * 从 ToolRegistry 自动生成，无需手动维护
     *
     * @return array<int, array{name: string, description: string, input_schema: array}>
     */
    private function getToolDefinitions(): array
    {
        return $this->toolRegistry->getClaudeToolDefinitions();
    }

    /**
     * 调用 AI 并处理 tool_use 循环
     *
     * @param  string  $userId  用户 ID
     * @param  array  &$messages  对话历史（引用传递，循环中会追加消息）
     * @param  \Closure|null  $onProgress  进度回调
     * @return string AI 回复文本
     */
    private function chatWithToolLoop(string $userId, array &$messages, ?\Closure $onProgress = null): string
    {
        $maxIterations = 10;
        $hasExecutedTools = false;

        for ($i = 0; $i < $maxIterations; $i++) {
            if ($onProgress) {
                $onProgress($hasExecutedTools ? '正在生成回复...' : '正在分析...');
            }

            $response = $this->aiDriver->chat(
                $this->buildSystemPrompt($userId),
                $messages,
                $this->getToolDefinitions(),
            );

            if ($response === null) {
                return '抱歉，AI 服务暂时不可用，请稍后再试。';
            }

            // 将 assistant 回复写入历史
            $messages[] = $response->rawAssistantMessage;

            // 没有 tool 调用，返回文本
            if (! $response->hasToolCalls()) {
                return $response->text;
            }

            // 执行所有 tool 调用
            $toolResults = [];
            foreach ($response->toolCalls as $toolCall) {
                if ($onProgress) {
                    $onProgress(self::toolProgressMessage($toolCall->name));
                }

                $result = $this->executeTool($toolCall->name, $toolCall->input, $userId);
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $toolCall->id,
                    'content' => $result,
                ];
            }

            $messages[] = [
                'role' => 'user',
                'content' => $toolResults,
            ];

            // 每轮 tool 调用后持久化中间状态，防止长 tool 循环中丢失
            $this->saveHistory($userId, $messages);

            $hasExecutedTools = true;
        }

        return '处理超时，请简化您的请求后重试。';
    }

    /**
     * 根据 tool 名称生成用户可读的进度提示
     *
     * @param  string  $toolName  工具名称
     * @return string 进度提示消息
     */
    public static function toolProgressMessage(string $toolName): string
    {
        return match ($toolName) {
            'search_contacts' => '正在搜索联系人...',
            'create_meeting' => '正在创建会议...',
            'cancel_meeting' => '正在取消会议...',
            'update_meeting' => '正在修改会议...',
            'get_meeting_info' => '正在查询会议信息...',
            'query_meetings' => '正在查询会议列表...',
            'query_meeting_rooms' => '正在查询会议室...',
            'book_meeting_room' => '正在预定会议室...',
            'cancel_room_booking' => '正在取消会议室预定...',
            'query_room_bookings' => '正在查询会议室预定信息...',
            'save_memory' => '正在保存记忆...',
            'delete_memory' => '正在删除记忆...',
            'create_calendar' => '正在创建日历...',
            'create_schedule' => '正在创建日程...',
            'query_schedules' => '正在查询日程列表...',
            'get_schedule_detail' => '正在查询日程详情...',
            'cancel_schedule' => '正在取消日程...',
            'query_calendars' => '正在查询日历列表...',
            'create_group_chat' => '正在创建群聊...',
            'update_group_chat' => '正在修改群聊...',
            'get_group_chat' => '正在获取群聊信息...',
            'query_group_chats' => '正在查询群聊列表...',
            'send_group_message' => '正在发送群消息...',
            'set_profile' => '正在设置个性化配置...',
            'get_profile' => '正在查看个性化配置...',
            'create_onetime_task' => '正在创建定时任务...',
            'create_recurring_task' => '正在创建定时任务...',
            'query_scheduled_tasks' => '正在查询定时任务...',
            'cancel_scheduled_task' => '正在取消定时任务...',
            'analyze_chat_with_contact' => '正在分析聊天记录...',
            'analyze_team_journals' => '正在分析团队汇报...',
            'query_journal_stats' => '正在查询汇报统计...',
            'get_daily_work_summary' => '正在生成工作总结...',
            default => '正在执行操作...',
        };
    }

    /**
     * 执行 MCP Tool
     * 自动加载 Tool 所属模块的用户配置（moduleConfig）并透传给 handle 方法
     *
     * @param  string  $toolName  工具名称
     * @param  array  $input  AI 提供的工具参数
     * @param  string  $userId  当前用户 ID（从回调消息 from.userid 透传）
     * @return string 工具执行结果 JSON
     */
    private function executeTool(string $toolName, array $input, string $userId): string
    {
        Log::info("执行 Tool: {$toolName}", ['input' => $input, 'userId' => $userId]);

        try {
            $toolClass = $this->toolRegistry->getToolMap()[$toolName] ?? null;
            if (! $toolClass) {
                return json_encode(['error' => "未知的工具: {$toolName}"], JSON_UNESCAPED_UNICODE);
            }

            // 加载 Tool 所属模块的用户配置
            $module = $this->toolRegistry->getModuleForTool($toolName);
            $moduleConfig = $module
                ? app(ModuleConfigService::class)->getAll($userId, $module)
                : [];

            $tool = app($toolClass);
            $request = new \Laravel\Mcp\Request($input);
            $response = app()->call([$tool, 'handle'], [
                'request' => $request,
                'userId' => $userId,
                'moduleConfig' => $moduleConfig,
            ]);

            return (string) $response->content();
        } catch (\Exception $e) {
            Log::error("Tool 执行失败: {$toolName}", ['error' => $e->getMessage()]);

            return json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * 清除用户对话历史
     *
     * @param  string  $userId  用户 ID
     */
    public function clearHistory(string $userId): void
    {
        Cache::forget(self::CACHE_PREFIX.$userId);
    }

    /**
     * 从 Cache 加载对话历史
     *
     * @param  string  $userId  用户 ID
     * @return array 对话消息数组
     */
    private function loadHistory(string $userId): array
    {
        return Cache::get(self::CACHE_PREFIX.$userId, []);
    }

    /**
     * 裁剪并保存对话历史到 Cache，刷新 TTL
     *
     * @param  string  $userId  用户 ID
     * @param  array  $messages  对话消息数组
     */
    private function saveHistory(string $userId, array $messages): void
    {
        $messages = $this->trimMessages($messages);
        Cache::put(self::CACHE_PREFIX.$userId, $messages, self::HISTORY_TTL);
    }

    /**
     * 滑动窗口裁剪：保留最近 MAX_MESSAGES 条消息，确保首条为纯文本 user 消息
     * 避免 tool_result 消息与其对应的 assistant(tool_calls) 消息被拆散，
     * 否则 OpenAI 兼容 API 会报错："Messages with role 'tool' must be a response to a preceding message with 'tool_calls'"
     *
     * @param  array  $messages  对话消息数组
     * @return array 裁剪后的消息数组
     */
    private function trimMessages(array $messages): array
    {
        if (count($messages) <= self::MAX_MESSAGES) {
            return $messages;
        }

        // 从尾部保留 MAX_MESSAGES 条
        $messages = array_slice($messages, -self::MAX_MESSAGES);

        // 确保首条为纯文本 user 消息（跳过 assistant 和含 tool_result 的 user 消息）
        while (count($messages) > 1) {
            $first = $messages[0];
            if (($first['role'] ?? '') === 'user' && is_string($first['content'] ?? '')) {
                break;
            }
            array_shift($messages);
        }

        return array_values($messages);
    }
}

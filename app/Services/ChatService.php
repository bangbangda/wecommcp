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

        return <<<PROMPT
你是企业微信 AI 助手，帮助用户管理会议和查询联系人。

当前时间：{$now}
当前用户：{$userId}

你的能力：
{$capabilities}
{$memoryBlock}
## 核心行为准则

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

## 注意事项
- 时间转换为 ISO 8601 格式（如 2026-02-26T15:00:00）
- 创建会议时，当前用户自动作为管理员和参会人，invitees 只填其他参会人
- 联系人同音字匹配到多个候选时，列出姓名和部门让用户选择
- 用简洁友好的中文回复

## 推理链示例

用户: "取消下午的会"
→ 调用 query_meetings 查询今天会议
→ 筛选下午时段，以编号列表展示
→ 用户选择后，用 meetingid 调用 cancel_meeting

用户: "帮我约个明天的会，叫上小王"
→ 追问会议主题和具体时间
→ 调用 create_meeting（内部自动匹配"小王"）
→ 如果匹配到多个候选，展示列表让用户确认

用户: "以后开会默认 30 分钟"
→ 调用 save_memory(module: "preferences", content: "默认会议时长 30 分钟")
→ 回复确认已记住
PROMPT;
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
            'create_group_chat' => '正在创建群聊...',
            'update_group_chat' => '正在修改群聊...',
            'get_group_chat' => '正在获取群聊信息...',
            'query_group_chats' => '正在查询群聊列表...',
            'send_group_message' => '正在发送群消息...',
            default => '正在执行操作...',
        };
    }

    /**
     * 执行 MCP Tool
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

            $tool = app($toolClass);
            $request = new \Laravel\Mcp\Request($input);
            $response = app()->call([$tool, 'handle'], ['request' => $request, 'userId' => $userId]);

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

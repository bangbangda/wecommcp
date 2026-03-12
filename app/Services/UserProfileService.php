<?php

namespace App\Services;

use App\Ai\Contracts\AiDriver;
use App\Models\Contact;
use App\Models\UserProfile;
use Illuminate\Support\Facades\Log;

class UserProfileService
{
    /** 允许设置的字段列表 */
    public const ALLOWED_FIELDS = [
        'bot_name',
        'user_nickname',
        'persona',
        'greeting_template',
        'catchphrases',
        'taboos',
    ];

    /** 字段中文名称映射 */
    private const FIELD_LABELS = [
        'bot_name' => '机器人昵称',
        'user_nickname' => '用户称呼',
        'persona' => '回复风格',
        'greeting_template' => '开场白模板',
        'catchphrases' => '常用语',
        'taboos' => '禁忌项',
    ];

    public function __construct(
        private AiDriver $aiDriver,
    ) {}

    /**
     * 获取用户 profile
     *
     * @param  string  $userId  用户 ID
     * @return UserProfile|null profile 记录，不存在返回 null
     */
    public function get(string $userId): ?UserProfile
    {
        return UserProfile::where('user_id', $userId)->first();
    }

    /**
     * 更新单个字段（不存在则自动创建）
     *
     * @param  string  $userId  用户 ID
     * @param  string  $field  字段名
     * @param  string  $value  字段值
     * @return array{status: string, field: string, value: string, message: string}
     */
    public function updateField(string $userId, string $field, string $value): array
    {
        if (! in_array($field, self::ALLOWED_FIELDS)) {
            return [
                'status' => 'error',
                'message' => "无效的字段「{$field}」，可选值：".implode('、', self::ALLOWED_FIELDS),
            ];
        }

        $profile = UserProfile::updateOrCreate(
            ['user_id' => $userId],
            [$field => $value],
        );

        $label = self::FIELD_LABELS[$field] ?? $field;

        Log::debug('UserProfileService::updateField 更新字段', [
            'user_id' => $userId,
            'field' => $field,
            'value' => $value,
        ]);

        return [
            'status' => 'success',
            'field' => $field,
            'value' => $value,
            'message' => "已设置{$label}为「{$value}」",
        ];
    }

    /**
     * 格式化为 system prompt 注入文本
     * 无 profile 或所有字段为空时返回空字符串
     *
     * @param  string  $userId  用户 ID
     * @return string prompt 注入文本
     */
    public function formatForPrompt(string $userId): string
    {
        $profile = $this->get($userId);

        if (! $profile || $this->isProfileEmpty($profile)) {
            return '';
        }

        $lines = [];

        // 身份行
        $botName = $profile->bot_name ?? '企微 AI 助手';
        $nickname = $profile->user_nickname;
        $lines[] = $nickname
            ? "你是「{$botName}」，{$nickname} 的专属企微 AI 助手。"
            : "你是「{$botName}」，用户的专属企微 AI 助手。";

        // 人设
        if ($profile->persona) {
            $lines[] = "\n## 你的性格\n{$profile->persona}";
        }

        // 口头禅
        if ($profile->catchphrases) {
            $lines[] = "\n## 常用语";
            foreach (explode("\n", $profile->catchphrases) as $phrase) {
                if (trim($phrase)) {
                    $lines[] = "- {$phrase}";
                }
            }
        }

        // 禁忌
        if ($profile->taboos) {
            $lines[] = "\n## 禁忌";
            foreach (explode("\n", $profile->taboos) as $taboo) {
                if (trim($taboo)) {
                    $lines[] = "- {$taboo}";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 调用 AI 将用户简单描述润色为完整人设描述
     *
     * @param  string  $rawInput  用户原始输入
     * @return string 润色后的人设描述，失败时返回原文
     */
    public function polishPersona(string $rawInput): string
    {
        $systemPrompt = <<<'PROMPT'
你是一个提示词优化专家。用户用简短的话描述了他们希望 AI 助手的风格和性格。
请将用户的描述润色为一段 50-150 字的人设描述，供 AI 助手作为 system prompt 使用。

要求：
- 保留用户原意，不添加用户未提及的特征
- 用第二人称（"你是..."）
- 具体、可执行，避免空泛描述
- 如果用户只说了一两个词（如"幽默"），适当展开但不过度发挥
- 只输出润色后的人设描述，不要输出其他内容
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $rawInput],
        ];

        try {
            $response = $this->aiDriver->chat($systemPrompt, $messages);

            if ($response && ! empty($response->text)) {
                Log::debug('UserProfileService::polishPersona 润色成功', [
                    'input' => $rawInput,
                    'output' => $response->text,
                ]);

                return $response->text;
            }
        } catch (\Exception $e) {
            Log::error('UserProfileService::polishPersona AI 润色失败', [
                'input' => $rawInput,
                'error' => $e->getMessage(),
            ]);
        }

        // AI 润色失败时返回原文
        return $rawInput;
    }

    /**
     * 生成用户进入会话时的欢迎语
     * 三级策略：有 greeting_template 用模板 → 有 profile 基于昵称生成 → 无 profile 用首次引导语
     *
     * @param  string  $userId  用户 ID
     * @return string 欢迎语文本
     */
    public function buildWelcomeMessage(string $userId): string
    {
        $profile = $this->get($userId);
        $weekday = now('Asia/Shanghai')->locale('zh_CN')->isoFormat('dddd');

        // 无 profile：首次见面引导语
        if (! $profile || $this->isProfileEmpty($profile)) {
            return $this->buildFirstMeetGreeting($userId);
        }

        // 有 greeting_template：使用模板
        if (! empty($profile->greeting_template)) {
            $nickname = $profile->user_nickname ?? '你';

            return str_replace(
                ['{nickname}', '{weekday}'],
                [$nickname, $weekday],
                $profile->greeting_template,
            );
        }

        // 有 profile 但无 template：基于 bot_name/nickname 生成
        $botName = $profile->bot_name ?? '企微 AI 助手';
        $greeting = $profile->user_nickname
            ? "嗨，{$profile->user_nickname}！我是{$botName}，今天有什么需要帮忙的吗？"
            : "你好！我是{$botName}，今天有什么需要帮忙的吗？";

        return $greeting;
    }

    /**
     * 构建首次见面的引导欢迎语
     * 通过通讯录查找用户真实姓名，营造温暖的第一印象并引导个性化设置
     *
     * @param  string  $userId  用户 ID
     * @return string 首次引导欢迎语
     */
    private function buildFirstMeetGreeting(string $userId): string
    {
        // 从通讯录查找用户真实姓名
        $contact = Contact::where('userid', $userId)->first();
        $userName = $contact?->name;

        $greeting = $userName
            ? "你好，{$userName}！👋"
            : '你好！👋';

        return <<<WELCOME
{$greeting}
我是刚上线的 AI 助手，还没有名字和身份，这是我们的第一次见面。

我能帮你做这些事：
📅 会议 & 日程 —— 创建、查询、取消，一句话搞定
👥 联系人 & 群聊 —— 搜索同事、建群、发消息
🏢 会议室 —— 查空闲、预定、取消

在开始之前，我们可以先花几秒钟互相熟悉一下：
1. 给我起个名字？—— 比如"小微""阿布"，或者你喜欢的
2. 我该怎么称呼你？—— "老板""哥"，还是直接叫名字？
3. 你喜欢什么沟通风格？—— 简洁直接？轻松随意？

直接告诉我就行，比如「叫你小微，叫我老板，轻松点别太正式」
当然，你也可以跳过这些，直接跟我说事儿~
WELCOME;
    }

    /**
     * 判断 profile 是否所有业务字段都为空
     *
     * @param  UserProfile  $profile  profile 记录
     * @return bool 所有字段为空返回 true
     */
    private function isProfileEmpty(UserProfile $profile): bool
    {
        foreach (self::ALLOWED_FIELDS as $field) {
            if (! empty($profile->{$field})) {
                return false;
            }
        }

        return true;
    }
}

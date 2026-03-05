<?php

namespace App\Services;

use App\Models\UserMemory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class UserMemoryService
{
    /** 每个用户最多存储的记忆条数 */
    public const MAX_MEMORIES_PER_USER = 50;

    /** 注入 prompt 的最大字符数（约 500 tokens） */
    public const MAX_PROMPT_CHARS = 1500;

    /** 有效的模块标识 */
    public const VALID_MODULES = ['preferences', 'relationships', 'schedule', 'general'];

    /** 模块中文名称映射 */
    private const MODULE_LABELS = [
        'preferences' => '用户偏好',
        'relationships' => '人际关系',
        'schedule' => '日程习惯',
        'general' => '通用',
    ];

    /** 关键词重叠比例阈值，超过则视为同主题更新 */
    private const SIMILARITY_THRESHOLD = 0.6;

    /**
     * 保存用户记忆
     * 如果已有相似内容，更新而非新建；超出上限时返回错误
     *
     * @param  string  $userId  用户 ID
     * @param  string  $module  模块标识
     * @param  string  $content  原子事实内容
     * @param  string  $source  来源（explicit/inferred）
     * @return array{status: string, memory_id?: int, message: string}
     */
    public function save(string $userId, string $module, string $content, string $source = 'explicit'): array
    {
        if (! in_array($module, self::VALID_MODULES)) {
            return [
                'status' => 'error',
                'message' => "无效的模块标识「{$module}」，可选值：".implode('、', self::VALID_MODULES),
            ];
        }

        // 查找同主题的已有记忆
        $existing = $this->findSimilar($userId, $module, $content);

        if ($existing) {
            $existing->update(['content' => $content, 'source' => $source]);
            Log::debug('UserMemoryService::save 更新已有记忆', [
                'memory_id' => $existing->id,
                'user_id' => $userId,
                'module' => $module,
                'content' => $content,
            ]);

            return [
                'status' => 'updated',
                'memory_id' => $existing->id,
                'message' => '已更新记忆',
            ];
        }

        // 检查上限
        $count = UserMemory::where('user_id', $userId)->count();
        if ($count >= self::MAX_MEMORIES_PER_USER) {
            return [
                'status' => 'error',
                'message' => '记忆条数已达上限（'.self::MAX_MEMORIES_PER_USER.'），请先删除不需要的记忆',
            ];
        }

        $memory = UserMemory::create([
            'user_id' => $userId,
            'module' => $module,
            'content' => $content,
            'source' => $source,
        ]);

        Log::debug('UserMemoryService::save 创建新记忆', [
            'memory_id' => $memory->id,
            'user_id' => $userId,
            'module' => $module,
            'content' => $content,
        ]);

        return [
            'status' => 'created',
            'memory_id' => $memory->id,
            'message' => '已保存记忆',
        ];
    }

    /**
     * 删除用户记忆（软删除）
     * 校验记忆归属当前用户
     *
     * @param  string  $userId  用户 ID
     * @param  int  $memoryId  记忆 ID
     * @return array{status: string, message: string}
     */
    public function delete(string $userId, int $memoryId): array
    {
        $memory = UserMemory::where('id', $memoryId)
            ->where('user_id', $userId)
            ->first();

        if (! $memory) {
            return [
                'status' => 'not_found',
                'message' => "未找到 ID 为 {$memoryId} 的记忆，或该记忆不属于当前用户",
            ];
        }

        $memory->delete();

        Log::debug('UserMemoryService::delete 删除记忆', [
            'memory_id' => $memoryId,
            'user_id' => $userId,
            'content' => $memory->content,
        ]);

        return [
            'status' => 'deleted',
            'message' => "已删除记忆「{$memory->content}」",
        ];
    }

    /**
     * 获取用户所有记忆，按模块分组
     *
     * @param  string  $userId  用户 ID
     * @return Collection<string, Collection<int, UserMemory>> 模块名 → 记忆列表
     */
    public function getByUser(string $userId): Collection
    {
        return UserMemory::where('user_id', $userId)
            ->orderBy('module')
            ->orderBy('id')
            ->get()
            ->groupBy('module');
    }

    /**
     * 格式化用户记忆为 system prompt 注入文本
     * 带 [Mn] ID 标签，按模块分组，超出 MAX_PROMPT_CHARS 截断
     *
     * @param  string  $userId  用户 ID
     * @return string 格式化文本，无记忆时返回空字符串
     */
    public function formatForPrompt(string $userId): string
    {
        $grouped = $this->getByUser($userId);

        if ($grouped->isEmpty()) {
            return '';
        }

        // 更新 hit_count 和 last_hit_at
        UserMemory::where('user_id', $userId)->increment('hit_count', 1, ['last_hit_at' => now()]);

        $lines = ['## 用户记忆'];
        $totalChars = mb_strlen($lines[0]);

        foreach (self::VALID_MODULES as $module) {
            if (! $grouped->has($module)) {
                continue;
            }

            $label = self::MODULE_LABELS[$module];
            $sectionHeader = "### {$label}";
            $totalChars += mb_strlen($sectionHeader) + 1;

            if ($totalChars > self::MAX_PROMPT_CHARS) {
                break;
            }

            $lines[] = $sectionHeader;

            foreach ($grouped[$module] as $memory) {
                $line = "- [M{$memory->id}] {$memory->content}";
                $lineChars = mb_strlen($line) + 1;

                if ($totalChars + $lineChars > self::MAX_PROMPT_CHARS) {
                    break 2;
                }

                $lines[] = $line;
                $totalChars += $lineChars;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 查找同用户同模块下的相似记忆（关键词重叠 ≥ 60%）
     *
     * @param  string  $userId  用户 ID
     * @param  string  $module  模块标识
     * @param  string  $content  新内容
     * @return UserMemory|null 相似记忆，无则返回 null
     */
    private function findSimilar(string $userId, string $module, string $content): ?UserMemory
    {
        $newKeywords = $this->extractKeywords($content);

        if (empty($newKeywords)) {
            return null;
        }

        $memories = UserMemory::where('user_id', $userId)
            ->where('module', $module)
            ->get();

        foreach ($memories as $memory) {
            $existingKeywords = $this->extractKeywords($memory->content);

            if (empty($existingKeywords)) {
                continue;
            }

            $intersection = array_intersect($newKeywords, $existingKeywords);
            $union = array_unique(array_merge($newKeywords, $existingKeywords));
            $overlap = count($intersection) / count($union);

            if ($overlap >= self::SIMILARITY_THRESHOLD) {
                return $memory;
            }
        }

        return null;
    }

    /**
     * 提取内容中的关键词（中文按字切分，英文/数字按词切分）
     *
     * @param  string  $content  文本内容
     * @return array<int, string> 关键词数组
     */
    private function extractKeywords(string $content): array
    {
        // 提取中文字符和英文/数字词（自动忽略标点和空白）
        preg_match_all('/[\x{4e00}-\x{9fff}]|[a-zA-Z0-9]+/u', $content, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }
}

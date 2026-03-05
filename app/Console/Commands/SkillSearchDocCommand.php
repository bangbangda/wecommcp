<?php

namespace App\Console\Commands;

use App\Models\WecomApiDoc;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SkillSearchDocCommand extends Command
{
    protected $signature = 'skill:search-doc
        {keyword? : 搜索关键词（匹配标题和分类路径）}
        {--id= : 查看指定 doc_id 的完整文档内容}
        {--category= : 按分类路径过滤（模糊匹配）}
        {--content : 同时搜索文档正文内容（较慢）}
        {--tree= : 显示指定 category_id 下的文档树}
        {--limit=20 : 搜索结果数量上限}';

    protected $description = '【Skill 专用】搜索企业微信 API 文档（数据来源：wecom_api_docs 表）';

    /**
     * 搜索结果摘要截取长度
     */
    private const SUMMARY_LENGTH = 150;

    /**
     * 执行命令
     * 根据参数选择不同的查询模式：查看文档、浏览文档树、搜索文档
     *
     * @return int 命令退出码
     */
    public function handle(): int
    {
        // 模式1: 查看指定文档的完整内容
        if ($docId = $this->option('id')) {
            return $this->showDocument((int) $docId);
        }

        // 模式2: 浏览文档树
        if ($categoryId = $this->option('tree')) {
            return $this->showTree((int) $categoryId);
        }

        // 模式3: 关键词搜索
        $keyword = $this->argument('keyword');
        if (! $keyword) {
            $this->showTopModules();

            return self::SUCCESS;
        }

        return $this->searchDocs($keyword);
    }

    /**
     * 显示指定文档的完整内容
     *
     * @param  int  $docId  文档 ID（doc_id 字段）
     * @return int 命令退出码
     */
    private function showDocument(int $docId): int
    {
        $doc = WecomApiDoc::where('doc_id', $docId)->first();

        if (! $doc) {
            $this->error("未找到 doc_id={$docId} 的文档。");

            return self::FAILURE;
        }

        $this->info("# {$doc->title}");
        $this->line("分类路径: {$doc->category_path}");
        $this->line("doc_id: {$doc->doc_id} | type: {$doc->type} | status: {$doc->status}");
        $this->newLine();

        if ($doc->parsed_content) {
            $this->line($doc->parsed_content);
        } else {
            $this->warn('该文档暂无内容（未抓取或为分类节点）。');
        }

        return self::SUCCESS;
    }

    /**
     * 显示指定分类下的文档树结构
     * 递归展示子分类和文档，用缩进表示层级
     *
     * @param  int  $categoryId  分类节点的 category_id
     * @return int 命令退出码
     */
    private function showTree(int $categoryId): int
    {
        $root = WecomApiDoc::where('category_id', $categoryId)->first();

        if (! $root) {
            $this->error("未找到 category_id={$categoryId} 的分类。");

            return self::FAILURE;
        }

        $this->info("# {$root->title} (category_id={$categoryId})");
        $this->newLine();

        $this->renderTree($categoryId, 0);

        return self::SUCCESS;
    }

    /**
     * 递归渲染文档树
     * 分类节点显示为目录（带 category_id），文档节点显示为文件（带 doc_id）
     *
     * @param  int  $parentId  父节点的 category_id
     * @param  int  $depth  当前缩进深度
     */
    private function renderTree(int $parentId, int $depth): void
    {
        $children = WecomApiDoc::where('parent_id', $parentId)
            ->orderBy('type')
            ->orderBy('doc_id')
            ->get();

        foreach ($children as $child) {
            $indent = str_repeat('  ', $depth);
            $icon = $child->type === 0 ? '[DIR]' : '[DOC]';
            $id = $child->type === 0 ? "category_id={$child->category_id}" : "doc_id={$child->doc_id}";
            $status = $child->type === 1 ? ($child->status === 1 ? '' : ' (未抓取)') : '';

            $this->line("{$indent}{$icon} {$child->title} ({$id}){$status}");

            // 递归展开分类节点
            if ($child->type === 0) {
                $this->renderTree($child->category_id, $depth + 1);
            }
        }
    }

    /**
     * 显示顶级模块列表（不带关键词时的默认行为）
     * 列出"服务端API"下的所有一级模块及其文档数量
     */
    private function showTopModules(): void
    {
        $this->info('企业微信 API 文档 - 顶级模块列表');
        $this->info('用法: php artisan skill:search-doc <关键词>');
        $this->newLine();

        $modules = WecomApiDoc::where('parent_id', 90135)
            ->where('type', 0)
            ->orderBy('doc_id')
            ->get();

        $rows = $modules->map(function ($m) {
            $docCount = WecomApiDoc::where('type', 1)
                ->where('status', 1)
                ->where('category_path', 'LIKE', "%{$m->title}%")
                ->count();

            return [
                $m->category_id,
                $m->title,
                $docCount,
            ];
        });

        $this->table(['category_id', '模块名', '已抓取文档数'], $rows);

        $this->newLine();
        $this->line('提示: 使用 --tree=<category_id> 浏览模块下的文档树');
    }

    /**
     * 按关键词搜索文档
     * 搜索策略：先匹配标题和分类路径，可选搜索正文内容
     *
     * @param  string  $keyword  搜索关键词
     * @return int 命令退出码
     */
    private function searchDocs(string $keyword): int
    {
        $limit = (int) $this->option('limit');
        $category = $this->option('category');
        $searchContent = $this->option('content');

        // 构建查询：只搜索文档页（type=1）
        $query = WecomApiDoc::where('type', 1)->where('status', 1);

        // 分类过滤
        if ($category) {
            $query->where('category_path', 'LIKE', "%{$category}%");
        }

        // 关键词匹配标题和分类路径
        $query->where(function ($q) use ($keyword, $searchContent) {
            $q->where('title', 'LIKE', "%{$keyword}%")
                ->orWhere('category_path', 'LIKE', "%{$keyword}%");

            if ($searchContent) {
                $q->orWhere('parsed_content', 'LIKE', "%{$keyword}%");
            }
        });

        $results = $query->orderBy('doc_id')->limit($limit)->get();

        if ($results->isEmpty()) {
            $this->warn("未找到与「{$keyword}」相关的文档。");
            $this->line('建议: 尝试更短的关键词，或使用 --content 搜索正文内容。');

            return self::SUCCESS;
        }

        $this->info("搜索「{$keyword}」找到 {$results->count()} 条结果：");
        $this->newLine();

        $rows = $results->map(function ($doc) use ($keyword) {
            $summary = $this->extractSummary($doc->parsed_content, $keyword);

            return [
                $doc->doc_id,
                Str::limit($doc->title, 30),
                Str::limit($doc->category_path, 50),
                $summary,
            ];
        });

        $this->table(['doc_id', '标题', '分类路径', '摘要'], $rows);

        $this->newLine();
        $this->line('提示: 使用 --id=<doc_id> 查看完整文档内容。');

        return self::SUCCESS;
    }

    /**
     * 从文档内容中提取包含关键词的摘要
     * 优先展示关键词附近的上下文，找不到则取开头内容
     *
     * @param  string|null  $content  文档正文
     * @param  string  $keyword  搜索关键词
     * @return string 摘要文本
     */
    private function extractSummary(?string $content, string $keyword): string
    {
        if (! $content) {
            return '-';
        }

        // 去除 markdown 格式便于摘要展示
        $plain = preg_replace('/[#*`|>\-\[\]()]/', '', $content);
        $plain = preg_replace('/\s+/', ' ', $plain);

        // 尝试找到关键词所在位置
        $pos = mb_stripos($plain, $keyword);
        if ($pos !== false) {
            $start = max(0, $pos - 30);
            $excerpt = mb_substr($plain, $start, self::SUMMARY_LENGTH);

            return ($start > 0 ? '...' : '').trim($excerpt).'...';
        }

        return Str::limit(trim($plain), self::SUMMARY_LENGTH);
    }
}

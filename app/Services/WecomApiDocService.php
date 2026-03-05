<?php

namespace App\Services;

use App\Models\WecomApiDoc;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WecomApiDocService
{
    /**
     * cookie 文件路径
     */
    private const COOKIE_FILE = 'skills/docs/cookie.txt';

    /**
     * docConfig 文件路径
     */
    private const DOC_CONFIG_FILE = 'skills/docs/docConfig.json';

    /**
     * fetchCnt 接口 URL
     */
    private const FETCH_URL = 'https://developer.work.weixin.qq.com/docFetch/fetchCnt';

    /**
     * 加载 docConfig.json，解析为数组
     *
     * @return array 文档配置数组
     */
    public function loadDocConfig(): array
    {
        $path = base_path(self::DOC_CONFIG_FILE);
        $json = file_get_contents($path);

        return json_decode($json, true);
    }

    /**
     * 将 docConfig 导入到 wecom_api_docs 表
     * 构建树结构，计算每个节点的 category_path
     *
     * @return int 导入的记录数
     */
    public function importDocConfig(): int
    {
        $items = $this->loadDocConfig();

        // 构建 category_id => item 映射，用于查找父节点
        $categoryMap = [];
        foreach ($items as $item) {
            $categoryMap[$item['category_id']] = $item;
        }

        $count = 0;
        foreach ($items as $item) {
            $categoryPath = $this->buildCategoryPath($item, $categoryMap);

            // doc_id=0 的分类节点，使用 category_id 作为唯一标识
            $docId = $item['doc_id'] > 0 ? $item['doc_id'] : $item['category_id'];

            WecomApiDoc::updateOrCreate(
                ['doc_id' => $docId],
                [
                    'category_id' => $item['category_id'],
                    'parent_id' => $item['parent_id'],
                    'title' => $item['title'],
                    'category_path' => $categoryPath,
                    'type' => $item['type'],
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * 递归构建完整分类路径
     *
     * @param  array  $item  当前节点
     * @param  array  $categoryMap  category_id => item 映射
     * @return string 完整分类路径（如"服务端API > 通讯录管理 > 成员管理 > 创建成员"）
     */
    private function buildCategoryPath(array $item, array $categoryMap): string
    {
        $path = [$item['title']];
        $current = $item;

        while ($current['parent_id'] > 0 && isset($categoryMap[$current['parent_id']])) {
            $current = $categoryMap[$current['parent_id']];
            array_unshift($path, $current['title']);
        }

        return implode(' > ', $path);
    }

    /**
     * 从 cookie 文件读取 cookie 字符串
     *
     * @return string cookie 字符串
     *
     * @throws \RuntimeException cookie 文件不存在或为空时抛出异常
     */
    public function loadCookie(): string
    {
        $path = base_path(self::COOKIE_FILE);

        if (! file_exists($path)) {
            throw new \RuntimeException("Cookie 文件不存在: {$path}\n请将浏览器 cookie 保存到该文件。");
        }

        $cookie = trim(file_get_contents($path));

        if (empty($cookie)) {
            throw new \RuntimeException("Cookie 文件为空: {$path}\n请将浏览器 cookie 保存到该文件。");
        }

        return $cookie;
    }

    /**
     * 调用 fetchCnt 接口获取单个文档内容
     *
     * @param  int  $docId  文档 ID
     * @return array 接口返回的 JSON 数据
     *
     * @throws \RuntimeException 请求失败时抛出异常
     */
    public function fetchDocContent(int $docId): array
    {
        $cookie = $this->loadCookie();
        $random = rand(100000, 999999);

        $url = self::FETCH_URL.'?'.http_build_query([
            'lang' => 'zh_CN',
            'ajax' => 1,
            'f' => 'json',
            'random' => $random,
        ]);

        Log::debug('WecomApiDoc 抓取请求', ['doc_id' => $docId, 'url' => $url]);

        $response = Http::withHeaders([
            'Accept' => 'application/json, text/plain, */*',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Cookie' => $cookie,
            'Origin' => 'https://developer.work.weixin.qq.com',
            'Referer' => 'https://developer.work.weixin.qq.com/document/path/90000',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
        ])->asForm()->post($url, [
            'doc_id' => $docId,
        ]);

        Log::debug('WecomApiDoc 抓取响应', [
            'doc_id' => $docId,
            'status' => $response->status(),
            'body_length' => strlen($response->body()),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("请求失败，HTTP {$response->status()}，doc_id={$docId}");
        }

        $data = $response->json();

        if ($data === null) {
            throw new \RuntimeException("响应 JSON 解析失败，doc_id={$docId}");
        }

        return $data;
    }

    /**
     * 抓取单个文档并更新数据库记录
     * 包含重试逻辑，失败 2 次后标记 status=2
     *
     * @param  WecomApiDoc  $doc  文档记录
     * @param  int  $maxRetries  最大重试次数
     * @return bool 是否抓取成功
     */
    public function fetchAndSave(WecomApiDoc $doc, int $maxRetries = 2): bool
    {
        $attempts = 0;
        $lastError = null;

        while ($attempts <= $maxRetries) {
            try {
                $data = $this->fetchDocContent($doc->doc_id);

                $rawJson = json_encode($data, JSON_UNESCAPED_UNICODE);

                $doc->update([
                    'raw_content' => $rawJson,
                    'parsed_content' => $this->parseContent($rawJson),
                    'status' => 1,
                    'fetched_at' => now(),
                ]);

                return true;
            } catch (\Exception $e) {
                $lastError = $e;
                $attempts++;
                Log::warning("WecomApiDoc 抓取失败 (第{$attempts}次)", [
                    'doc_id' => $doc->doc_id,
                    'error' => $e->getMessage(),
                ]);

                if ($attempts <= $maxRetries) {
                    sleep(2);
                }
            }
        }

        // 所有重试均失败，标记为失败
        $doc->update(['status' => 2]);
        Log::error('WecomApiDoc 抓取最终失败', [
            'doc_id' => $doc->doc_id,
            'error' => $lastError?->getMessage(),
        ]);

        return false;
    }

    /**
     * 解析接口返回的 JSON，提取 Markdown 正文内容
     * 接口返回的 data.content_md 字段即为 Markdown 格式的文档内容
     *
     * @param  string  $rawJson  原始 JSON 字符串
     * @return string|null 解析后的 Markdown 正文内容
     */
    public function parseContent(string $rawJson): ?string
    {
        $data = json_decode($rawJson, true);

        if (! $data || ! isset($data['data']['content_md'])) {
            return null;
        }

        return $data['data']['content_md'];
    }
}

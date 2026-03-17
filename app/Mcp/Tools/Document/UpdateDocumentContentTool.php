<?php

namespace App\Mcp\Tools\Document;

use App\Wecom\WecomDocumentClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update_document_content')]
#[Description('编辑企微在线文档的内容。支持三种操作模式：追加内容到文档末尾、替换文档全部内容、在指定位置插入内容。用于将分析结果、日报、工作总结、待办清单等写入文档。典型场景："把分析结果写入文档""在文档末尾追加内容""更新文档内容为最新的日报"。内部会自动获取文档版本和位置信息，无需手动指定。仅支持文档类型（doc_type=3）。重要：文档内容仅支持纯文本，不支持 Markdown 格式，写入时请使用纯文本。')]
class UpdateDocumentContentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'docid' => $schema->string('文档 ID')->required(),
            'content' => $schema->string('要写入的文本内容')->required(),
            'mode' => $schema->string('操作模式：append=追加到末尾（默认），replace=替换全部内容，insert=在指定位置插入'),
            'insert_index' => $schema->integer('插入位置（仅 mode=insert 时需要，通过 get_document_content 获取）'),
        ];
    }

    /**
     * 编辑文档内容
     * 内部自动获取版本号和节点位置，封装底层 batch_update 的复杂度
     */
    public function handle(Request $request, WecomDocumentClient $client): Response
    {
        $data = $request->validate([
            'docid' => 'required|string',
            'content' => 'required|string',
            'mode' => 'nullable|string|in:append,replace,insert',
            'insert_index' => 'nullable|integer|min:0',
        ]);

        $mode = $data['mode'] ?? 'append';
        $docId = $data['docid'];
        $content = $data['content'];

        Log::debug('UpdateDocumentContentTool::handle 收到请求', [
            'docid' => $docId,
            'mode' => $mode,
            'content_length' => mb_strlen($content),
        ]);

        try {
            // 获取当前文档数据（版本号 + 节点位置）
            $docData = $client->getDocumentContent($docId);
            $version = $docData['version'];
            $document = $docData['document'];

            $requests = match ($mode) {
                'replace' => $this->buildReplaceRequests($document, $content),
                'insert' => $this->buildInsertRequests($content, $data['insert_index'] ?? 1),
                default => $this->buildAppendRequests($document, $content), // append
            };

            if (empty($requests)) {
                return Response::text(json_encode([
                    'status' => 'error',
                    'message' => '无法构建编辑操作，请检查文档状态',
                ], JSON_UNESCAPED_UNICODE));
            }

            $client->batchUpdateDocument($docId, $requests, $version);

            $modeLabel = match ($mode) {
                'replace' => '替换',
                'insert' => '插入',
                default => '追加',
            };

            return Response::text(json_encode([
                'status' => 'success',
                'message' => "内容已{$modeLabel}到文档",
                'content_length' => mb_strlen($content),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('UpdateDocumentContentTool::handle 编辑失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '编辑文档失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 构建追加操作：在文档末尾插入文本
     *
     * @param  array  $document  文档节点树
     * @param  string  $content  要追加的内容
     * @return array 操作列表
     */
    private function buildAppendRequests(array $document, string $content): array
    {
        $endIndex = $this->getContentEndIndex($document);

        return [
            [
                'insert_text' => [
                    'text' => $content,
                    'location' => ['index' => $endIndex],
                ],
            ],
        ];
    }

    /**
     * 构建替换操作：清空文档内容后写入新内容
     *
     * @param  array  $document  文档节点树
     * @param  string  $content  新内容
     * @return array 操作列表
     */
    private function buildReplaceRequests(array $document, string $content): array
    {
        $requests = [];

        // 获取正文内容的范围
        $startIndex = $this->getContentStartIndex($document);
        $endIndex = $this->getContentEndIndex($document);

        // 如果有现有内容，先删除
        if ($endIndex > $startIndex) {
            $requests[] = [
                'delete_content' => [
                    'range' => [
                        'start_index' => $startIndex,
                        'length' => $endIndex - $startIndex,
                    ],
                ],
            ];
        }

        // 插入新内容
        $requests[] = [
            'insert_text' => [
                'text' => $content,
                'location' => ['index' => $startIndex],
            ],
        ];

        return $requests;
    }

    /**
     * 构建指定位置插入操作
     *
     * @param  string  $content  要插入的内容
     * @param  int  $index  插入位置
     * @return array 操作列表
     */
    private function buildInsertRequests(string $content, int $index): array
    {
        return [
            [
                'insert_text' => [
                    'text' => $content,
                    'location' => ['index' => $index],
                ],
            ],
        ];
    }

    /**
     * 获取文档正文内容的起始位置
     * 遍历节点树找到 MainStory 的起始位置
     *
     * @param  array  $node  文档根节点
     * @return int 起始 index
     */
    private function getContentStartIndex(array $node): int
    {
        // 查找 MainStory 节点
        foreach ($node['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'MainStory') {
                // MainStory 的第一个子节点的 begin
                foreach ($child['children'] ?? [] as $grandchild) {
                    return $grandchild['begin'] ?? 1;
                }

                return $child['begin'] ?? 1;
            }
        }

        return 1;
    }

    /**
     * 获取文档正文内容的结束位置
     * 用于追加内容时确定写入位置
     *
     * @param  array  $node  文档根节点
     * @return int 结束 index（最后一个内容节点的 end - 1）
     */
    private function getContentEndIndex(array $node): int
    {
        // 查找 MainStory 节点
        foreach ($node['children'] ?? [] as $child) {
            if (($child['type'] ?? '') === 'MainStory') {
                $children = $child['children'] ?? [];
                if (! empty($children)) {
                    $lastChild = end($children);

                    // 在最后一个段落的 end - 1 位置插入（在结束符之前）
                    return max(1, ($lastChild['end'] ?? 1) - 1);
                }

                return max(1, ($child['end'] ?? 1) - 1);
            }
        }

        return max(1, ($node['end'] ?? 1) - 1);
    }
}

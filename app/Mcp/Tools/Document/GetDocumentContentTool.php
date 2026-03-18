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

#[Name('get_document_content')]
#[Description('读取企微在线文档的文本内容。用于分析文档中的工作计划、待办事项、数据报告等，也可用于了解文档当前内容以便后续编辑。典型场景："读取这个文档的内容""看看文档里写了什么""分析一下这个文档的工作计划"。仅支持文档类型（doc_type=3），表格请使用其他方式。')]
class GetDocumentContentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'docid' => $schema->string('文档 ID')->required(),
        ];
    }

    /**
     * 读取文档内容，返回提取的纯文本
     */
    public function handle(Request $request, WecomDocumentClient $client): Response
    {
        $data = $request->validate([
            'docid' => 'required|string',
        ]);

        Log::debug('GetDocumentContentTool::handle 收到请求', $data);

        try {
            $result = $client->getDocumentContent($data['docid']);
            $text = $client->extractText($result['document']);
            $text = trim($text);

            if (empty($text)) {
                return Response::text(json_encode([
                    'status' => 'empty',
                    'message' => '文档内容为空',
                    'version' => $result['version'],
                ], JSON_UNESCAPED_UNICODE));
            }

            return Response::text(json_encode([
                'status' => 'success',
                'version' => $result['version'],
                'content' => $text,
                'content_length' => mb_strlen($text),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('GetDocumentContentTool::handle 读取失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '读取文档内容失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

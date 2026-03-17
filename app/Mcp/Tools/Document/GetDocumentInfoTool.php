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

#[Name('get_document_info')]
#[Description('获取企微文档的基础信息，包括名称、类型、创建时间、修改时间等。当需要确认文档信息时使用。需要提供 docid（通过 create_document 创建时获得）。')]
class GetDocumentInfoTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'docid' => $schema->string('文档 ID（通过 create_document 创建时获得）')->required(),
        ];
    }

    /**
     * 获取文档基础信息
     */
    public function handle(Request $request, WecomDocumentClient $client): Response
    {
        $data = $request->validate([
            'docid' => 'required|string',
        ]);

        Log::debug('GetDocumentInfoTool::handle 收到请求', $data);

        try {
            $info = $client->getDocBaseInfo($data['docid']);

            $typeLabel = match ($info['doc_type'] ?? 0) {
                3 => '文档',
                4 => '表格',
                10 => '智能表格',
                default => '未知',
            };

            return Response::text(json_encode([
                'status' => 'success',
                'doc_info' => [
                    'docid' => $info['docid'] ?? $data['docid'],
                    'doc_name' => $info['doc_name'] ?? '',
                    'doc_type' => $typeLabel,
                    'create_time' => isset($info['create_time']) ? date('Y-m-d H:i:s', $info['create_time']) : '',
                    'modify_time' => isset($info['modify_time']) ? date('Y-m-d H:i:s', $info['modify_time']) : '',
                ],
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('GetDocumentInfoTool::handle 获取失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '获取文档信息失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

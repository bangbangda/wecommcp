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

#[Name('delete_document')]
#[Description('删除企微文档。删除后不可恢复，请谨慎操作。建议先确认文档信息后再删除。')]
class DeleteDocumentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'docid' => $schema->string('文档 ID')->required(),
        ];
    }

    /**
     * 删除文档
     */
    public function handle(Request $request, WecomDocumentClient $client): Response
    {
        $data = $request->validate([
            'docid' => 'required|string',
        ]);

        Log::debug('DeleteDocumentTool::handle 收到请求', $data);

        try {
            $client->deleteDoc($data['docid']);

            return Response::text(json_encode([
                'status' => 'success',
                'message' => '文档已删除',
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('DeleteDocumentTool::handle 删除失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '删除失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

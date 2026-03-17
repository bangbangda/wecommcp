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

#[Name('rename_document')]
#[Description('重命名企微文档。典型场景："把文档改名为XXX"。')]
class RenameDocumentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'docid' => $schema->string('文档 ID')->required(),
            'new_name' => $schema->string('新的文档名称，最长255个字符')->required(),
        ];
    }

    /**
     * 重命名文档
     */
    public function handle(Request $request, WecomDocumentClient $client): Response
    {
        $data = $request->validate([
            'docid' => 'required|string',
            'new_name' => 'required|string|max:255',
        ]);

        Log::debug('RenameDocumentTool::handle 收到请求', $data);

        try {
            $client->renameDoc($data['docid'], $data['new_name']);

            return Response::text(json_encode([
                'status' => 'success',
                'message' => "文档已重命名为「{$data['new_name']}」",
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('RenameDocumentTool::handle 重命名失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '重命名失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

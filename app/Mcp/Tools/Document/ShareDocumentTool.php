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

#[Name('share_document')]
#[Description('获取企微文档的分享链接，可将链接发送给其他人查看。典型场景："把这个文档分享给我""获取文档链接"。')]
class ShareDocumentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'docid' => $schema->string('文档 ID')->required(),
        ];
    }

    /**
     * 获取文档分享链接
     */
    public function handle(Request $request, WecomDocumentClient $client): Response
    {
        $data = $request->validate([
            'docid' => 'required|string',
        ]);

        Log::debug('ShareDocumentTool::handle 收到请求', $data);

        try {
            $shareUrl = $client->shareDoc($data['docid']);

            return Response::text(json_encode([
                'status' => 'success',
                'share_url' => $shareUrl,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('ShareDocumentTool::handle 获取失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '获取分享链接失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

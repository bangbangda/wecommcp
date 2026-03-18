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

#[Name('create_document')]
#[Description('创建企业微信在线文档或表格。用于将工作总结、分析报告、日报、会议纪要、待办清单等内容保存为企微文档，供团队成员查看和协作。典型场景："帮我创建一个本周工作总结文档""把分析结果保存为文档""创建一个项目进度表格"。创建前必须先询问用户是否需要在企微客户端编辑该文档：如果需要编辑，则必须通过 admin_users 指定编辑人（可通过 search_contacts 获取 userid）；如果仅查看不编辑，则不需要设置 admin_users。创建后会返回文档 ID 和访问链接，如需写入内容请使用 update_document_content 工具。文档内容仅支持纯文本，不支持 Markdown 格式。')]
class CreateDocumentTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'doc_name' => $schema->string('文档名称，最长255个字符')->required(),
            'doc_type' => $schema->integer('文档类型：3=文档（默认），4=表格，10=智能表格'),
            'admin_users' => $schema->array('文档管理员的 userid 列表（设置后该用户可在企微客户端编辑文档），仅在用户确认需要编辑时才设置，可通过 search_contacts 获取 userid'),
        ];
    }

    /**
     * 创建企微在线文档
     */
    public function handle(Request $request, WecomDocumentClient $client): Response
    {
        $data = $request->validate([
            'doc_name' => 'required|string|max:255',
            'doc_type' => 'nullable|integer|in:3,4,10',
            'admin_users' => 'nullable|array',
        ]);

        $docType = $data['doc_type'] ?? 3;
        $adminUsers = $data['admin_users'] ?? [];

        Log::debug('CreateDocumentTool::handle 收到请求', $data);

        try {
            $result = $client->createDoc($docType, $data['doc_name'], $adminUsers);

            $typeLabel = match ($docType) {
                3 => '文档',
                4 => '表格',
                10 => '智能表格',
                default => '文档',
            };

            return Response::text(json_encode([
                'status' => 'success',
                'message' => "{$typeLabel}「{$data['doc_name']}」创建成功",
                'docid' => $result['docid'],
                'url' => $result['url'],
                'doc_type' => $docType,
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Log::error('CreateDocumentTool::handle 创建失败', ['error' => $e->getMessage()]);

            return Response::text(json_encode([
                'status' => 'error',
                'message' => '创建文档失败：'.$e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}

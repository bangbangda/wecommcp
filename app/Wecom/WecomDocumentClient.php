<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use Illuminate\Support\Facades\Log;

/**
 * 企微文档 API 客户端
 * 支持文档/表格/智能表格的创建、管理和内容操作
 */
class WecomDocumentClient
{
    public function __construct(
        private WecomManager $manager,
    ) {}

    /**
     * 新建文档
     * API: POST /cgi-bin/wedoc/create_doc
     * 文档 doc_id: 43939
     *
     * @param  int  $docType  文档类型：3=文档, 4=表格, 10=智能表格
     * @param  string  $docName  文档名称（最长255字符）
     * @param  array  $adminUsers  管理员 userid 列表
     * @param  string|null  $spaceId  空间 ID
     * @param  string|null  $fatherId  父目录 ID
     * @return array{docid: string, url: string} 文档 ID 和访问链接
     *
     * @throws WecomApiException
     */
    public function createDoc(int $docType, string $docName, array $adminUsers = [], ?string $spaceId = null, ?string $fatherId = null): array
    {
        $params = [
            'doc_type' => $docType,
            'doc_name' => $docName,
        ];

        if (! empty($adminUsers)) {
            $params['admin_users'] = $adminUsers;
        }
        if ($spaceId !== null) {
            $params['spaceid'] = $spaceId;
            $params['fatherid'] = $fatherId ?? $spaceId;
        }

        Log::debug('WecomDocumentClient::createDoc 请求参数', $params);

        $response = $this->request('/cgi-bin/wedoc/create_doc', $params);

        Log::debug('WecomDocumentClient::createDoc 返回结果', $response);

        return [
            'docid' => $response['docid'] ?? '',
            'url' => $response['url'] ?? '',
        ];
    }

    /**
     * 获取文档基础信息
     * API: POST /cgi-bin/wedoc/get_doc_base_info
     * 文档 doc_id: 44590
     *
     * @param  string  $docId  文档 ID
     * @return array 文档基础信息
     *
     * @throws WecomApiException
     */
    public function getDocBaseInfo(string $docId): array
    {
        $params = ['docid' => $docId];

        Log::debug('WecomDocumentClient::getDocBaseInfo 请求参数', $params);

        $response = $this->request('/cgi-bin/wedoc/get_doc_base_info', $params);

        Log::debug('WecomDocumentClient::getDocBaseInfo 返回结果', $response);

        return $response['doc_base_info'] ?? [];
    }

    /**
     * 获取文档分享链接
     * API: POST /cgi-bin/wedoc/doc_share
     * 文档 doc_id: 44589
     *
     * @param  string  $docId  文档 ID
     * @return string 分享链接
     *
     * @throws WecomApiException
     */
    public function shareDoc(string $docId): string
    {
        $params = ['docid' => $docId];

        Log::debug('WecomDocumentClient::shareDoc 请求参数', $params);

        $response = $this->request('/cgi-bin/wedoc/doc_share', $params);

        Log::debug('WecomDocumentClient::shareDoc 返回结果', $response);

        return $response['share_url'] ?? '';
    }

    /**
     * 重命名文档
     * API: POST /cgi-bin/wedoc/rename_doc
     * 文档 doc_id: 44593
     *
     * @param  string  $docId  文档 ID
     * @param  string  $newName  新名称（最长255字符）
     *
     * @throws WecomApiException
     */
    public function renameDoc(string $docId, string $newName): void
    {
        $params = [
            'docid' => $docId,
            'new_name' => $newName,
        ];

        Log::debug('WecomDocumentClient::renameDoc 请求参数', $params);

        $response = $this->request('/cgi-bin/wedoc/rename_doc', $params);

        Log::debug('WecomDocumentClient::renameDoc 返回结果', $response);
    }

    /**
     * 删除文档
     * API: POST /cgi-bin/wedoc/del_doc
     * 文档 doc_id: 44592
     *
     * @param  string  $docId  文档 ID
     *
     * @throws WecomApiException
     */
    public function deleteDoc(string $docId): void
    {
        $params = ['docid' => $docId];

        Log::debug('WecomDocumentClient::deleteDoc 请求参数', $params);

        $response = $this->request('/cgi-bin/wedoc/del_doc', $params);

        Log::debug('WecomDocumentClient::deleteDoc 返回结果', $response);
    }

    /**
     * 获取文档内容数据
     * API: POST /cgi-bin/wedoc/document/get
     * 文档 doc_id: 44378
     *
     * @param  string  $docId  文档 ID
     * @return array{version: int, document: array} 版本号和文档节点树
     *
     * @throws WecomApiException
     */
    public function getDocumentContent(string $docId): array
    {
        $params = ['docid' => $docId];

        Log::debug('WecomDocumentClient::getDocumentContent 请求参数', $params);

        $response = $this->request('/cgi-bin/wedoc/document/get', $params);

        Log::debug('WecomDocumentClient::getDocumentContent 返回结果（摘要）', [
            'version' => $response['version'] ?? null,
            'has_document' => isset($response['document']),
        ]);

        return [
            'version' => $response['version'] ?? 0,
            'document' => $response['document'] ?? [],
        ];
    }

    /**
     * 批量编辑文档内容
     * API: POST /cgi-bin/wedoc/document/batch_update
     * 文档 doc_id: 44293
     *
     * @param  string  $docId  文档 ID
     * @param  array  $requests  操作列表（最多30个）
     * @param  int|null  $version  文档版本号
     *
     * @throws WecomApiException
     */
    public function batchUpdateDocument(string $docId, array $requests, ?int $version = null): void
    {
        $params = [
            'docid' => $docId,
            'requests' => $requests,
        ];

        if ($version !== null) {
            $params['version'] = $version;
        }

        Log::debug('WecomDocumentClient::batchUpdateDocument 请求参数', [
            'docid' => $docId,
            'version' => $version,
            'operations_count' => count($requests),
        ]);

        $response = $this->request('/cgi-bin/wedoc/document/batch_update', $params);

        Log::debug('WecomDocumentClient::batchUpdateDocument 返回结果', $response);
    }

    /**
     * 发送 API 请求并检查响应
     *
     * @param  string  $uri  API 路径
     * @param  array  $params  请求参数
     * @return array 响应数据
     *
     * @throws WecomApiException
     */
    private function request(string $uri, array $params): array
    {
        $client = $this->manager->app('agent')->getClient();
        $response = $client->postJson($uri, $params)->toArray();

        $errcode = $response['errcode'] ?? 0;
        if ($errcode !== 0) {
            $errmsg = $response['errmsg'] ?? '未知错误';
            Log::error('WecomDocumentClient API 错误', [
                'uri' => $uri,
                'errcode' => $errcode,
                'errmsg' => $errmsg,
            ]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }

        return $response;
    }

    /**
     * 从文档节点树中提取纯文本内容
     * 递归遍历节点，拼接所有 Text 节点的文本
     *
     * @param  array  $node  文档节点
     * @return string 纯文本内容
     */
    public function extractText(array $node): string
    {
        $text = '';

        if (($node['type'] ?? '') === 'Text' && isset($node['text'])) {
            $text .= $node['text'];
        }

        foreach ($node['children'] ?? [] as $child) {
            $childText = $this->extractText($child);
            $text .= $childText;

            // Paragraph 节点之间加换行
            if (in_array($child['type'] ?? '', ['Paragraph', 'TableRow'])) {
                $text .= "\n";
            }
        }

        return $text;
    }

    /**
     * 获取文档内容的末尾位置（用于追加内容）
     *
     * @param  array  $node  文档根节点
     * @return int 末尾位置 index
     */
    public function getDocumentEndIndex(array $node): int
    {
        return $node['end'] ?? 1;
    }
}

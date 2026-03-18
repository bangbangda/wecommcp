<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use Illuminate\Support\Facades\Log;

/**
 * 企微汇报 API 客户端
 * 支持拉取汇报记录单号、获取详情、获取统计数据
 */
class WecomJournalClient
{
    public function __construct(
        private WecomManager $manager,
    ) {}

    /**
     * 批量获取汇报记录单号
     * API: POST /cgi-bin/oa/journal/get_record_list
     * 文档 doc_id: 25844
     *
     * @param  int  $startTime  开始时间（Unix 时间戳）
     * @param  int  $endTime  结束时间（与开始时间间隔不超过一个月）
     * @param  int  $cursor  游标，首次传 0
     * @param  int  $limit  拉取条数，最多 100
     * @param  array  $filters  过滤条件 [['key' => 'template_id', 'value' => 'xxx']]
     * @return array{journaluuid_list: string[], next_cursor: int, endflag: int}
     *
     * @throws WecomApiException
     */
    public function getRecordList(int $startTime, int $endTime, int $cursor = 0, int $limit = 100, array $filters = []): array
    {
        $params = [
            'starttime' => $startTime,
            'endtime' => $endTime,
            'cursor' => $cursor,
            'limit' => $limit,
        ];

        if (! empty($filters)) {
            $params['filters'] = $filters;
        }

        Log::debug('WecomJournalClient::getRecordList 请求参数', $params);

        $response = $this->request('/cgi-bin/oa/journal/get_record_list', $params);

        Log::debug('WecomJournalClient::getRecordList 返回结果', [
            'count' => count($response['journaluuid_list'] ?? []),
            'endflag' => $response['endflag'] ?? null,
        ]);

        return [
            'journaluuid_list' => $response['journaluuid_list'] ?? [],
            'next_cursor' => $response['next_cursor'] ?? 0,
            'endflag' => $response['endflag'] ?? 1,
        ];
    }

    /**
     * 获取汇报记录详情
     * API: POST /cgi-bin/oa/journal/get_record_detail
     * 文档 doc_id: 25845
     *
     * @param  string  $journalUuid  汇报记录 ID
     * @return array 汇报详情（info 对象）
     *
     * @throws WecomApiException
     */
    public function getRecordDetail(string $journalUuid): array
    {
        $params = ['journaluuid' => $journalUuid];

        Log::debug('WecomJournalClient::getRecordDetail 请求参数', $params);

        $response = $this->request('/cgi-bin/oa/journal/get_record_detail', $params);

        Log::debug('WecomJournalClient::getRecordDetail 返回结果（摘要）', [
            'template_name' => $response['info']['template_name'] ?? '',
            'submitter' => $response['info']['submitter']['userid'] ?? '',
        ]);

        return $response['info'] ?? [];
    }

    /**
     * 获取汇报统计数据
     * API: POST /cgi-bin/oa/journal/get_stat_list
     * 文档 doc_id: 25846
     *
     * @param  string  $templateId  汇报表单 ID
     * @param  int  $startTime  开始时间
     * @param  int  $endTime  结束时间（间隔最长一年）
     * @return array stat_list 数组
     *
     * @throws WecomApiException
     */
    public function getStatList(string $templateId, int $startTime, int $endTime): array
    {
        $params = [
            'template_id' => $templateId,
            'starttime' => $startTime,
            'endtime' => $endTime,
        ];

        Log::debug('WecomJournalClient::getStatList 请求参数', $params);

        $response = $this->request('/cgi-bin/oa/journal/get_stat_list', $params);

        Log::debug('WecomJournalClient::getStatList 返回结果', [
            'stat_count' => count($response['stat_list'] ?? []),
        ]);

        return $response['stat_list'] ?? [];
    }

    /**
     * 发送 API 请求并检查响应
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
            Log::error('WecomJournalClient API 错误', [
                'uri' => $uri,
                'errcode' => $errcode,
                'errmsg' => $errmsg,
            ]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }

        return $response;
    }
}

<?php

namespace App\Wecom;

use App\Exceptions\WecomApiException;
use EasyWeChat\Work\Application as WecomApp;
use Illuminate\Support\Facades\Log;

class WecomCheckinClient
{
    public function __construct(private WecomApp $app) {}

    /**
     * 获取打卡记录数据
     * API: POST /cgi-bin/checkin/getcheckindata
     *
     * @param  array  $userIds  用户 userid 列表（单次最多 100 个，超过自动分批）
     * @param  int  $startTime  开始时间（Unix 时间戳）
     * @param  int  $endTime  结束时间（Unix 时间戳）
     * @param  int  $type  打卡类型：1-上下班；2-外出；3-全部
     * @return array 打卡记录列表
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getCheckinData(array $userIds, int $startTime, int $endTime, int $type = 3): array
    {
        $allRecords = [];

        // 时间跨度不超过 30 天，自动分段
        $segmentStart = $startTime;
        $maxSpan = 30 * 86400;

        while ($segmentStart < $endTime) {
            $segmentEnd = min($segmentStart + $maxSpan, $endTime);

            // 用户列表不超过 100 个，自动分批
            $batches = array_chunk($userIds, 100);

            foreach ($batches as $batch) {
                $params = [
                    'opencheckindatatype' => $type,
                    'starttime' => $segmentStart,
                    'endtime' => $segmentEnd,
                    'useridlist' => $batch,
                ];

                Log::debug('WecomCheckinClient::getCheckinData 请求参数', $params);

                $client = $this->app->getClient();
                $response = $client->postJson('/cgi-bin/checkin/getcheckindata', $params)->toArray();

                Log::debug('WecomCheckinClient::getCheckinData 返回结果', $response);

                $this->checkResponse($response);

                $allRecords = array_merge($allRecords, $response['checkindata'] ?? []);
            }

            $segmentStart = $segmentEnd;
        }

        return $allRecords;
    }

    /**
     * 获取打卡日报数据
     * API: POST /cgi-bin/checkin/getcheckin_daydata
     *
     * @param  array  $userIds  用户 userid 列表（单次最多 100 个，超过自动分批）
     * @param  int  $startTime  开始时间（当天 0 点 Unix 时间戳）
     * @param  int  $endTime  结束时间（当天 0 点 Unix 时间戳）
     * @return array 日报数据列表
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getDayData(array $userIds, int $startTime, int $endTime): array
    {
        $allData = [];
        $batches = array_chunk($userIds, 100);

        foreach ($batches as $batch) {
            $params = [
                'starttime' => $startTime,
                'endtime' => $endTime,
                'useridlist' => $batch,
            ];

            Log::debug('WecomCheckinClient::getDayData 请求参数', $params);

            $client = $this->app->getClient();
            $response = $client->postJson('/cgi-bin/checkin/getcheckin_daydata', $params)->toArray();

            Log::debug('WecomCheckinClient::getDayData 返回结果', $response);

            $this->checkResponse($response);

            $allData = array_merge($allData, $response['datas'] ?? []);
        }

        return $allData;
    }

    /**
     * 获取打卡月报数据
     * API: POST /cgi-bin/checkin/getcheckin_monthdata
     *
     * @param  array  $userIds  用户 userid 列表（单次最多 100 个，超过自动分批）
     * @param  int  $startTime  开始时间（当天 0 点 Unix 时间戳）
     * @param  int  $endTime  结束时间（当天 0 点 Unix 时间戳）
     * @return array 月报数据列表
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getMonthData(array $userIds, int $startTime, int $endTime): array
    {
        $allData = [];
        $batches = array_chunk($userIds, 100);

        foreach ($batches as $batch) {
            $params = [
                'starttime' => $startTime,
                'endtime' => $endTime,
                'useridlist' => $batch,
            ];

            Log::debug('WecomCheckinClient::getMonthData 请求参数', $params);

            $client = $this->app->getClient();
            $response = $client->postJson('/cgi-bin/checkin/getcheckin_monthdata', $params)->toArray();

            Log::debug('WecomCheckinClient::getMonthData 返回结果', $response);

            $this->checkResponse($response);

            $allData = array_merge($allData, $response['datas'] ?? []);
        }

        return $allData;
    }

    /**
     * 获取员工打卡规则
     * API: POST /cgi-bin/checkin/getcheckinoption
     *
     * @param  array  $userIds  用户 userid 列表（单次最多 100 个，超过自动分批）
     * @param  int  $datetime  日期当天 0 点的 Unix 时间戳
     * @return array 打卡规则列表
     *
     * @throws WecomApiException API 返回错误时抛出
     */
    public function getCheckinOption(array $userIds, int $datetime): array
    {
        $allInfo = [];
        $batches = array_chunk($userIds, 100);

        foreach ($batches as $batch) {
            $params = [
                'datetime' => $datetime,
                'useridlist' => $batch,
            ];

            Log::debug('WecomCheckinClient::getCheckinOption 请求参数', $params);

            $client = $this->app->getClient();
            $response = $client->postJson('/cgi-bin/checkin/getcheckinoption', $params)->toArray();

            Log::debug('WecomCheckinClient::getCheckinOption 返回结果', $response);

            $this->checkResponse($response);

            $allInfo = array_merge($allInfo, $response['info'] ?? []);
        }

        return $allInfo;
    }

    /**
     * 检查 API 响应，errcode 不为 0 时抛出异常
     *
     * @param  array  $response  API 响应数组
     *
     * @throws WecomApiException errcode != 0 时抛出
     */
    private function checkResponse(array $response): void
    {
        $errcode = $response['errcode'] ?? 0;

        if ($errcode !== 0) {
            $errmsg = $response['errmsg'] ?? '未知错误';
            Log::error('WecomCheckinClient API 错误', ['errcode' => $errcode, 'errmsg' => $errmsg]);

            throw new WecomApiException($errcode, $errmsg, $response);
        }
    }
}

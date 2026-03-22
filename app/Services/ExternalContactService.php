<?php

namespace App\Services;

use App\Exceptions\WecomApiException;
use App\Models\Contact;
use App\Models\ExternalContact;
use App\Models\ExternalContactFollowUser;
use App\Wecom\WecomExternalContactClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Overtrue\Pinyin\Pinyin;

class ExternalContactService
{
    public function __construct(
        private WecomExternalContactClient $client,
    ) {}

    /**
     * 全量同步所有员工的外部联系人
     * 按批次获取内部员工列表，调用批量接口同步
     *
     * @return array{contacts: int, relations: int} 同步的联系人数和跟进关系数
     */
    public function syncAll(): array
    {
        $userids = Contact::pluck('userid')->toArray();

        if (empty($userids)) {
            Log::warning('ExternalContactService::syncAll 内部员工列表为空，跳过同步');

            return ['contacts' => 0, 'relations' => 0];
        }

        Log::info('ExternalContactService::syncAll 开始全量同步', ['employee_count' => count($userids)]);

        $totalContacts = 0;
        $totalRelations = 0;

        // 按 100 个员工一批调用批量接口
        $chunks = array_chunk($userids, 100);

        foreach ($chunks as $chunk) {
            $result = $this->syncByUserBatch($chunk);
            $totalContacts += $result['contacts'];
            $totalRelations += $result['relations'];
        }

        Log::info('ExternalContactService::syncAll 全量同步完成', [
            'contacts' => $totalContacts,
            'relations' => $totalRelations,
        ]);

        return ['contacts' => $totalContacts, 'relations' => $totalRelations];
    }

    /** @var int 批量 upsert 每批条数 */
    private const UPSERT_BATCH_SIZE = 200;

    /**
     * 批量同步指定员工列表的外部联系人
     * 使用 batchGetByUser 接口 + upsert 批量写库
     *
     * @param  array  $useridList  内部员工 userid 列表（最多 100 个）
     * @return array{contacts: int, relations: int} 同步的联系人数和跟进关系数
     */
    public function syncByUserBatch(array $useridList): array
    {
        $contactCount = 0;
        $relationCount = 0;
        $contactBuffer = [];
        $relationBuffer = [];
        $cursor = '';

        do {
            try {
                $response = $this->client->batchGetByUser($useridList, $cursor);
            } catch (WecomApiException $e) {
                Log::error('ExternalContactService::syncByUserBatch 批量获取失败', [
                    'userids' => $useridList,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            foreach ($response['external_contact_list'] ?? [] as $item) {
                $contactData = $item['external_contact'] ?? [];
                $followInfo = $item['follow_info'] ?? [];

                if (empty($contactData['external_userid'])) {
                    continue;
                }

                $contactBuffer[] = $this->buildContactRow($contactData);
                $contactCount++;

                if (! empty($followInfo['userid'])) {
                    $relationBuffer[] = $this->buildRelationRow($contactData['external_userid'], $followInfo);
                    $relationCount++;
                }

                // 达到批量大小时刷入数据库
                if (count($contactBuffer) >= self::UPSERT_BATCH_SIZE) {
                    $this->flushContacts($contactBuffer);
                    $contactBuffer = [];
                }
                if (count($relationBuffer) >= self::UPSERT_BATCH_SIZE) {
                    $this->flushRelations($relationBuffer);
                    $relationBuffer = [];
                }
            }

            $cursor = $response['next_cursor'] ?? '';
        } while (! empty($cursor));

        // 刷入剩余数据
        if (! empty($contactBuffer)) {
            $this->flushContacts($contactBuffer);
        }
        if (! empty($relationBuffer)) {
            $this->flushRelations($relationBuffer);
        }

        return ['contacts' => $contactCount, 'relations' => $relationCount];
    }

    /**
     * 同步指定员工的外部联系人
     * 使用 batchGetByUser 接口（传单个 userid），避免逐条 API 调用
     *
     * @param  string  $userid  内部员工 userid
     * @return int 同步的联系人数量
     */
    public function syncByUser(string $userid): int
    {
        $result = $this->syncByUserBatch([$userid]);

        return $result['contacts'];
    }

    /**
     * 从详情接口响应中保存外部联系人及所有跟进关系（事件回调用）
     *
     * @param  array  $detail  getContactDetail 返回的完整数据
     */
    private function saveFromDetailResponse(array $detail): void
    {
        $contactData = $detail['external_contact'] ?? [];
        $followUsers = $detail['follow_user'] ?? [];

        if (empty($contactData['external_userid'])) {
            return;
        }

        $this->flushContacts([$this->buildContactRow($contactData)]);

        $relationRows = [];
        foreach ($followUsers as $followUser) {
            if (! empty($followUser['userid'])) {
                $relationRows[] = $this->buildRelationRow($contactData['external_userid'], $followUser);
            }
        }
        if (! empty($relationRows)) {
            $this->flushRelations($relationRows);
        }
    }

    /**
     * 构建联系人 upsert 行数据
     *
     * @param  array  $data  API 返回的外部联系人数据
     * @return array 可用于 upsert 的行数据
     */
    private function buildContactRow(array $data): array
    {
        $pinyin = $this->generatePinyin($data['name'] ?? '');

        return [
            'external_userid' => $data['external_userid'],
            'name' => $data['name'] ?? '',
            'name_pinyin' => $pinyin['pinyin'],
            'name_initials' => $pinyin['initials'],
            'avatar' => $data['avatar'] ?? null,
            'type' => $data['type'] ?? 0,
            'gender' => $data['gender'] ?? 0,
            'corp_name' => $data['corp_name'] ?? null,
            'corp_full_name' => $data['corp_full_name'] ?? null,
            'position' => $data['position'] ?? null,
            'unionid' => $data['unionid'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * 构建跟进关系 upsert 行数据
     *
     * @param  string  $externalUserid  外部联系人 userid
     * @param  array  $followData  跟进人数据
     * @return array 可用于 upsert 的行数据
     */
    private function buildRelationRow(string $externalUserid, array $followData): array
    {
        return [
            'external_userid' => $externalUserid,
            'userid' => $followData['userid'],
            'remark' => $followData['remark'] ?? null,
            'description' => $followData['description'] ?? null,
            'remark_corp_name' => $followData['remark_corp_name'] ?? null,
            'add_way' => $followData['add_way'] ?? null,
            'state' => $followData['state'] ?? null,
            'follow_at' => isset($followData['createtime'])
                ? \Carbon\Carbon::createFromTimestamp($followData['createtime'])
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * 批量写入联系人数据（upsert）
     *
     * @param  array  $rows  联系人行数据列表
     */
    private function flushContacts(array $rows): void
    {
        ExternalContact::upsert(
            $rows,
            ['external_userid'],
            ['name', 'name_pinyin', 'name_initials', 'avatar', 'type', 'gender', 'corp_name', 'corp_full_name', 'position', 'unionid', 'updated_at']
        );

        Log::debug('ExternalContactService::flushContacts upsert', ['count' => count($rows)]);
    }

    /**
     * 批量写入跟进关系数据（upsert）
     *
     * @param  array  $rows  跟进关系行数据列表
     */
    private function flushRelations(array $rows): void
    {
        ExternalContactFollowUser::upsert(
            $rows,
            ['external_userid', 'userid'],
            ['remark', 'description', 'remark_corp_name', 'add_way', 'state', 'follow_at', 'updated_at']
        );

        Log::debug('ExternalContactService::flushRelations upsert', ['count' => count($rows)]);
    }

    /**
     * 处理添加外部联系人事件回调
     * 通过 API 获取详情后保存
     *
     * @param  string  $userid  内部员工 userid
     * @param  string  $externalUserid  外部联系人 userid
     */
    public function handleAddEvent(string $userid, string $externalUserid): void
    {
        Log::info('ExternalContactService::handleAddEvent', [
            'userid' => $userid,
            'external_userid' => $externalUserid,
        ]);

        try {
            $detail = $this->client->getContactDetail($externalUserid);
            $this->saveFromDetailResponse($detail);
        } catch (WecomApiException $e) {
            Log::error('ExternalContactService::handleAddEvent 获取详情失败', [
                'external_userid' => $externalUserid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理编辑外部联系人事件回调
     * 重新获取详情并更新
     *
     * @param  string  $userid  内部员工 userid
     * @param  string  $externalUserid  外部联系人 userid
     */
    public function handleEditEvent(string $userid, string $externalUserid): void
    {
        Log::info('ExternalContactService::handleEditEvent', [
            'userid' => $userid,
            'external_userid' => $externalUserid,
        ]);

        try {
            $detail = $this->client->getContactDetail($externalUserid);
            $this->saveFromDetailResponse($detail);
        } catch (WecomApiException $e) {
            Log::error('ExternalContactService::handleEditEvent 获取详情失败', [
                'external_userid' => $externalUserid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理员工删除客户事件回调
     * 记录删除时间，不删除记录
     *
     * @param  string  $userid  内部员工 userid
     * @param  string  $externalUserid  外部联系人 userid
     */
    public function handleDeleteByUserEvent(string $userid, string $externalUserid): void
    {
        Log::info('ExternalContactService::handleDeleteByUserEvent', [
            'userid' => $userid,
            'external_userid' => $externalUserid,
        ]);

        ExternalContactFollowUser::where('external_userid', $externalUserid)
            ->where('userid', $userid)
            ->whereNull('deleted_by_user_at')
            ->update(['deleted_by_user_at' => now()]);
    }

    /**
     * 处理客户删除员工事件回调
     * 记录删除时间，不删除记录
     *
     * @param  string  $userid  内部员工 userid
     * @param  string  $externalUserid  外部联系人 userid
     */
    public function handleDeleteByCustomerEvent(string $userid, string $externalUserid): void
    {
        Log::info('ExternalContactService::handleDeleteByCustomerEvent', [
            'userid' => $userid,
            'external_userid' => $externalUserid,
        ]);

        ExternalContactFollowUser::where('external_userid', $externalUserid)
            ->where('userid', $userid)
            ->whereNull('deleted_by_customer_at')
            ->update(['deleted_by_customer_at' => now()]);
    }

    /**
     * 四级匹配策略搜索外部联系人
     *
     * 1. 精确匹配（姓名或备注名）
     * 2. 拼音全匹配
     * 3. 首字母匹配
     * 4. 模糊匹配（兜底）
     *
     * @param  string  $name  搜索关键词
     * @return Collection 匹配到的外部联系人集合
     */
    public function searchByName(string $name): Collection
    {
        // 1. 精确匹配姓名
        $results = ExternalContact::where('name', $name)->get();
        if ($results->isNotEmpty()) {
            return $results;
        }

        // 也尝试匹配跟进关系中的备注名
        $remarkResults = ExternalContact::whereHas('followUsers', function ($query) use ($name) {
            $query->where('remark', $name)
                ->whereNull('deleted_by_user_at')
                ->whereNull('deleted_by_customer_at');
        })->get();
        if ($remarkResults->isNotEmpty()) {
            return $remarkResults;
        }

        // 2. 拼音全匹配
        $pinyin = $this->generatePinyin($name);
        $results = ExternalContact::where('name_pinyin', $pinyin['pinyin'])->get();
        if ($results->isNotEmpty()) {
            return $results;
        }

        // 3. 首字母匹配
        $results = ExternalContact::where('name_initials', $pinyin['initials'])->get();
        if ($results->isNotEmpty()) {
            return $results;
        }

        // 4. 模糊匹配（兜底）
        return ExternalContact::where('name', 'like', "%{$name}%")->get();
    }

    /**
     * 根据 external_userid 获取外部联系人
     *
     * @param  string  $externalUserid  外部联系人 userid
     */
    public function getByExternalUserId(string $externalUserid): ?ExternalContact
    {
        return ExternalContact::where('external_userid', $externalUserid)->first();
    }

    /**
     * 获取指定员工的活跃外部联系人，支持按添加时间筛选
     *
     * @param  string  $userid  内部员工 userid
     * @param  string|null  $startDate  开始日期（Y-m-d），筛选 follow_at >= 该日期
     * @param  string|null  $endDate  结束日期（Y-m-d），筛选 follow_at <= 该日期 23:59:59
     * @return Collection 外部联系人集合（附带该员工的跟进关系）
     */
    public function getByFollowUser(string $userid, ?string $startDate = null, ?string $endDate = null): Collection
    {
        return ExternalContact::whereHas('activeFollowUsers', function ($query) use ($userid, $startDate, $endDate) {
            $query->where('userid', $userid);
            if ($startDate) {
                $query->where('follow_at', '>=', $startDate.' 00:00:00');
            }
            if ($endDate) {
                $query->where('follow_at', '<=', $endDate.' 23:59:59');
            }
        })->with(['activeFollowUsers' => function ($query) use ($userid) {
            $query->where('userid', $userid);
        }])->get();
    }

    /**
     * 获取指定时间范围内所有新增的外部联系人，支持按员工筛选
     *
     * @param  string  $startDate  开始日期（Y-m-d）
     * @param  string  $endDate  结束日期（Y-m-d）
     * @param  string|null  $userid  可选，限定某个员工
     * @return Collection 外部联系人集合（附带跟进关系）
     */
    public function getByDateRange(string $startDate, string $endDate, ?string $userid = null): Collection
    {
        return ExternalContact::whereHas('activeFollowUsers', function ($query) use ($startDate, $endDate, $userid) {
            $query->where('follow_at', '>=', $startDate.' 00:00:00')
                ->where('follow_at', '<=', $endDate.' 23:59:59');
            if ($userid) {
                $query->where('userid', $userid);
            }
        })->with(['activeFollowUsers' => function ($query) use ($startDate, $endDate, $userid) {
            $query->where('follow_at', '>=', $startDate.' 00:00:00')
                ->where('follow_at', '<=', $endDate.' 23:59:59');
            if ($userid) {
                $query->where('userid', $userid);
            }
        }])->get();
    }

    /**
     * 生成姓名的拼音和首字母
     *
     * @param  string  $name  姓名
     * @return array{pinyin: string, initials: string}
     */
    private function generatePinyin(string $name): array
    {
        if (empty($name)) {
            return ['pinyin' => '', 'initials' => ''];
        }

        // 只保留 CJK 统一汉字和英文字母，去除标点、数字、空格等特殊字符
        $cleanName = preg_replace('/[^\x{4e00}-\x{9fff}\x{3400}-\x{4dbf}\x{20000}-\x{2a6df}a-zA-Z]/u', '', $name);
        if (empty($cleanName)) {
            return ['pinyin' => '', 'initials' => ''];
        }

        $pinyinCollection = Pinyin::name($cleanName, 'none');
        $pinyinStr = $pinyinCollection->join(' ');
        $initials = $pinyinCollection->map(fn ($p) => mb_substr($p, 0, 1))->join('');

        return [
            'pinyin' => $pinyinStr,
            'initials' => $initials,
        ];
    }
}

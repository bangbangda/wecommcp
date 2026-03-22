<?php

namespace App\Services;

use App\Exceptions\WecomApiException;
use App\Models\Contact;
use App\Wecom\WecomContactClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Overtrue\Pinyin\Pinyin;

class ContactsService
{
    /**
     * 四级匹配策略搜索联系人
     *
     * 1. 精确匹配
     * 2. 拼音全匹配（解决同音字）
     * 3. 首字母匹配
     * 4. 模糊匹配（兜底）
     */
    public function searchByName(string $name): Collection
    {
        // 1. 精确匹配
        $results = Contact::where('name', $name)->get();
        if ($results->isNotEmpty()) {
            return $results;
        }

        // 2. 拼音全匹配
        $pinyin = $this->generatePinyin($name);
        $results = Contact::where('name_pinyin', $pinyin['pinyin'])->get();
        if ($results->isNotEmpty()) {
            return $results;
        }

        // 3. 首字母匹配
        $results = Contact::where('name_initials', $pinyin['initials'])->get();
        if ($results->isNotEmpty()) {
            return $results;
        }

        // 4. 模糊匹配（兜底）
        return Contact::where('name', 'like', "%{$name}%")->get();
    }

    /**
     * 从企微同步通讯录到本地数据库
     *
     * @return int 同步的联系人数量
     */
    public function syncFromWecom(WecomContactClient $wecomContactClient): int
    {
        // 1. 分页获取所有成员 userid
        $userids = [];
        $cursor = null;

        do {
            $result = $wecomContactClient->getUserIdList($cursor);
            foreach ($result['dept_user'] ?? [] as $item) {
                $userids[$item['userid']] = $item['department'] ?? 0;
            }
            $cursor = $result['next_cursor'] ?? '';
        } while (! empty($cursor));

        // 2. 逐个获取成员详情并写入本地
        $synced = 0;
        $departmentCache = []; // 缓存部门名称，避免重复请求

        foreach ($userids as $userid => $departmentId) {
            try {
                $user = $wecomContactClient->getUser($userid);
            } catch (WecomApiException $e) {
                Log::warning("同步联系人跳过 {$userid}: {$e->getMessage()}");

                continue;
            }

            if (empty($user['name'])) {
                continue;
            }

            // 获取主部门名称（优先使用 API 返回的 main_department）
            $mainDeptId = (int) ($user['main_department'] ?? $departmentId);
            $departmentName = $this->getDepartmentName($mainDeptId, $departmentCache, $wecomContactClient);

            $pinyin = $this->generatePinyin($user['name']);

            Contact::updateOrCreate(
                ['userid' => $userid],
                [
                    'name' => $user['name'],
                    'name_pinyin' => $pinyin['pinyin'],
                    'name_initials' => $pinyin['initials'],
                    'department' => $departmentName,
                    'position' => $user['position'] ?? '',
                    'mobile' => $user['mobile'] ?? '',
                    'email' => $user['email'] ?? '',
                ]
            );
            $synced++;
        }

        return $synced;
    }

    /**
     * 根据部门 ID 获取部门名称，带本地缓存
     *
     * @param  int  $departmentId  部门 ID
     * @param  array  &$cache  部门名称缓存（id → name）
     * @param  WecomContactClient  $wecomContactClient  企微通讯录服务
     * @return string 部门名称，获取失败时返回空字符串
     */
    private function getDepartmentName(int $departmentId, array &$cache, WecomContactClient $wecomContactClient): string
    {
        if ($departmentId <= 0) {
            return '';
        }

        if (isset($cache[$departmentId])) {
            return $cache[$departmentId];
        }

        try {
            $result = $wecomContactClient->getDepartment($departmentId);
            $name = $result['department']['name'] ?? '';
        } catch (WecomApiException $e) {
            Log::warning("获取部门名称失败 [{$departmentId}]: {$e->getMessage()}");
            $name = '';
        }

        $cache[$departmentId] = $name;

        return $name;
    }

    /**
     * 生成姓名的拼音和首字母
     */
    public function generatePinyin(string $name): array
    {
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

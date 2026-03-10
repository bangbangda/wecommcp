<?php

namespace App\Services;

use App\Models\UserModuleConfig;

class ModuleConfigService
{
    /**
     * 读取单个模块配置
     *
     * @param  string  $userId  企微 userid
     * @param  string  $module  业务模块标识
     * @param  string  $key  配置键名
     * @return string|null 配置值，不存在返回 null
     */
    public function get(string $userId, string $module, string $key): ?string
    {
        return UserModuleConfig::where('user_id', $userId)
            ->where('module', $module)
            ->where('key', $key)
            ->value('value');
    }

    /**
     * 读取模块全部配置
     *
     * @param  string  $userId  企微 userid
     * @param  string  $module  业务模块标识
     * @return array<string, string> key => value 映射，如 ['cal_id' => 'xxx']
     */
    public function getAll(string $userId, string $module): array
    {
        return UserModuleConfig::where('user_id', $userId)
            ->where('module', $module)
            ->pluck('value', 'key')
            ->toArray();
    }

    /**
     * 写入/更新单个模块配置
     *
     * @param  string  $userId  企微 userid
     * @param  string  $module  业务模块标识
     * @param  string  $key  配置键名
     * @param  string  $value  配置值
     */
    public function set(string $userId, string $module, string $key, string $value): void
    {
        UserModuleConfig::updateOrCreate(
            ['user_id' => $userId, 'module' => $module, 'key' => $key],
            ['value' => $value],
        );
    }

    /**
     * 向 JSON 数组类型的配置追加值（自动去重）
     * 适用于一个 key 需要存储多个值的场景，如多个 cal_id
     *
     * @param  string  $userId  企微 userid
     * @param  string  $module  业务模块标识
     * @param  string  $key  配置键名
     * @param  string  $value  要追加的值
     */
    public function append(string $userId, string $module, string $key, string $value): void
    {
        $existing = $this->getList($userId, $module, $key);

        if (! in_array($value, $existing)) {
            $existing[] = $value;
        }

        $this->set($userId, $module, $key, json_encode($existing, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 读取 JSON 数组类型的配置值列表
     * 兼容旧数据：如果值不是 JSON 数组，自动包装为单元素数组
     *
     * @param  string  $userId  企微 userid
     * @param  string  $module  业务模块标识
     * @param  string  $key  配置键名
     * @return array<string> 值列表
     */
    public function getList(string $userId, string $module, string $key): array
    {
        $raw = $this->get($userId, $module, $key);

        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);

        // 兼容旧数据：非 JSON 数组的纯字符串值包装为数组
        if (! is_array($decoded)) {
            return [$raw];
        }

        return $decoded;
    }
}

<?php

namespace App\Wecom;

use EasyWeChat\Work\Application as WecomApp;
use InvalidArgumentException;

/**
 * 企业微信应用管理器
 *
 * 按名称获取不同的 WecomApp 实例（agent、contact 等），
 * 内部缓存已创建实例，避免重复构建。
 *
 * 用法：
 *   app(WecomManager::class)->app('agent')   // 自建应用
 *   app(WecomManager::class)->app('contact') // 通讯录应用
 *   app(WecomManager::class)->app()          // 默认 agent
 */
class WecomManager
{
    /** @var array<string, WecomApp> 已解析的实例缓存 */
    private array $resolved = [];

    /**
     * 获取指定应用的 WecomApp 实例
     *
     * @param  string|null  $name  应用名称（agent、contact 等），默认 agent
     *
     * @throws InvalidArgumentException 配置不存在时抛出
     */
    public function app(?string $name = null): WecomApp
    {
        $name = $name ?? 'agent';

        return $this->resolved[$name] ??= $this->resolve($name);
    }

    /**
     * 根据配置创建 WecomApp 实例
     *
     * @param  string  $name  应用名称
     *
     * @throws InvalidArgumentException 配置不存在时抛出
     */
    private function resolve(string $name): WecomApp
    {
        $appConfig = config("services.wecom.apps.{$name}");

        if (! $appConfig) {
            throw new InvalidArgumentException("企业微信应用 [{$name}] 未配置，请检查 config/services.php 的 wecom.apps.{$name}");
        }

        $corpId = config('services.wecom.corp_id');

        return new WecomApp([
            'corp_id' => $corpId,
            'secret' => $appConfig['secret'] ?? '',
            'token' => $appConfig['token'] ?? '',
            'aes_key' => $appConfig['aes_key'] ?? '',
        ]);
    }
}

<?php

namespace App\Ai;

use App\Ai\Contracts\AiDriver;
use App\Ai\Drivers\AnthropicDriver;
use App\Ai\Drivers\OpenAiCompatibleDriver;
use App\Ai\Dto\AiResponse;
use Illuminate\Support\Manager;

/**
 * AI 模型驱动管理器
 *
 * 用法：
 *   app(AiManager::class)->chat(...)           // 使用默认驱动
 *   app(AiManager::class)->driver('anthropic') // 切换驱动
 *
 * @mixin AiDriver
 */
class AiManager extends Manager implements AiDriver
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('ai.default', 'ollama');
    }

    public function createAnthropicDriver(): AnthropicDriver
    {
        $config = $this->config->get('ai.drivers.anthropic', []);

        return new AnthropicDriver(
            apiKey: $config['api_key'] ?? '',
            model: $config['model'] ?? 'claude-sonnet-4-5-20250929',
            maxTokens: $config['max_tokens'] ?? 4096,
            baseUrl: $config['base_url'] ?? 'https://api.anthropic.com',
            apiVersion: $config['api_version'] ?? '2023-06-01',
        );
    }

    public function createOllamaDriver(): OpenAiCompatibleDriver
    {
        $config = $this->config->get('ai.drivers.ollama', []);

        return new OpenAiCompatibleDriver(
            baseUrl: $config['base_url'] ?? 'http://localhost:11434/v1',
            model: $config['model'] ?? 'qwen3:8b',
            apiKey: $config['api_key'] ?? '',
            maxTokens: $config['max_tokens'] ?? 4096,
            timeout: $config['timeout'] ?? 120,
        );
    }

    public function createOpenaiDriver(): OpenAiCompatibleDriver
    {
        $config = $this->config->get('ai.drivers.openai', []);

        return new OpenAiCompatibleDriver(
            baseUrl: $config['base_url'] ?? 'https://api.openai.com/v1',
            model: $config['model'] ?? 'gpt-4o',
            apiKey: $config['api_key'] ?? '',
            maxTokens: $config['max_tokens'] ?? 4096,
            timeout: $config['timeout'] ?? 60,
        );
    }

    public function createDeepseekDriver(): OpenAiCompatibleDriver
    {
        $config = $this->config->get('ai.drivers.deepseek', []);

        return new OpenAiCompatibleDriver(
            baseUrl: $config['base_url'] ?? 'https://api.deepseek.com/v1',
            model: $config['model'] ?? 'deepseek-chat',
            apiKey: $config['api_key'] ?? '',
            maxTokens: $config['max_tokens'] ?? 4096,
            timeout: $config['timeout'] ?? 60,
        );
    }

    // AiDriver 接口代理到默认驱动

    public function chat(string $systemPrompt, array $messages, array $tools = []): ?AiResponse
    {
        /** @var AiDriver $driver */
        $driver = $this->driver();

        return $driver->chat($systemPrompt, $messages, $tools);
    }
}

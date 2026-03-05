<?php

namespace App\Ai\Contracts;

use App\Ai\Dto\AiResponse;

interface AiDriver
{
    /**
     * 发送对话请求到 AI 模型
     *
     * @param  string  $systemPrompt  系统提示词
     * @param  array  $messages  对话历史（Claude 内部格式）
     * @param  array  $tools  工具定义（Claude 内部格式）
     * @return AiResponse|null 标准化响应，失败返回 null
     */
    public function chat(string $systemPrompt, array $messages, array $tools = []): ?AiResponse;
}

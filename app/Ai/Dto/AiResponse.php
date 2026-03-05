<?php

namespace App\Ai\Dto;

class AiResponse
{
    /**
     * @param  string  $text  模型返回的文本内容
     * @param  ToolCall[]  $toolCalls  模型请求的工具调用列表
     * @param  bool  $wantsTool  模型是否需要工具调用结果（继续循环）
     * @param  array  $rawAssistantMessage  Claude 内部格式的 assistant 消息（用于写入对话历史）
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly bool $wantsTool,
        public readonly array $rawAssistantMessage,
    ) {}

    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }
}

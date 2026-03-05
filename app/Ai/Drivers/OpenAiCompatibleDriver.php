<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\AiDriver;
use App\Ai\Dto\AiResponse;
use App\Ai\Dto\ToolCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiCompatibleDriver implements AiDriver
{
    public function __construct(
        protected string $baseUrl,
        protected string $model,
        protected string $apiKey = '',
        protected int $maxTokens = 4096,
        protected int $timeout = 120,
    ) {}

    public function chat(string $systemPrompt, array $messages, array $tools = []): ?AiResponse
    {
        try {
            $openAiMessages = $this->convertMessages($systemPrompt, $messages);

            $payload = [
                'model' => $this->model,
                'messages' => $openAiMessages,
                'max_tokens' => $this->maxTokens,
            ];

            if (! empty($tools)) {
                $payload['tools'] = $this->convertToolDefinitions($tools);
            }

            Log::debug('OpenAiCompatibleDriver::chat 请求参数', $payload);

            $http = Http::timeout($this->timeout)
                ->withHeaders(['content-type' => 'application/json']);

            if (! empty($this->apiKey)) {
                $http = $http->withToken($this->apiKey);
            }

            $response = $http->post(
                rtrim($this->baseUrl, '/').'/chat/completions',
                $payload,
            );

            if (! $response->successful()) {
                Log::error('OpenAI-compatible API 请求失败', [
                    'base_url' => $this->baseUrl,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();
            Log::debug('OpenAiCompatibleDriver::chat 返回结果', $data);

            return $this->parseResponse($data);
        } catch (\Exception $e) {
            Log::error('OpenAI-compatible API 异常', [
                'base_url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Claude 内部消息格式 → OpenAI 消息格式
     */
    protected function convertMessages(string $systemPrompt, array $messages): array
    {
        $result = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            if ($role === 'user') {
                array_push($result, ...$this->convertUserMessage($msg));
            } elseif ($role === 'assistant') {
                array_push($result, ...$this->convertAssistantMessage($msg));
            }
        }

        return $result;
    }

    /**
     * 转换 user 消息（含 tool_result 场景）
     */
    protected function convertUserMessage(array $msg): array
    {
        $content = $msg['content'];

        // 纯文本
        if (is_string($content)) {
            return [['role' => 'user', 'content' => $content]];
        }

        // content blocks（tool_result）
        $result = [];
        foreach ($content as $block) {
            if ($block['type'] === 'tool_result') {
                $result[] = [
                    'role' => 'tool',
                    'tool_call_id' => $block['tool_use_id'],
                    'content' => is_string($block['content'])
                        ? $block['content']
                        : json_encode($block['content'], JSON_UNESCAPED_UNICODE),
                ];
            } elseif ($block['type'] === 'text') {
                $result[] = ['role' => 'user', 'content' => $block['text']];
            }
        }

        return $result;
    }

    /**
     * 转换 assistant 消息（含 tool_use → tool_calls）
     */
    protected function convertAssistantMessage(array $msg): array
    {
        $content = $msg['content'];

        if (is_string($content)) {
            return [['role' => 'assistant', 'content' => $content]];
        }

        $textParts = [];
        $toolCalls = [];

        foreach ($content as $block) {
            if ($block['type'] === 'text') {
                $textParts[] = $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'],
                        'arguments' => json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE),
                    ],
                ];
            }
        }

        $assistantMsg = [
            'role' => 'assistant',
            'content' => implode("\n", $textParts) ?: null,
        ];

        if (! empty($toolCalls)) {
            $assistantMsg['tool_calls'] = $toolCalls;
        }

        return [$assistantMsg];
    }

    /**
     * Claude 工具定义 → OpenAI 工具定义
     */
    protected function convertToolDefinitions(array $tools): array
    {
        return array_map(fn (array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'parameters' => $tool['input_schema'] ?? [
                    'type' => 'object',
                    'properties' => new \stdClass,
                ],
            ],
        ], $tools);
    }

    /**
     * OpenAI 响应 → AiResponse（rawAssistantMessage 为 Claude 内部格式）
     */
    protected function parseResponse(array $data): AiResponse
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $finishReason = $choice['finish_reason'] ?? 'stop';

        $text = $this->stripThinkingTags($message['content'] ?? '');
        $openAiToolCalls = $message['tool_calls'] ?? [];

        $toolCalls = array_map(fn (array $tc) => new ToolCall(
            id: $tc['id'] ?? ('call_'.uniqid()),
            name: $tc['function']['name'],
            input: json_decode($tc['function']['arguments'] ?? '{}', true) ?? [],
        ), $openAiToolCalls);

        // 构建 Claude 内部格式的 rawAssistantMessage
        $contentBlocks = [];
        if (! empty($text)) {
            $contentBlocks[] = ['type' => 'text', 'text' => $text];
        }
        foreach ($toolCalls as $tc) {
            $contentBlocks[] = [
                'type' => 'tool_use',
                'id' => $tc->id,
                'name' => $tc->name,
                'input' => $tc->input,
            ];
        }

        return new AiResponse(
            text: $text,
            toolCalls: $toolCalls,
            wantsTool: $finishReason === 'tool_calls' || ! empty($toolCalls),
            rawAssistantMessage: [
                'role' => 'assistant',
                'content' => $contentBlocks,
            ],
        );
    }

    /**
     * 去除模型思考标签（如 qwen3 的 <think>...</think>）
     */
    protected function stripThinkingTags(string $text): string
    {
        return trim(preg_replace('/<think>[\s\S]*?<\/think>/', '', $text));
    }
}

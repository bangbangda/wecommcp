<?php

namespace App\Ai\Drivers;

use App\Ai\Contracts\AiDriver;
use App\Ai\Dto\AiResponse;
use App\Ai\Dto\ToolCall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicDriver implements AiDriver
{
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected int $maxTokens = 4096,
        protected string $baseUrl = 'https://api.anthropic.com',
        protected string $apiVersion = '2023-06-01',
    ) {}

    public function chat(string $systemPrompt, array $messages, array $tools = []): ?AiResponse
    {
        if (empty($this->apiKey)) {
            Log::error('Anthropic API key 未配置');

            return null;
        }

        try {
            $payload = [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'system' => $systemPrompt,
                'messages' => $messages,
            ];

            if (! empty($tools)) {
                $payload['tools'] = $tools;
            }

            Log::debug('AnthropicDriver::chat 请求参数', $payload);

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->apiVersion,
                'content-type' => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}/v1/messages", $payload);

            if (! $response->successful()) {
                Log::error('Claude API 请求失败', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $data = $response->json();
            Log::debug('AnthropicDriver::chat 返回结果', $data);

            return $this->parseResponse($data);
        } catch (\Exception $e) {
            Log::error('Claude API 异常', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function parseResponse(array $data): AiResponse
    {
        $contentBlocks = $data['content'] ?? [];
        $stopReason = $data['stop_reason'] ?? 'end_turn';

        $textParts = [];
        $toolCalls = [];

        foreach ($contentBlocks as $block) {
            if ($block['type'] === 'text') {
                $textParts[] = $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: $block['id'],
                    name: $block['name'],
                    input: $block['input'] ?? [],
                );
            }
        }

        return new AiResponse(
            text: implode("\n", $textParts),
            toolCalls: $toolCalls,
            wantsTool: $stopReason === 'tool_use',
            rawAssistantMessage: [
                'role' => 'assistant',
                'content' => $contentBlocks,
            ],
        );
    }
}

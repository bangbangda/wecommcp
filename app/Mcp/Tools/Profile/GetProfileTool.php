<?php

namespace App\Mcp\Tools\Profile;

use App\Services\UserProfileService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_profile')]
#[Description(
    '查看用户当前的个性化配置。'.
    '当用户问"我的设置是什么""你叫什么名字""我之前设了什么风格"时使用。'
)]
class GetProfileTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * 处理查看 profile 请求
     *
     * @param  Request  $request  MCP 请求
     * @param  UserProfileService  $profileService  profile 服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, UserProfileService $profileService, string $userId): Response
    {
        Log::debug('GetProfileTool::handle 收到请求', ['user_id' => $userId]);

        $profile = $profileService->get($userId);

        if (! $profile) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => '当前没有个性化配置，可以告诉我你希望怎么设置。',
            ], JSON_UNESCAPED_UNICODE));
        }

        $fields = [];
        $fieldLabels = [
            'bot_name' => '机器人昵称',
            'user_nickname' => '用户称呼',
            'persona' => '回复风格',
            'greeting_template' => '开场白模板',
            'catchphrases' => '常用语',
            'taboos' => '禁忌项',
        ];

        foreach ($fieldLabels as $field => $label) {
            if (! empty($profile->{$field})) {
                $fields[$label] = $profile->{$field};
            }
        }

        if (empty($fields)) {
            return Response::text(json_encode([
                'status' => 'empty',
                'message' => '当前没有个性化配置，可以告诉我你希望怎么设置。',
            ], JSON_UNESCAPED_UNICODE));
        }

        return Response::text(json_encode([
            'status' => 'success',
            'profile' => $fields,
        ], JSON_UNESCAPED_UNICODE));
    }
}

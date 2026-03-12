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

#[Name('set_profile')]
#[Description(
    '设置或更新用户的个性化配置。'.
    '当用户想给机器人起名字（"叫你小微""你的名字就叫..."）、'.
    '设置称呼（"叫我老板""以后喊我哥"）、'.
    '调整回复风格（"轻松点""别用敬语""幽默一些""说话简短"）、'.
    '设置口头禅（"完成后说搞定了""确认时说收到"）、'.
    '设置禁忌（"不要用emoji""别说还有什么需要帮忙的"）、'.
    '设置开场白（"每天早上跟我打招呼""问候时加上今天日程"）时使用。'.
    '字段：bot_name / user_nickname / persona / greeting_template / catchphrases / taboos。'.
    'persona 字段会自动润色，用户只需简单描述风格即可。'
)]
class SetProfileTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'field' => $schema->string('要设置的字段名：bot_name（机器人昵称）、user_nickname（用户称呼）、persona（回复风格）、greeting_template（开场白模板）、catchphrases（常用语）、taboos（禁忌项）')->required(),
            'value' => $schema->string('用户表达的原始内容')->required(),
        ];
    }

    /**
     * 处理设置 profile 请求
     *
     * @param  Request  $request  MCP 请求
     * @param  UserProfileService  $profileService  profile 服务
     * @param  string  $userId  当前用户 userid
     * @return Response MCP 响应
     */
    public function handle(Request $request, UserProfileService $profileService, string $userId): Response
    {
        $data = $request->validate([
            'field' => 'required|string|in:'.implode(',', UserProfileService::ALLOWED_FIELDS),
            'value' => 'required|string|max:500',
        ]);

        Log::debug('SetProfileTool::handle 收到请求', ['user_id' => $userId, ...$data]);

        $value = $data['value'];

        // persona 字段自动润色
        if ($data['field'] === 'persona') {
            $value = $profileService->polishPersona($value);
        }

        $result = $profileService->updateField($userId, $data['field'], $value);

        Log::debug('SetProfileTool::handle 执行结果', $result);

        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE));
    }
}

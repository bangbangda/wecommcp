<?php

namespace App\Mcp\Servers;

use App\Mcp\ToolRegistry;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('wecom')]
#[Version('1.0.0')]
#[Instructions('企业微信 AI 助手，可以创建会议、修改会议、取消会议、查询会议详情、搜索联系人、查询会议室、预定会议室、取消会议室预定、查询会议室预定信息')]
class WecomServer extends Server
{
    /**
     * 构造函数，从 ToolRegistry 获取注册的 Tool 列表
     */
    public function __construct()
    {
        $this->tools = app(ToolRegistry::class)->getToolClasses();
    }
}

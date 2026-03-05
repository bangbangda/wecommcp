<?php

use App\Mcp\Servers\WecomServer;
use Laravel\Mcp\Facades\Mcp;

// 本地模式（Claude Desktop / Claude Code）
Mcp::local('wecom', WecomServer::class);

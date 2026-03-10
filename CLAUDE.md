# 企业微信 AI 助手

企微 AI 助手：用户发送语音/文字指令 → AI 意图识别 → MCP Tool 执行企微操作（创建会议、搜索联系人等）。

## 技术栈

Laravel 12 + `laravel/mcp` + `overtrue/pinyin` + SQLite（MVP）。AI 模型通过 Driver 抽象层接入，默认 Ollama qwen3:8b。

## 常用命令

```bash
php artisan chat                    # 交互式对话测试
php artisan test                    # 运行测试（Pest）
php artisan mcp:start wecom         # 启动 MCP Server
AI_DRIVER=anthropic php artisan chat  # 切换 Claude 驱动
php artisan skill:search-doc <关键词>  # 搜索企微 API 文档（skill 专用）
```

## 项目结构

```
app/
├── Ai/                         # AI 驱动抽象层
│   ├── Contracts/AiDriver.php  # 驱动接口
│   ├── Drivers/                # AnthropicDriver, OpenAiCompatibleDriver
│   ├── Dto/                    # AiResponse, ToolCall
│   └── AiManager.php           # Laravel Manager 工厂
├── Mcp/
│   ├── Servers/WecomServer.php # MCP Server 入口
│   └── Tools/                  # CreateMeetingTool, SearchContactsTool
├── Models/                     # Contact, RecentMeeting
├── Services/
│   ├── ChatService.php         # AI 对话编排（tool 循环）
│   └── ContactsService.php     # 拼音四级匹配
└── Console/Commands/
    └── ChatCommand.php         # CLI 入口
config/ai.php                   # AI 驱动配置（drivers + default）
config/services.php             # 企微配置
routes/ai.php                   # MCP Server 路由
```

## 核心设计模式

### AI 驱动切换

`config/ai.php` 配置，`AI_DRIVER` 环境变量切换。内部消息格式统一为 Claude 风格，Driver 负责格式转换。`OpenAiCompatibleDriver` 同时支持 Ollama / OpenAI / DeepSeek。

### 同音字匹配

`ContactsService::searchByName()` 四级策略：精确 → 拼音全匹配 → 首字母 → 模糊。contacts 表含 `name_pinyin`、`name_initials` 字段。多候选时 Tool 返回 `need_clarification` 让 AI 追问。

### MCP Tool

Tool schema 只暴露业务参数（title、start_time、invitees）。基础设施参数（access_token、corp_id、姓名→userid 转换）在 Tool/Service 内部处理，LLM 不可见。

## 编码规范

- 遵循 Laravel 官方编码风格，代码编写完成后必须执行 `pint` 格式化
- Service 层封装外部 API 调用，Tool 层做参数校验和流程编排
- 敏感配置只通过 .env 管理，不硬编码
- 中文注释，代码变量名用英文
- 测试使用 Pest 语法
- 所有方法必须编写注释（方法用途、参数、返回值）
- 对接第三方 API 的请求参数和返回值必须记录日志（`Log::debug`），便于问题排查
- 编写企微 API 对接代码前，**必须先用 `php artisan skill:search-doc` 查阅官方文档**，逐一核对请求参数和返回字段，严禁凭经验臆测参数

### Tool Description 与 System Prompt

Tool 的 `#[Description]` 是大模型选择工具的首要依据，必须包含：功能说明、触发场景（含口语化泛化示例）、工具间调用链依赖、边界说明。System Prompt（`ChatService::buildSystemPrompt()`）引导 AI 的链式推理和交互方式：模糊指令时主动分解任务、多步工具调用、让用户「选择」而非「描述」。详见 **[提示词编写规范](docs/prompt-guide.md)**。

## Skill 开发规范

设计和创建 Skill 时，额外读取 `skills/CLAUDE.md`

### 企微 API 文档查询

需要查阅企业微信 API 文档时，**必须使用 `php artisan skill:search-doc` 命令**从本地数据库查询，不要访问官方网站。详见 [wecom-api-docs-lookup](skills/wecom-api-docs-lookup/SKILL.md)。

## 文档

- [架构设计详情](docs/architecture.md)
- [提示词编写规范](docs/prompt-guide.md)
- [开发路线图](docs/roadmap.md)

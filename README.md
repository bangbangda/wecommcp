# WeCom MCP - 企业微信 AI 助手

基于 Laravel + MCP 协议构建的企业微信 AI 助手。用户通过自然语言指令，由 AI 自动识别意图并调用企业微信 API 完成操作。

> "帮我明天下午3点和张三开个需求评审会" → AI 自动搜索联系人 → 创建会议 → 返回会议信息

## 功能特性

- **自然语言驱动** — 用口语化指令完成企微操作，无需学习 API
- **MCP Tool 架构** — 基于 [Model Context Protocol](https://modelcontextprotocol.io)，可扩展的工具体系
- **多模型支持** — Ollama / Claude / OpenAI / DeepSeek 一键切换
- **拼音智能匹配** — 支持同音字、首字母缩写匹配联系人（"王伟" = "汪伟"）
- **用户记忆** — 自动记住用户偏好和习惯，个性化响应
- **Claude Code Skills** — 内置 API 文档查询 Skill，辅助开发

## 已支持的 MCP Tools

| 分类 | Tool | 说明 |
|------|------|------|
| 会议 | CreateMeetingTool | 创建在线会议 |
| | QueryMeetingsTool | 查询会议列表 |
| | GetMeetingInfoTool | 获取会议详情 |
| | UpdateMeetingTool | 修改会议 |
| | CancelMeetingTool | 取消会议 |
| 会议室 | QueryMeetingRoomsTool | 查询会议室列表 |
| | BookMeetingRoomTool | 预定会议室 |
| | QueryRoomBookingsTool | 查询预定记录 |
| | CancelRoomBookingTool | 取消预定 |
| 联系人 | SearchContactsTool | 搜索联系人（拼音四级匹配） |
| 记忆 | SaveMemoryTool | 保存用户偏好/习惯 |
| | DeleteMemoryTool | 删除记忆 |

## 环境要求

- PHP >= 8.2
- Composer
- SQLite（默认）或 MySQL
- AI 模型（任选其一）：
  - [Ollama](https://ollama.ai) 本地部署（推荐开发环境，默认 qwen3:8b）
  - Claude API Key（[Anthropic](https://console.anthropic.com)）
  - OpenAI API Key
  - DeepSeek API Key

## 快速开始

### 1. 安装

```bash
git clone https://github.com/bangbangda/wecommcp.git
cd wecom-mcp
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

### 2. 配置 AI 模型

编辑 `.env`，选择一个 AI 驱动：

```bash
# 方式一：Ollama 本地模型（无需 API Key）
AI_DRIVER=ollama
OLLAMA_BASE_URL=http://localhost:11434/v1
OLLAMA_MODEL=qwen3:8b

# 方式二：Claude
AI_DRIVER=anthropic
ANTHROPIC_API_KEY=sk-ant-xxx

# 方式三：OpenAI
AI_DRIVER=openai
OPENAI_API_KEY=sk-xxx

# 方式四：DeepSeek
AI_DRIVER=deepseek
DEEPSEEK_API_KEY=sk-xxx
```

### 3. 配置企业微信

在[企业微信管理后台](https://work.weixin.qq.com)创建自建应用，获取以下信息填入 `.env`：

```bash
WECOM_CORP_ID=your_corp_id
WECOM_AGENT_SECRET=your_agent_secret
WECOM_AGENT_ID=your_agent_id
WECOM_AGENT_TOKEN=your_callback_token
WECOM_AGENT_AES_KEY=your_callback_aes_key

# 通讯录同步（可选）
WECOM_CONTACT_SECRET=your_contact_secret
```

### 4. 同步通讯录

```bash
php artisan wecom:sync-contacts
```

### 5. 开始对话

```bash
php artisan chat
```

输入自然语言指令即可：

```
You: 帮我明天下午两点和张三开个项目评审会
AI: 我来帮你创建会议。先搜索一下"张三"...
    找到联系人张三（产品部）。
    已创建会议「项目评审会」：
    - 时间：明天 14:00-15:00
    - 参会人：张三
    - 会议链接：https://meeting.tencent.com/xxx
```

## 架构概览

```
用户（语音/文字）
    |
    v
+-------------+     +------------------+     +-----------------+
|   接入层     | --> |   AI 编排层       | --> |  MCP Tool 执行层 |
|  企微回调    |     |  AiDriver        |     |  企微 API 调用   |
|  CLI 入口    | <-- |  + Tool Use      | <-- |  结果返回        |
+-------------+     +------------------+     +-----------------+
                            |
                    +-------+-------+
                    |       |       |
                  Ollama  Claude  OpenAI ...
```

**核心设计原则：**

- **Tool 参数隔离** — Tool 只暴露业务参数（title、start_time），access_token、corp_id 等基础设施参数由 Service 层内部管理，LLM 不可见
- **AI 驱动抽象** — Manager 模式，内部统一为 Claude 风格消息格式，Driver 负责格式转换
- **拼音四级匹配** — 精确 → 拼音全匹配 → 首字母 → 模糊，多候选时 AI 自动追问

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
│   └── Tools/                  # 12 个 MCP Tool
├── Models/                     # Contact, RecentMeeting, UserMemory
├── Services/
│   ├── ChatService.php         # AI 对话编排（tool 循环）
│   ├── ContactsService.php     # 拼音四级匹配
│   └── WecomService.php        # 企微 API 封装
└── Console/Commands/           # CLI 命令
config/ai.php                   # AI 驱动配置
skills/                         # Claude Code Skills
```

## 常用命令

```bash
php artisan chat                      # 交互式对话
php artisan test                      # 运行测试
php artisan wecom:sync-contacts       # 同步企微通讯录
php artisan mcp:start wecom           # 启动 MCP Server

# 切换 AI 驱动
AI_DRIVER=anthropic php artisan chat
AI_DRIVER=deepseek php artisan chat
```

## 添加新的 AI 驱动

1. 在 `config/ai.php` 的 `drivers` 中添加配置
2. 如果使用 OpenAI 兼容 API：在 `AiManager` 中添加 `createXxxDriver()` 返回 `OpenAiCompatibleDriver`
3. 如果 API 格式不同：创建新 Driver 实现 `AiDriver` 接口
4. `.env` 中添加对应环境变量

## 开发路线图

- [x] **第一期：MVP** — MCP Server + Tool + AI 驱动抽象层 + CLI 对话
- [ ] **第二期：接入企微** — 回调接口、异步队列、多轮对话上下文（进行中）
- [ ] **第三期：功能扩展** — 智能排期、部门级邀请、用户偏好挖掘
- [ ] **第四期：架构升级** — MCP HTTP 模式部署、接入 Claude Desktop

## 技术栈

- [Laravel 12](https://laravel.com)
- [laravel/mcp](https://github.com/laravel/mcp) — MCP Server 实现
- [overtrue/pinyin](https://github.com/overtrue/laravel-pinyin) — 拼音转换
- [w7corp/easywechat](https://github.com/w7corp/easywechat) — 企业微信 SDK
- [Pest](https://pestphp.com) — 测试框架

## License

[MIT](LICENSE)

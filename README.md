# WeCom MCP - 企业微信 AI 助手

基于 Laravel + MCP 协议构建的企业微信 AI 助手。用户通过自然语言指令，由 AI 自动识别意图并调用企业微信 API 完成操作。

> "帮我明天下午3点和张三开个需求评审会" → AI 自动搜索联系人 → 创建会议 → 返回会议信息

## 功能特性

- **自然语言驱动** — 用口语化指令完成企微操作，无需学习 API
- **MCP Tool 架构** — 基于 [Model Context Protocol](https://modelcontextprotocol.io)，可扩展的工具体系
- **多模型支持** — Ollama / Claude / OpenAI / DeepSeek 一键切换
- **拼音智能匹配** — 支持同音字、首字母缩写匹配联系人（"王伟" = "汪伟"）
- **用户记忆** — 自动记住用户偏好和习惯，个性化响应
- **个性化 Profile** — 自定义 AI 名字、人设、欢迎语，进入会话自动问候
- **定时任务** — 一次性（"30分钟后提醒我"）和周期性（"每天9点发日报提醒"）定时消息
- **外部联系人管理** — 客户信息同步、搜索、回调实时更新，支持按时间范围查询
- **聊天记录智能分析** — 基于会话内容存档，AI 自动提取待办/决策/时间节点，每日生成工作日报推送
- **Claude Code Skills** — 内置 API 文档查询 Skill，辅助开发

## 已支持的 MCP Tools

| 分类 | Tool | 说明 |
|------|------|------|
| 会议 | CreateMeetingTool | 创建在线视频会议 |
| | QueryMeetingsTool | 查询会议列表 |
| | GetMeetingInfoTool | 获取会议详情 |
| | UpdateMeetingTool | 修改会议 |
| | CancelMeetingTool | 取消会议 |
| 日程 | CreateCalendarTool | 创建日历（私人/公共/企业） |
| | QueryCalendarsTool | 查询日历列表 |
| | CreateScheduleTool | 创建日程，多日历时自动推荐 |
| | QuerySchedulesTool | 查询日程列表 |
| | GetScheduleDetailTool | 获取日程详情 |
| | CancelScheduleTool | 取消日程 |
| 会议室 | QueryMeetingRoomsTool | 查询会议室列表 |
| | BookMeetingRoomTool | 预定会议室 |
| | QueryRoomBookingsTool | 查询预定记录 |
| | CancelRoomBookingTool | 取消预定 |
| 群聊 | CreateGroupChatTool | 创建群聊 |
| | UpdateGroupChatTool | 修改群聊（改名/换群主/增减成员） |
| | GetGroupChatTool | 获取群聊详情 |
| | QueryGroupChatsTool | 查询我创建/参与的群聊 |
| | SendGroupMessageTool | 推送消息到群聊（支持 @成员） |
| 联系人 | SearchContactsTool | 搜索内部联系人（拼音四级匹配） |
| 外部联系人 | SearchExternalContactsTool | 搜索外部联系人/客户（拼音四级匹配 + 备注名） |
| | ListExternalContactsTool | 列出外部联系人，支持按员工和时间范围筛选 |
| 记忆 | SaveMemoryTool | 保存用户偏好/习惯 |
| | DeleteMemoryTool | 删除记忆 |
| 个性化 | SetProfileTool | 设置 AI 名字/人设/欢迎语等 |
| | GetProfileTool | 查看当前个性化配置 |
| 定时任务 | CreateOnetimeTaskTool | 创建一次性定时任务（"30分钟后提醒我"） |
| | CreateRecurringTaskTool | 创建周期性定时任务（每天/工作日/每周/每月） |
| | QueryScheduledTasksTool | 查询定时任务列表 |
| | CancelScheduledTaskTool | 取消定时任务 |

## 聊天记录智能分析

基于企微会话内容存档，每日自动分析员工聊天记录，提取结构化工作洞察并生成日报推送。

**分层知识架构：**

```
Layer 2: 用户日报（每人每天一份，推送给用户）
Layer 1: 对话摘要（每对话对每天一条，AI 分析压缩）
Layer 0: 原始聊天记录（外部 MySQL，按需回溯）
```

**5 类洞察提取：**

| 类型 | 说明 | 示例 |
|------|------|------|
| 待办事项 | 对话中的任务分配和工作请求 | "帮我看一下登录Bug" |
| 重要决策 | 双方达成一致的结论 | "就用方案B吧" |
| 关键时间节点 | 提到的截止日期 | "周五前提测" |
| 未回复检测 | 工作问题未得到回应 | 问了接口文档位置没回复 |
| 工作总结 | 对话核心内容概要 | 今日主要讨论了上线计划 |

**待办生命周期管理：**

```
open → completed（对话中确认完成）
open → expired（超期未完成）→ reminded（日报提醒）
reminded → completed / ignored / open（用户回复操作）
```

**使用方式：**

```bash
php artisan chat:analyze-daily                     # 分析昨天的聊天记录
php artisan chat:analyze-daily --date=2026-03-15   # 分析指定日期
php artisan chat:analyze-daily --backfill          # 冷启动，回溯分析近 N 天
php artisan chat:push-reports                      # 推送日报给员工
php artisan chat:push-reports --force              # 强制推送（忽略推送日限制）
```

## 环境要求

- PHP >= 8.2
- Composer
- MySQL
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

### 4. 配置聊天分析（可选）

如果需要使用聊天记录智能分析功能，配置外部聊天记录数据库连接：

```bash
CHAT_RECORDS_DB_HOST=127.0.0.1
CHAT_RECORDS_DB_PORT=3306
CHAT_RECORDS_DB_DATABASE=chat_records
CHAT_RECORDS_DB_USERNAME=root
CHAT_RECORDS_DB_PASSWORD=
```

初始化分析配置：

```bash
php artisan db:seed --class=ChatAnalysisConfigSeeder
```

### 5. 同步通讯录

```bash
php artisan wecom:sync-contacts              # 同步内部通讯录
php artisan wecom:sync-external-contacts     # 同步外部联系人
```

### 6. 开始对话

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

+-----------------------------------------------------+
|              聊天记录智能分析（异步）                   |
|  外部 MySQL → 采集 → AI 分析 → 洞察管理 → 日报推送    |
+-----------------------------------------------------+
```

**核心设计原则：**

- **Tool 参数隔离** — Tool 只暴露业务参数（title、start_time），access_token、corp_id 等基础设施参数由 Service 层内部管理，LLM 不可见
- **AI 驱动抽象** — Manager 模式，内部统一为 Claude 风格消息格式，Driver 负责格式转换
- **拼音四级匹配** — 精确 → 拼音全匹配 → 首字母 → 模糊，多候选时 AI 自动追问
- **个性化 Profile** — 自定义 AI 身份和交互风格，`enter_chat` 事件触发欢迎语
- **定时任务双机制** — 一次性任务用 Queue `dispatch()->delay()`，周期性任务用 Scheduler 每分钟检查
- **分层知识架构** — 聊天分析采用 L0→L1→L2 三层压缩，后续分析优先读压缩层，控制 token 消耗

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
│   └── Tools/                  # 31 个 MCP Tool
├── Models/                     # Eloquent 模型
├── Services/
│   ├── ChatService.php         # AI 对话编排（tool 循环）
│   ├── ContactsService.php     # 内部联系人拼音匹配
│   ├── ExternalContactService.php # 外部联系人管理
│   ├── UserProfileService.php  # 用户个性化 Profile
│   ├── ScheduledTaskService.php # 定时任务调度
│   └── ChatAnalysis/           # 聊天记录智能分析
│       ├── ChatAnalysisService.php    # 主编排器
│       ├── MessageCollector.php       # 消息采集
│       ├── ConversationAnalyzer.php   # Phase 1 对话级 AI 分析
│       ├── ReportGenerator.php        # Phase 2 日报生成
│       ├── InsightManager.php         # 洞察生命周期管理
│       └── AnalysisConfigService.php  # 配置管理
├── Wecom/                      # 企微 API 客户端
├── Jobs/                       # Queue Jobs
└── Console/Commands/           # CLI 命令
config/ai.php                   # AI 驱动配置
config/database.php             # 数据库配置（含外部聊天记录库）
skills/                         # Claude Code Skills
docs/                           # 设计文档
```

## 常用命令

```bash
php artisan chat                      # 交互式对话
php artisan test                      # 运行测试
php artisan wecom:sync-contacts       # 同步内部通讯录
php artisan wecom:sync-external-contacts  # 同步外部联系人
php artisan chat:analyze-daily        # 分析昨日聊天记录
php artisan chat:push-reports         # 推送日报
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
- [x] **第二期：接入企微** — 回调接口、异步队列、多轮对话上下文
- [x] **第三期：功能扩展** — 外部联系人管理、聊天记录智能分析
- [ ] **第四期：架构升级** — MCP HTTP 模式部署、接入 Claude Desktop、周报/月报

## 技术栈

- [Laravel 12](https://laravel.com)
- [laravel/mcp](https://github.com/laravel/mcp) — MCP Server 实现
- [overtrue/pinyin](https://github.com/overtrue/laravel-pinyin) — 拼音转换
- [w7corp/easywechat](https://github.com/w7corp/easywechat) — 企业微信 SDK
- [Pest](https://pestphp.com) — 测试框架

## License

[MIT](LICENSE)

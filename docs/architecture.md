# 架构设计

## 整体架构

```
企微用户(语音/文字)
    │
    ▼
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  接入层       │────▶│  AI 编排层        │────▶│  MCP Tool 执行层  │
│  企微回调     │     │  AiDriver        │     │  企微 API 调用    │
│  /api/wecom  │◀────│  + Tool Use      │◀────│  结果返回         │
└─────────────┘     └──────────────────┘     └─────────────────┘
```

## 消息处理流程

```
1. 企微推送消息 → POST /api/wecom/callback
2. Laravel 解密消息，提取文字内容（语音消息企微自带 ASR 转文字）
3. 异步 Job 处理：
   a. 加载对话历史（Redis）
   b. 组装 system prompt（注入当前时间、用户身份）
   c. 携带 MCP Tool 定义，调用 AI 模型（通过 AiDriver）
   d. AI 返回 tool_use → 执行对应 MCP Tool
   e. Tool 执行结果回传 AI → AI 生成自然语言回复
   f. 通过企微 API 发送回复给用户
4. 如果有歧义（如同音字），AI 自动追问，进入多轮对话
```

## AI 驱动层

采用 Laravel Manager 模式，支持多模型切换：

```
ChatService (编排层)
    │
    ▼
AiManager extends Manager implements AiDriver
    │
    ├── AnthropicDriver          ← Claude API 原生格式
    └── OpenAiCompatibleDriver   ← Ollama / OpenAI / DeepSeek 通用
```

- 内部消息格式统一使用 Claude 风格（content blocks）
- 每个 Driver 负责格式转换（unified ↔ provider-specific）
- `OpenAiCompatibleDriver` 覆盖所有 OpenAI 兼容 API，仅配置不同
- 通过 `AI_DRIVER` 环境变量一键切换

### 添加新 AI 驱动

1. 在 `config/ai.php` 的 `drivers` 中添加配置
2. 如果使用 OpenAI 兼容 API → 在 `AiManager` 中添加 `createXxxDriver()` 返回 `OpenAiCompatibleDriver`
3. 如果 API 格式不同 → 创建新 Driver 实现 `AiDriver` 接口
4. `.env` 添加对应环境变量

## MCP Tool 参数设计原则

Tool 对 LLM 只暴露业务参数，基础设施参数在 Tool 内部处理：

| 参数类型 | 示例 | 谁管理 | LLM 可见 |
|---------|------|--------|---------|
| 固定配置 | corp_id, secret | .env + config | 否 |
| 动态凭证 | access_token | Service 自动刷新 | 否 |
| 内部转换 | 姓名→userid | ContactsService | 否 |
| 业务参数 | title, start_time | LLM 从对话提取 | 是 |

## 同音字匹配策略

`ContactsService::searchByName()` 四级匹配：

```
1. 精确匹配: name = "汪伟"
2. 拼音全匹配: name_pinyin = "wang wei"（同音字核心方案）
3. 首字母匹配: name_initials = "ww"（简称场景）
4. 模糊匹配: name LIKE "%汪%"（兜底）
```

contacts 表含 `name_pinyin` 和 `name_initials` 字段，由 `ContactsService::generatePinyin()` 生成。匹配多人时返回候选列表让 AI 追问确认。

## 企微 access_token 管理（第二期）

```php
Cache::remember('wecom:access_token', 7000, function () {
    // 调用 /cgi-bin/gettoken，提前 5 分钟刷新
});
```

## 通讯录同步策略（第二期）

- 启动时全量同步
- 每小时定时增量同步
- 同步时自动生成拼音字段
- 查询未命中时实时调企微 API 兜底

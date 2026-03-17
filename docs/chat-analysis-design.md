---
name: 聊天分析功能设计方案
description: 基于会话内容存档的自动分析功能 — 完整设计方案，包含待办提取、工作总结、决策记录、未回复检测、时间节点提取
type: project
---

## 功能概述
自动分析企微聊天记录，每日提取 5 类结构化洞察（todo/decision/deadline/pending/summary），生成日报推送给员工。

## 已确认的设计决策

### 数据源
- 聊天记录已由外部程序存入 MySQL（work_wechat_chat_records 表）
- 本项目通过第二数据库连接（chat_records）读取，只读
- 当前版本只分析 **单聊 + 文本消息**
- 不考虑租户（tenant_id），只分析当前企业
- 日均约 3000 条单聊文本，40~60 个活跃对话对

### 分层知识架构
- Layer 0: 原始聊天记录（外部 MySQL）
- Layer 1: 对话摘要（chat_analysis_summaries，每对话对每天一条）
- Layer 2: 用户日报（chat_analysis_reports，每人每天一份）
- 查询优先级：L2 → L1 → L0，控制 token 消耗

### AI 分析
- 使用商业模型（Claude / GPT / DeepSeek），通过配置表独立指定
- 两阶段分析：Phase 1 对话级提取 → Phase 2 用户级汇总
- 长对话按时间分段，多次分析
- Phase 1 输入包含近 N 天历史摘要 + open insights，实现跨天连续性
- 预估日均 20~30 万 tokens

### 执行策略
- 分析和推送时间分开（凌晨分析，早上推送）
- 周末正常分析但不推送，周一合并推送周五~周日
- 冷启动时回溯分析最近 N 天历史数据

### 用户关联
- from/to 优先关联 contacts 表获取姓名
- 回退使用聊天记录自带的 from_name/to_name
- 机器人消息（wb 开头）跳过
- 外部联系人对话照常分析，日报只推给内部员工

### 待办生命周期
- open → completed（对话中确认）
- open → expired（超 N 天未完成）→ reminded（日报提醒）
- reminded → ignored（用户选择忽略）/ completed / open（继续跟踪）
- 用户通过回复 bot 消息操作：「完成1」「忽略1」

### 配置
- 独立配置表 chat_analysis_configs（key-value + group 分组）
- 分组：schedule / ai / scope / lifecycle

### 数据表
- chat_analysis_configs: 配置表
- chat_analysis_summaries: Layer 1 对话摘要
- chat_analysis_insights: 结构化洞察（含生命周期状态）
- chat_analysis_reports: Layer 2 用户日报

### 代码结构
- app/Services/ChatAnalysis/: 6 个 Service 类
- app/Models/: 4 个 Model + 1 个外部库 Model
- app/Console/Commands/: 2 个 Artisan 命令

### 后续扩展（不在本期）
- MCP Tools（QueryChatInsightsTool 等按需查询）
- 群聊分析
- 多媒体消息分析
- 周报/月报

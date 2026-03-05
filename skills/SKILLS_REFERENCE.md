# Claude Skills 设计参考手册

> 基于 Anthropic 官方文档《The Complete Guide to Building Skills for Claude》整理
> 用于指导企微 AI 助手项目的 Skill、MCP Tool、System Prompt 设计

---

## 一、Skill 是什么

Skill 是一个文件夹，通过结构化的指令教会 Claude 处理特定任务或工作流。
核心价值：把你的流程、偏好、领域知识教给 Claude 一次，之后每次对话自动生效。

### 适用场景

- 可重复的工作流（从需求生成设计、用固定方法做研究、按团队规范写文档）
- 多步骤流程编排（跨系统的复杂操作）
- MCP 工具的最佳实践封装（把"能调什么 API"变成"怎么高效地调 API"）

### 三个核心设计原则

```
渐进式加载（Progressive Disclosure）
  不是一次性把所有内容塞给 Claude，而是分三级按需加载，节省 tokens。

可组合性（Composability）
  多个 Skill 可以同时加载，互不干扰。设计时不要假设你的 Skill 是唯一的。

可移植性（Portability）
  同一个 Skill 在 Claude.ai、Claude Code、API 中都能工作，无需修改。
```

---

## 二、三级渐进式加载（最重要的架构设计）

这是 Skill 系统最核心的设计思想，直接影响 token 消耗和响应速度。

```
第一级: YAML frontmatter（始终加载）
  ┌──────────────────────────────────────┐
  │ name: wecom-meeting-assistant        │
  │ description: 创建、查询、管理企微会议  │  ← ~100 tokens
  │   Use when user mentions "开会"...    │
  └──────────────────────────────────────┘
  作用: Claude 根据这个判断"要不要启用这个 Skill"
  原则: 只放最少的信息，但必须足够精准

第二级: SKILL.md 正文（按需加载）
  ┌──────────────────────────────────────┐
  │ # 核心行为准则                        │
  │ # 推理链示例                          │  ← ~2,000 tokens
  │ # 交互行为规则                        │
  │ # 工具使用指南                        │
  └──────────────────────────────────────┘
  作用: Claude 认为 Skill 相关时才读取完整指令
  原则: 聚焦核心指令，保持在 5,000 词以内

第三级: references/ 目录（深度按需加载）
  ┌──────────────────────────────────────┐
  │ references/meeting-api.md            │
  │ references/schedule-api.md           │  ← 按需读取
  │ references/contacts-api.md           │
  └──────────────────────────────────────┘
  作用: Claude 需要具体参数或示例时才加载
  原则: 详细文档、API 参考、示例代码都放这里

企微项目对应关系:
  第一级 = Tool 的 description（始终在 prompt 中）
  第二级 = System Prompt 的核心规则和推理链示例
  第三级 = 企微 API 文档（你正在整理的 md 文件）
```

---

## 三、文件结构规范

### 标准目录结构

```
your-skill-name/                  # 必须用 kebab-case
├── SKILL.md                      # 必须，且必须大写（区分大小写）
├── scripts/                      # 可选，可执行脚本
│   ├── process_data.py
│   └── validate.sh
├── references/                   # 可选，按需加载的参考文档
│   ├── api-guide.md
│   └── examples/
└── assets/                       # 可选，模板、字体、图标等
    └── report-template.md
```

### 命名规则

```
文件夹命名:
  ✅ wecom-meeting-assistant（kebab-case）
  ❌ Wecom Meeting Assistant（有空格和大写）
  ❌ wecom_meeting_assistant（下划线）
  ❌ WecomMeetingAssistant（驼峰）

SKILL.md:
  ✅ SKILL.md（必须完全匹配，区分大小写）
  ❌ skill.md / SKILL.MD / Skill.md

其他:
  ❌ 不要在 Skill 文件夹内放 README.md
  ❌ name 和 description 中不能有 XML 尖括号 < >
  ❌ name 中不能包含 "claude" 或 "anthropic"（保留词）
```

---

## 四、YAML Frontmatter 编写规范

### 最小必填格式

```yaml
---
name: wecom-meeting-assistant
description: >
  做什么 + 什么时候用 + 关键能力。
  Include specific trigger phrases users might say.
---
```

### 完整格式（含可选字段）

```yaml
---
name: wecom-meeting-assistant
description: >
  企业微信会议和日程管理助手。通过 MCP 工具创建、查询、修改、取消企微会议，
  管理日程和发送消息。支持同音字智能匹配参会人。
  Use when user mentions "开会""建个会""查会议""取消会议""日程""提醒"
  或任何涉及企业微信会议和日程管理的请求。
  不处理审批流、打卡、汇报等非会议日程类功能。
license: MIT
compatibility: >
  需要 Laravel MCP Server 运行环境，企业微信自建应用权限。
metadata:
  author: YourName
  version: 1.0.0
  mcp-server: wecom-server
  category: productivity
  tags: [meeting, schedule, wecom, enterprise]
---
```

### description 好坏示例

```yaml
# ✅ 好：具体、有触发短语、有边界
description: >
  管理企业微信在线会议的完整生命周期，包括创建、查询、修改和取消会议。
  支持通过姓名（含同音字模糊匹配）邀请参会人，支持会议室预定。
  Use when user says "开会""建个会议""查看今天的会""取消刚才那个会"
  "帮我订个会议室""明天和张三开个会"。
  不处理审批、打卡、汇报等功能，这些请使用 wecom-oa-assistant。

# ❌ 差：太模糊
description: 帮助管理会议。

# ❌ 差：缺少触发短语
description: 企业微信会议管理系统，提供完整的CRUD操作。

# ❌ 差：太技术化
description: 封装企微 /cgi-bin/meeting/* 系列 API 的调用逻辑。
```

---

## 五、SKILL.md 正文编写模板

```markdown
---
name: your-skill
description: [做什么 + 什么时候用 + 触发短语]
---

# Skill 名称

## 核心指令
[最重要的行为规则放在最前面，Claude 对开头内容注意力最强]

## 工作流程

### 步骤1: [第一步]
清楚说明做什么、用什么工具。
```bash
python scripts/fetch_data.py --project-id PROJECT_ID
```
预期结果: [成功长什么样]

### 步骤2: [第二步]
...

## 推理链示例

### 示例1: [常见场景名]
用户说: "具体的用户表达"
操作:
1. 调用 tool_a(参数)
2. 根据结果调用 tool_b(参数)
结果: [最终效果]

### 示例2: [另一个场景]
...

## 故障排除

### 错误: [常见错误信息]
原因: [为什么发生]
解决: [怎么修]
```

### 编写最佳实践

```
✅ 具体且可操作
  "运行 python scripts/validate.py --input {filename} 检查数据格式。
   如果验证失败，常见问题包括：
   - 缺少必填字段（在CSV中补充）
   - 日期格式错误（使用 YYYY-MM-DD）"

❌ 模糊笼统
  "在继续之前验证数据。"

✅ 关键指令放最前面
  用 ## Important 或 ## Critical 标题
  必要时重复关键点

❌ 关键指令埋在中间
  Claude 对长文档中间部分的注意力会下降

✅ 用代码做关键验证
  代码是确定性的，自然语言理解有偶然性
  参考: Anthropic 的 Office Skills 就用脚本做验证

❌ 全靠自然语言指令做验证
  "确保格式正确" → Claude 可能跳过
```

---

## 六、references/ 目录组织规范

> 严格遵循 Agent Skills 官方规范：
> "Keep file references one level deep from SKILL.md. Avoid deeply nested reference chains."

### 核心原则

- **扁平结构，不使用子文件夹**（严格遵循官方规范）
- 通过文件名前缀体现模块归属
- SKILL.md 中的索引是模型按需加载的唯一依据
- 影响模型质量的是：文件内容是否清晰、聚焦、格式统一

### 目录结构规则

```
references/ 目录直接存放所有文件，通过文件名前缀分类:

references/
├── contacts-member.md              ← 通讯录 - 成员管理
├── contacts-department.md          ← 通讯录 - 部门管理
├── contacts-tag.md                 ← 通讯录 - 标签管理
├── contacts-callback.md            ← 通讯录 - 回调通知
├── meeting-crud.md                 ← 会议 - 基础增删改查
├── meeting-room.md                 ← 会议 - 会议室管理
├── schedule-crud.md                ← 日程 - 基础操作
├── message-send.md                 ← 消息 - 发送消息
├── message-callback.md             ← 消息 - 消息回调
├── approval-crud.md                ← 审批 - 基础操作
└── ...
```

### 命名规范

```
格式: {模块名}-{子模块名}.md

规则1: 全部小写，使用连字符分隔
  ✅ contacts-member.md
  ❌ Contacts_Member.md

规则2: 前缀 = 业务模块，与 SKILL.md 索引分组一致
  contacts-*    通讯录相关
  meeting-*     会议相关
  schedule-*    日程相关
  message-*     消息相关
  approval-*    审批相关
  attendance-*  打卡相关
  oa-*          OA 相关

规则3: 单个文件 = 一个业务子模块的所有接口
  contacts-member.md 包含: 创建、读取、更新、删除、批量获取成员
  而不是每个接口一个文件

规则4: 单个文件控制在 2,000-3,000 tokens 以内
  如果某个子模块接口太多，按功能拆分:
  contacts-member-crud.md（基础增删改查）
  contacts-member-batch.md（批量操作）

规则5: 不建立任何子文件夹
  ✅ references/contacts-member.md
  ❌ references/contacts/member.md
  ❌ references/contacts/member/crud.md
```

### 文件内容模板

每个 API 文档文件应遵循统一格式：

```markdown
# [模块名] - [子模块名]

## 接口清单
- 接口1名称
- 接口2名称
- ...

## 接口1名称
- 接口: POST /cgi-bin/xxx
- 必填参数: param1, param2
- 可选参数: param3, param4
- 返回: errcode, errmsg, data
- 注意事项: 特殊限制或常见问题

## 接口2名称
...
```

### SKILL.md 中的索引（关键）

SKILL.md 中必须包含完整的文件索引，这是模型按需加载的唯一依据。
通过 Markdown 标题对索引进行逻辑分组，弥补扁平目录缺少层级的不足：

```markdown
## API 参考文档索引

### 通讯录
- [成员管理](references/contacts-member.md) - 创建/读取/更新/删除成员
- [部门管理](references/contacts-department.md) - 部门增删改查和部门树
- [标签管理](references/contacts-tag.md) - 标签创建和成员管理
- [回调通知](references/contacts-callback.md) - 变更事件回调

### 会议
- [会议管理](references/meeting-crud.md) - 创建/查询/修改/取消
- [会议室](references/meeting-room.md) - 查询和预定

### 日程
- [日程管理](references/schedule-crud.md) - 日程增删改查

### 消息
- [发送消息](references/message-send.md) - 应用消息和群聊消息
- [消息回调](references/message-callback.md) - 接收消息回调
...
```

### 索引质量要求

- 每条索引包含：链接 + 一句话说明覆盖哪些接口
- 通过 Markdown ### 标题按业务模块分组
- 索引属于 SKILL.md 第二级内容，Skill 激活时加载一次
- 模型扫一遍索引后根据当前任务决定读哪个文件，不会全部加载
- 文件之间不要互相引用，每个文件保持自包含

---

## 七、五种工作流设计模式

### 模式1: 顺序流程编排

**适用场景:** 多步骤必须严格按顺序执行。

```
企微示例: 创建会议完整流程

步骤1: 检查冲突
  → 调用 check_schedule_conflict
  → 通过则继续，冲突则提示用户

步骤2: 解析参会人
  → 调用 search_contacts（拼音匹配）
  → 有歧义则返回候选列表

步骤3: 创建会议
  → 调用 create_meeting
  → 失败则告知原因

步骤4: 通知参会人
  → 调用 send_message
  → 告知会议链接和时间

关键技巧:
  - 步骤之间有明确的依赖关系
  - 每步有验证，失败有回退指令
  - 数据在步骤间传递（步骤1的结果供步骤3使用）
```

### 模式2: 多 MCP 协调

**适用场景:** 一个工作流跨越多个服务/系统。

```
企微示例（未来扩展）: 会议全流程管理

阶段1: 企微 MCP
  → 创建会议、邀请参会人

阶段2: 邮件 MCP
  → 发送会议议程给外部参会人

阶段3: 文档 MCP
  → 在共享文档中创建会议纪要模板

阶段4: 企微消息 MCP
  → 在群聊中通知会议信息

关键技巧:
  - 阶段之间有清晰分界
  - 数据在不同 MCP 之间传递
  - 进入下一阶段前做验证
  - 统一的错误处理
```

### 模式3: 迭代优化

**适用场景:** 输出需要经过多轮打磨。

```
示例: 会议纪要生成

第一轮: 生成初稿
  → 根据会议录音/聊天记录生成纪要

质量检查:
  → 运行验证脚本检查：
    - 是否有遗漏的议题
    - 格式是否一致
    - 待办事项是否明确

优化循环:
  → 修复问题 → 重新验证 → 直到达标

最终输出:
  → 应用格式 → 生成摘要 → 保存

关键技巧:
  - 明确的质量标准
  - 关键验证用脚本（代码确定性 > 语言理解）
  - 知道何时停止迭代
```

### 模式4: 上下文感知选择

**适用场景:** 同样的目标，根据上下文选择不同工具。

```
企微示例: 会议 vs 日程

判断逻辑:
  用户说"开会""视频会议""在线会议"
    → 使用 CreateMeetingTool

  用户说"提醒我""记一下""安排个事"
    → 使用 CreateScheduleTool

  用户说"订个会议室""要个10人的房间"
    → 使用 BookMeetingRoomTool

关键技巧:
  - 清晰的判断条件
  - 有兜底选项（判断不了就追问）
  - 向用户解释为什么选择了这个工具
```

### 模式5: 领域知识嵌入

**适用场景:** Skill 不只是调工具，还包含专业知识。

```
企微示例: 智能会议安排

嵌入的领域知识:
  - 10 人以上的会优先用大会议室
  - 跨时区会议自动换算时间
  - 下午 1-2 点是午休时间，避免安排会议
  - 周五下午尽量不安排长会
  - 重要会议提前 30 分钟发提醒

  这些规则不在任何 API 文档里，是团队的隐性知识。
  Skill 把这些知识固化下来，每次创建会议都自动应用。

关键技巧:
  - 领域知识嵌入在逻辑中
  - 操作前做合规/合理性检查
  - 完整的决策记录
```

---

## 八、测试方法

### 三层测试

```
1. 触发测试（Skill 什么时候加载）
   ✅ 应该触发:
     - "帮我建个明天10点的会"
     - "查看今天的会议安排"
     - "取消刚才那个会议"
   ❌ 不应该触发:
     - "今天天气怎么样"
     - "帮我写段代码"
     - "提交一个审批"

2. 功能测试（输出是否正确）
   - 会议是否成功创建
   - 参会人是否正确匹配
   - 时间转换是否准确
   - 错误处理是否友好

3. 性能对比（有 Skill vs 没 Skill）
   没有 Skill:
     - 用户每次重新解释需求
     - 15 轮对话才完成
     - 3 次 API 调用失败
     - 消耗 12,000 tokens

   有 Skill:
     - 自动执行工作流
     - 只需 2 次确认
     - 0 次 API 失败
     - 消耗 6,000 tokens
```

### 调试技巧

```
Skill 不触发?
  → 直接问 Claude: "你什么时候会用 wecom-meeting-assistant 这个 skill？"
  → Claude 会说出它对 description 的理解
  → 根据回答调整 description

Skill 过度触发?
  → 在 description 中加否定条件:
    "不处理审批、打卡、汇报等功能"
  → 更具体地限定范围

指令不被遵循?
  → 关键指令移到最前面
  → 用 ## Critical 标题强调
  → 考虑用脚本替代自然语言验证

响应变慢?
  → SKILL.md 控制在 5,000 词以内
  → 详细文档移到 references/
  → 减少同时启用的 Skill 数量（建议不超过 20-50 个）
```

### 迭代优化信号

```
触发不足的信号:
  - 用户手动启用 Skill
  - 用户问"什么时候用这个功能"
  → 解决: 在 description 中加更多触发短语

触发过度的信号:
  - 无关问题也触发了 Skill
  - 用户主动关闭 Skill
  → 解决: 加否定条件，缩小范围

执行问题的信号:
  - 结果不一致
  - API 调用失败
  - 需要用户纠正
  → 解决: 改进指令，加错误处理
```

---

## 九、分发与部署

### 部署方式

```
个人使用:
  Claude.ai → Settings > Capabilities > Skills → 上传 zip
  Claude Code → 放入 skills 目录

组织级部署:
  管理员可在工作区统一部署 Skill
  支持自动更新和集中管理

API 调用:
  通过 /v1/skills 端点管理
  在 Messages API 中通过 container.skills 参数传入
  适合: 生产环境、自动化流水线、Agent 系统
```

### 选择部署方式

```
场景                              推荐方式
──────────────────────────        ──────────────
个人日常使用                       Claude.ai / Claude Code
开发调试                          Claude.ai / Claude Code
团队标准化                         组织级部署
应用程序集成（如你的 Laravel 项目）   API 方式
生产环境大规模部署                   API 方式
自动化流水线                       API 方式
```

---

## 十、MCP + Skill 协作模型

### 厨房类比

```
MCP = 专业厨房（提供工具、食材、设备）
  → 连接企微 API，提供数据访问和工具调用

Skill = 菜谱（怎么用这些工具做出好菜）
  → 工作流、最佳实践、领域知识

两者结合 = 用户不需要自己摸索每一步
```

### 没有 Skill vs 有 Skill

```
没有 Skill:
  ❌ 用户连了 MCP 但不知道能做什么
  ❌ 每次对话从零开始
  ❌ 不同用户得到不一致的结果
  ❌ 用户把"不好用"怪罪到 MCP 上

有 Skill:
  ✅ 预设的工作流在需要时自动触发
  ✅ 一致、可靠的工具使用
  ✅ 最佳实践嵌入每次交互
  ✅ 降低学习成本
```

---

## 十一、成功标准参考

### 量化指标（大致基准）

```
触发准确率: 90% 的相关查询能正确触发 Skill
  测试方法: 准备 10-20 个测试查询，统计触发率

工作流效率: 对比有无 Skill 的 tool call 次数和 token 消耗
  测试方法: 同一任务跑两遍，对比数据

API 成功率: 每个工作流 0 次 API 调用失败
  测试方法: 监控 MCP Server 日志
```

### 质量指标

```
用户不需要提示下一步: 
  测试方法: 观察测试中你需要纠正或引导 Claude 的频率

工作流无需用户修正即可完成:
  测试方法: 同一请求跑 3-5 次，对比输出的一致性

新用户首次即可成功:
  测试方法: 让不了解系统的人试用，观察是否能一次成功
```

---

## 十二、对企微 AI 助手项目的应用建议

### 当前阶段（MVP）

```
你现在做的事:
  System Prompt 中的行为规则 = Skill 第二级内容
  Tool 的 description = Skill 第一级内容
  企微 API 文档整理 = Skill 第三级 references/

不需要改变现有架构，但可以用 Skill 的思维方式来优化:
  - description 遵循 "做什么 + 什么时候用 + 触发短语" 格式
  - System Prompt 按五种模式设计推理链
  - API 文档按 references/ 的方式组织，按需引用
```

### 未来演进

```
阶段1: 保持现有 Laravel MCP + Claude API 架构
  → 用 Skill 思维优化 prompt 和 tool description

阶段2: 将核心指令打包成标准 Skill
  → SKILL.md + references/
  → 可以在 Claude.ai 和 Claude Code 中直接使用

阶段3: 通过 API 的 container.skills 参数集成
  → Laravel 应用调 Claude API 时传入 Skill
  → 比手写 System Prompt 更标准化

阶段4: 发布为社区 Skill
  → 其他有企微 MCP Server 的团队可以直接使用
  → 配合你的 MCP Server 形成完整的解决方案
```

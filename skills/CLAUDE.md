# CLAUDE.md — 企微 AI 助手 Skill 生产环境

> 本文件专用于生成和维护企业微信 AI 助手的 Skill 文件。
> Claude Code 在此项目目录中工作时，以本文件为最高优先级指导。

---

## 强制前置步骤（每次任务开始前必须执行）

接到任何 Skill 生成或修改任务时，**按顺序**完成以下读取，不得跳过：

```
Step 1. 读取 SKILLS_REFERENCE.md          ← 完整规范，必读
Step 2. 读取 skills/examples/wecom-schedule-assistant/SKILL.md
                                           ← 高质量 SKILL.md 样例，对照输出
Step 3. 读取 skills/examples/wecom-schedule-assistant/references/schedule-general.md
                                           ← 高质量 references 文件样例，对照输出
```

读取完成后，在心中默认：**我的输出结构、详细程度、风格必须与样例一致。**

---

## 项目目录结构

```
项目根目录/
├── CLAUDE.md                                  ← 本文件
├── SKILLS_REFERENCE.md                        ← 完整规范文档
├── skills/
│   ├── examples/                              ← few-shot 样例（只读，不要修改）
│   │   └── wecom-schedule-assistant/
│   │       ├── SKILL.md
│   │       └── references/
│   │           └── schedule-general.md
│   ├── wecom-schedule-assistant/              ← 已完成
│   ├── wecom-meeting-assistant/               ← 待生成
│   ├── wecom-contacts-assistant/              ← 待生成
│   ├── wecom-approval-assistant/              ← 待生成
│   └── wecom-message-assistant/               ← 待生成
```

---

## Skill 文件生成规范

### 1. 目录和文件命名

```
Skill 文件夹:  kebab-case，如 wecom-meeting-assistant
主文件:        SKILL.md（必须大写，区分大小写）
references:    {模块}-{子模块}.md，全小写连字符

✅ wecom-meeting-assistant/SKILL.md
✅ wecom-meeting-assistant/references/meeting-crud.md
❌ WecomMeeting/skill.md
❌ wecom_meeting/references/MeetingCRUD.md
```

### 2. YAML description 必须包含四个要素

```
① 一句话说明做什么（功能概述）
② 支持的核心操作列表
③ Use when user says — 至少 8 个用户真实触发短语（口语化，非技术语言）
④ 边界否定 — 明确不处理什么，并指向负责该功能的其他 Skill
```

对照样例 `skills/examples/wecom-schedule-assistant/SKILL.md` 中的 description 格式输出。

### 3. SKILL.md 正文必须包含五个章节

```
## 核心指令        ← 最重要规则放最前，Claude 注意力最强
## 工作流程        ← 分步骤，每步说明调用什么接口
## 推理链示例      ← 至少 3 个真实场景，格式：用户说/操作/结果
## 故障排除        ← 覆盖 errcode 非0、权限不足、参数错误、配额超限
## API 参考文档索引 ← 按业务分组，每条：[名称](references/文件名.md) - 一句话说明
```

### 4. references 文件规范

```
- 每个文件 = 一个业务子模块的所有接口
- 文件大小控制在 2,000-3,000 tokens，超出则按功能拆分
- 不建立任何子文件夹（扁平结构）
- 文件之间不互相引用，每个文件保持自包含
- 禁止出现：无效图片链接 ![]()、无效锚点链接 (#id)
- 附录中的文件引用格式：[名称](文件名.md)，无 Tab 缩进，使用列表格式
```

对照样例 `skills/examples/wecom-schedule-assistant/references/schedule-general.md` 的格式输出。

---

## 生成新 Skill 的完整流程

收到"生成 wecom-xxx-assistant"的任务时，按以下流程执行：

```
1. 完成强制前置步骤（读取规范 + 两个样例文件）

2. 读取 api-raw/ 目录下对应模块的原始 API 内容

3. 规划文件结构：
   - 确定需要哪些 references 文件
   - 确定 SKILL.md 中 API 索引的分组方式
   - 确认每个 references 文件大小不超限

4. 生成 SKILL.md：
   - YAML description 对照样例写，触发短语不少于 8 个
   - 推理链示例覆盖：创建、查询、修改/删除 三类操作
   - 故障排除覆盖模块特有的常见错误

5. 生成所有 references/*.md 文件

6. 执行自检清单（见下方）

7. 输出完成报告：列出生成的文件清单和自检结果
```

---

## 修改已有 Skill 的规则

```
- 修改前先完整读取目标文件，理解现有结构
- 只修改有问题的部分，不"顺手优化"其他内容
- API 参考文档索引的链接和分组：非必要不动
- 修改后执行完整自检清单
- 记录修改原因（在完成报告中说明）
```

---

## 自检清单（每次生成/修改后必须逐项验证）

完成文件输出后，对照以下清单检查，有不通过项立即修正后再输出结果。

### SKILL.md 检查项

```
YAML frontmatter:
- [ ] name 使用 kebab-case，不含 "claude" 或 "anthropic"
- [ ] name 和 description 中无 XML 尖括号 < >
- [ ] description 包含功能概述 + 核心操作 + 触发短语（≥8个）+ 边界否定
- [ ] description 的触发短语是用户口语，非技术术语
- [ ] metadata 包含 author / version / mcp-server / category / tags

正文结构:
- [ ] 存在 ## 核心指令，且关键规则在文件前半段
- [ ] 存在 ## 工作流程，步骤清晰
- [ ] 存在 ## 推理链示例，包含 ≥3 个场景
- [ ] 推理链每个示例包含：用户说 / 操作步骤 / 结果
- [ ] 存在 ## 故障排除，覆盖 errcode非0 / 权限 / 参数 / 配额
- [ ] 存在 ## API 参考文档索引，按业务模块分组
- [ ] 索引每条格式：[名称](references/文件名.md) - 一句话说明
- [ ] SKILL.md 正文控制在 5,000 词以内
```

### references/*.md 检查项

```
- [ ] 文件名格式：{模块}-{子模块}.md，全小写连字符
- [ ] 标题格式：# 模块名 - 子模块名
- [ ] 包含 ## 接口清单 列表
- [ ] 无 ![]() 图片语法
- [ ] 无 (#锚点) 格式的失效链接
- [ ] 附录文件引用格式为 (文件名.md)，使用 - 列表，无 Tab 缩进
- [ ] 单文件不超过 3,000 tokens（超出则拆分）
- [ ] 未引用其他 references 文件（保持自包含）
```

### 目录结构检查项

```
- [ ] Skill 文件夹名为 kebab-case
- [ ] 主文件名为 SKILL.md（完全大写）
- [ ] references/ 下无子文件夹
- [ ] 文件夹内无 README.md
```

全部通过后输出：`✅ 自检通过，共生成 X 个文件。`
有未通过项则输出：`❌ 以下检查项未通过：[列表]，正在修正...`，修正后重新自检。

---

## 禁止事项

```
❌ 不读规范和样例就直接生成
❌ description 只写功能描述，不写触发短语
❌ 推理链示例用技术接口名（如 /cgi-bin/schedule/create）代替自然语言步骤描述
❌ references 文件中保留无效图片链接或锚点链接
❌ references 目录下建立子文件夹
❌ 文件夹命名用下划线、驼峰或含空格
❌ 修改 examples/ 目录下的任何文件
❌ 跳过自检清单直接报告完成
```

---

## 快速参考：模块与文件名前缀对照

```
企微模块          Skill 文件夹名                  references 前缀
────────────────  ──────────────────────────────  ───────────────
日程管理          wecom-schedule-assistant        schedule-*
会议管理          wecom-meeting-assistant         meeting-*
通讯录            wecom-contacts-assistant        contacts-*
消息推送          wecom-message-assistant         message-*
审批流程          wecom-approval-assistant        approval-*
打卡考勤          wecom-attendance-assistant      attendance-*
OA 相关           wecom-oa-assistant              oa-*
```

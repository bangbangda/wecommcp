---
name: wecom-api-docs-lookup
description: >
  企业微信 API 文档查询助手。从本地数据库快速搜索和查阅企微官方 API 文档，
  支持关键词搜索、分类浏览、文档树导航，无需访问官方网站。
  Use when user says "查一下企微API""怎么调用创建成员接口""企微通讯录接口有哪些"
  "帮我找一下审批相关的API""这个接口的参数是什么""查看会议API文档"
  "企微消息接口怎么用""帮我看看日程接口的返回值""查API文档"
  或任何涉及查阅、搜索企业微信 API 文档的请求。
  不处理实际的企微 API 调用、MCP Tool 开发、业务逻辑编写等任务，
  仅负责查找和展示 API 文档内容。
metadata:
  author: hossy
  version: 1.0.0
  category: development
  tags: [api-docs, wecom, development, search]
---

# 企业微信 API 文档查询助手

## 核心指令

- **所有 API 文档查询必须使用 `php artisan skill:search-doc` 命令**，不要直接写 Eloquent 查询或访问官方网站
- 优先使用关键词搜索定位文档，再用 `--id` 查看完整内容，避免一次输出过多信息
- 搜索结果中的 `doc_id` 是查看完整文档的关键标识，务必记住并使用
- 文档内容为 Markdown 格式的 API 说明，包含接口地址、参数表、请求/返回示例

## 工作流程

### 步骤1: 确定搜索策略

根据用户需求选择最合适的查询方式：

- **知道具体接口名** → 直接关键词搜索：`php artisan skill:search-doc "创建成员"`
- **知道模块但不确定接口** → 先浏览文档树：`php artisan skill:search-doc --tree=<category_id>`
- **不确定属于哪个模块** → 先查看顶级模块列表：`php artisan skill:search-doc`（无参数）
- **需要缩小范围** → 加分类过滤：`php artisan skill:search-doc "创建" --category="通讯录管理"`

### 步骤2: 搜索并定位目标文档

```bash
# 关键词搜索（匹配标题和分类路径）
php artisan skill:search-doc "创建成员"

# 加分类过滤，缩小范围
php artisan skill:search-doc "回调" --category="日程"

# 搜索正文内容（较慢，标题搜索无结果时使用）
php artisan skill:search-doc "userid" --content

# 控制结果数量
php artisan skill:search-doc "消息" --limit=10
```

### 步骤3: 查看完整文档内容

从搜索结果中获取 `doc_id`，查看完整的 API 文档：

```bash
php artisan skill:search-doc --id=10018
```

### 步骤4: 提炼关键信息

从文档内容中提取用户需要的信息：
- 接口地址（HTTP 方法 + URL）
- 必填/可选参数及说明
- 请求示例和返回示例
- 权限要求和注意事项
- errcode 错误码含义

## 推理链示例

### 示例1: 查找具体接口文档

用户说: "帮我查一下企微创建日程的接口参数"
操作:
1. 关键词搜索 → `php artisan skill:search-doc "创建日程"`
2. 从结果中找到 doc_id（如 26903）
3. 查看完整文档 → `php artisan skill:search-doc --id=26903`
4. 提取接口地址、参数表、请求示例返回给用户
结果: 向用户展示创建日程接口的完整参数说明和调用示例

### 示例2: 浏览某个模块的所有接口

用户说: "通讯录管理有哪些API"
操作:
1. 查看顶级模块 → `php artisan skill:search-doc`（找到通讯录管理的 category_id=90192）
2. 浏览文档树 → `php artisan skill:search-doc --tree=90192`
3. 列出所有子模块和文档，按树结构展示给用户
结果: 展示通讯录管理下的完整接口列表（成员管理、部门管理、标签管理等）

### 示例3: 模糊查找接口

用户说: "怎么通过企微发消息"
操作:
1. 关键词搜索 → `php artisan skill:search-doc "发送消息"`
2. 结果可能较多，加分类过滤 → `php artisan skill:search-doc "发送" --category="消息"`
3. 从结果中选择最相关的文档，用 `--id` 查看完整内容
4. 提取关键信息返回给用户
结果: 展示应用消息发送接口的调用方式和参数说明

### 示例4: 查找特定字段或参数

用户说: "哪个接口返回值里有 department 字段"
操作:
1. 标题搜索无法满足，使用正文搜索 → `php artisan skill:search-doc "department" --content --limit=10`
2. 从结果中筛选最相关的文档
3. 用 `--id` 查看详细内容确认
结果: 找到包含 department 字段的相关接口文档

## 故障排除

### 搜索无结果
原因: 关键词太长或太具体
解决: 缩短关键词，如"创建企业微信会议" → "创建会议"；或尝试 `--content` 搜索正文

### 结果太多不精确
原因: 关键词太泛（如"查询""获取"）
解决: 增加 `--category` 过滤指定模块，或用 `--limit` 控制数量

### 文档内容为空
原因: 该文档未抓取（status!=1）或为分类节点（type=0）
解决: 检查 doc_id 是否正确，分类节点使用 `--tree` 浏览其子文档

## 命令参考

```
php artisan skill:search-doc                           # 显示顶级模块列表
php artisan skill:search-doc <关键词>                   # 按标题和分类路径搜索
php artisan skill:search-doc <关键词> --category=<分类>  # 加分类过滤
php artisan skill:search-doc <关键词> --content          # 同时搜索正文内容
php artisan skill:search-doc --id=<doc_id>              # 查看完整文档
php artisan skill:search-doc --tree=<category_id>       # 浏览文档树
php artisan skill:search-doc <关键词> --limit=<数量>     # 限制结果数量（默认20）
```

## 常用模块 category_id 速查

| category_id | 模块名 |
|---|---|
| 90192 | 通讯录管理 |
| 90234 | 消息接收与发送 |
| 90264 | 审批 |
| 93617 | 会议室 |
| 93623 | 日程 |
| 93625 | 会议 |
| 92108 | 客户联系 |
| 97314 | 文档 |
| 93652 | 微盘 |
| 95348 | 邮件 |

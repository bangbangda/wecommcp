---
name: wecom-schedule-assistant
description: >
  企业微信日程和日历管理助手。创建、查询、修改、取消日程，管理日历，
  支持通过姓名邀请日程参与者。
  Use when user says "帮我安排一下""记个提醒""建个日程""面试定在周三下午"
  "我今天有什么安排""查一下日历""明天几点有事""把那个日程改到下午"
  "取消明天的安排""把张三加进来""移除某人""建个日历"
  或任何涉及企业微信日程、日历管理的请求。
  不处理会议室预定、在线会议、审批、打卡、汇报等非日程功能，
  会议室和在线会议请使用 wecom-meeting-assistant。
metadata:
  author: hossy
  version: 1.0.0
  mcp-server: wecom-server
  category: schedule
  tags: [schedule, wecom]
---

# 企业微信日程助手

## 核心指令

- 执行日程相关操作前，确认用户意图
- 修改和删除操作需向用户确认后再执行
- access_token 由系统内部处理，不要暴露给用户
- 接口调用失败时，根据 errcode 查阅错误码表给出具体原因

## 工作流程

### 步骤1: 理解意图
分析用户指令，判断日程的具体操作类型。

### 步骤2: 参数校验与确认
校验必填参数，向用户确认操作内容。

### 步骤3: 执行操作
调用对应接口，解析返回结果。失败时根据 errcode 给出建议。

### 步骤4: 结果反馈
将操作结果以用户友好的方式呈现。

## 推理链示例

### 示例1: 创建日程（含参与者解析）
用户说: "帮我安排明天下午3点和张三做需求评审，大概1小时"
操作:
1. 判断意图 → 创建日程，需要解析参与者"张三"
2. 调用 search_contacts(name: "张三") → 获取 userid
3. 若返回多个候选人 → 列出候选让用户选择
4. 确认参数后调用创建日程接口(summary: "需求评审", start_time: 明天15:00, end_time: 明天16:00, attendees: [张三userid])
结果: "已创建日程「需求评审」，明天15:00-16:00，参与者：张三。已向张三发送通知。"

### 示例2: 查询日程列表
用户说: "我今天有什么安排"
操作:
1. 判断意图 → 查询日程列表，时间范围为今天
2. 调用获取日历下的日程列表接口(cal_id: 默认日历, start_time: 今天00:00, end_time: 今天23:59)
3. 解析返回的日程列表，按时间排序
结果: "今天共有3个日程：\n- 09:00-10:00 晨会\n- 14:00-15:00 需求评审\n- 16:00-17:00 代码review"

### 示例3: 取消日程
用户说: "取消明天的需求评审"
操作:
1. 判断意图 → 取消日程，需先查找匹配的日程
2. 调用获取日历下的日程列表接口(时间范围: 明天) → 在结果中匹配"需求评审"
3. 找到目标日程后，向用户确认："确认取消明天14:00的「需求评审」吗？"
4. 用户确认后调用取消日程接口(schedule_id: 目标日程ID)
结果: "已取消明天14:00的「需求评审」，已通知所有参与者。"

## 故障排除

### 错误: errcode 非 0
原因: 接口调用失败，可能是参数错误、权限不足或配额超限
解决: 根据返回的 errcode 查阅 error-codes.md，向用户说明具体原因和建议操作

### 错误: 60011 - 权限不足（no privilege to access/bindip to bindapp）
原因: 应用未配置日程接口权限，或操作的日历/日程不在应用权限范围内
解决: 提示用户联系企业管理员，在「协作」-「日程」中配置「可调用接口的应用」

### 错误: 参数校验失败（40035/40097 等）
原因: 常见原因包括 start_time >= end_time、summary 超过128字符、attendees 超过1000人、cal_id 无效
解决: 检查并修正参数后重试，向用户说明具体哪个参数不合法

### 错误: 日程配额超限
原因: 每个应用每天创建日程上限2万个，每个企业公共日历上限3万个，每个用户被共享日历上限100个
解决: 提示用户当前已达配额上限，建议清理不需要的日历/日程后再试

## API 参考文档索引

### 管理日历
- [创建日历](../../wecom-schedule-assistant/references/schedule-calendar-26902.md) - 创建日历
- [更新日历](../../wecom-schedule-assistant/references/schedule-calendar-44557.md) - 更新日历
- [获取日历详情](../../wecom-schedule-assistant/references/schedule-calendar-44558.md) - 获取日历详情
- [删除日历](../../wecom-schedule-assistant/references/schedule-calendar-44559.md) - 删除日历

### 管理日程
- [创建日程](../../wecom-schedule-assistant/references/schedule-event-26903.md) - 创建日程
- [更新重复日程](../../wecom-schedule-assistant/references/schedule-event-41941.md) - 更新重复日程
- [更新日程](../../wecom-schedule-assistant/references/schedule-event-44567.md) - 更新日程
- [新增日程参与者](../../wecom-schedule-assistant/references/schedule-event-44568.md) - 新增日程参与者
- [删除日程参与者](../../wecom-schedule-assistant/references/schedule-event-44569.md) - 删除日程参与者
- [获取日历下的日程列表](../../wecom-schedule-assistant/references/schedule-event-44570.md) - 获取日历下的日程列表
- [获取日程详情](../../wecom-schedule-assistant/references/schedule-event-44571.md) - 获取日程详情
- [取消日程](../../wecom-schedule-assistant/references/schedule-event-44573.md) - 取消日程

### 回调通知
- [回调通知](../../wecom-schedule-assistant/references/schedule-callback.md) - 概述、删除日历事件、修改日历事件、修改日程事件、删除日程事件、日程回执事件

### 通用
- [通用](../../wecom-schedule-assistant/references/schedule-general.md) - 概述
- [全局错误码](references/error-codes.md) - 企业微信 API 全局 errcode 对照表，接口调用失败时查阅

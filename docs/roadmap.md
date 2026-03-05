# 开发阶段规划

## 第一期：MVP 最小验证 ✅

- [x] Laravel 项目初始化 + MCP Server 搭建
- [x] CreateMeetingTool + SearchContactsTool（模拟数据）
- [x] ContactsService 拼音匹配逻辑
- [x] Artisan 命令行入口（`php artisan chat`）
- [x] AI 驱动抽象层（AiManager + Driver 模式）
- [x] Ollama qwen3:8b 本地测试支持
- [x] 单元测试覆盖

## 第二期：接入企微（进行中）

- [ ] 企微自建应用 + 回调接口
- [x] WecomService 对接真实 API（创建会议、取消会议、查询会议、通讯录）
- [x] 通讯录同步命令（`php artisan wecom:sync-contacts`）
- [ ] 通讯录定时同步任务
- [x] WecomApiException 统一错误处理
- [ ] 异步队列处理消息（解决 5 秒超时）
- [ ] 多轮对话上下文管理（Redis）

## 第三期：功能扩展

- [x] 取消会议（CancelMeetingTool）
- [x] 查询会议详情（GetMeetingInfoTool）
- [ ] 修改会议
- [ ] "拉上整个产品部"（部门级邀请）
- [ ] 智能排期（查冲突后建议时间）
- [ ] 会前自动发送会议资料
- [x] 用户记忆系统（SaveMemoryTool + DeleteMemoryTool，按模块分类，自动注入 prompt）
- [ ] 用户偏好统计提取（从历史数据挖掘模式，source=inferred）

## 第四期：架构升级

- [ ] MCP Server HTTP 模式部署，团队共享
- [ ] 接入 Claude Desktop / Claude Code 直接使用
- [ ] 发布为独立 MCP Server 包，社区复用

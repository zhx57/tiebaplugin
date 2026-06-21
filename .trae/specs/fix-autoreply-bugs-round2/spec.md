# 修复 weltolk_autoreply 插件剩余逻辑与前端 Bug Spec

## Why

weltolk_autoreply 自动回帖插件已完成前后端移植并通过基本编译验证，但实际运行中出现 4 个影响核心功能的 bug：

1. 定时回帖与“随机一条回复”功能失效：Go 版 cron 逻辑与 PHP 原版的随机挑选、定时触发不一致。
2. 识别不到新楼层：new_floor 模式的楼层/帖子更新检测逻辑有误，导致无法感知新回复。
3. 保存任务时百度账号错乱：前端传的是小号，入库后变成大号，存在 pid/uid 映射或前端选择绑定错误。
4. 菜单点击进入自动回贴页面 404，必须刷新才出现：前端路由/预渲染或 SPA fallback 配置问题。

这些 bug 导致插件无法“开箱即用”，必须彻底排查并完美修复。

## What Changes

- 修复 `plugins/s_weltolk_autoreply.go` 的 cron `Action()` 逻辑，使定时回帖与“随机一条回复”行为与 PHP 版 `cron/check_and_reply.php` 完全一致。
- 修复新楼层识别逻辑，确保 `new_floor` 触发模式能正确检测 `last_replied_pid`/`last_floor` 之外的新楼层。
- 修复任务保存时百度账号（pid）映射：核对前端提交值、后端 `PATCH list` 校验、数据库 `pid` 字段的写入路径，防止保存后被覆盖成大号。
- 修复前端路由/SPA fallback，使从菜单点击 `/plugin/weltolk_autoreply` 直接进入不再 404，无需刷新。
- 增加端到端验证（编译 + 启动 + API 调用 + 页面访问）。

## Impact

- Affected specs: `port-weltolk-autoreply-to-go`、`fix-autoreply-frontend-404`
- Affected code:
  - `plugins/s_weltolk_autoreply.go`（后端 cron、API、pid 映射）
  - `tbsign_go_fe/app/pages/plugin/weltolk_autoreply.vue`（前端选择器、pid 提交、路由兼容性）
  - `tbsign_go/assets/dist/`（前端构建产物）
  - 可能需要调整 `api/entry.go` 或前端 Nuxt fallback 配置
- 无破坏性变更，修复后保持现有 API 路径与数据库结构不变。

## ADDED Requirements

### Requirement: 定时回帖与随机一条回复

系统 SHALL 在 `Action()` cron 中正确实现定时回帖与随机一条回复，行为与 PHP `cron/check_and_reply.php` 一致。

#### Scenario: 多任务随机执行
- **WHEN** 某分钟内存在多个启用的任务
- **THEN** 按 `weltolk_autoreply_action_limit` 限制随机挑选任务执行（或按 PHP 原逻辑顺序+概率），而非固定顺序或只执行第一个

#### Scenario: 回复概率
- **WHEN** `reply_probability < 100`
- **THEN** 每次触发回帖前按 `reply_probability` 概率决定是否跳过；仅当命中概率时才真正发帖

### Requirement: 新楼层识别

系统 SHALL 在 `new_floor` 模式下正确识别目标帖子中尚未回复过的楼层。

#### Scenario: 有新楼层产生
- **WHEN** 帖子新增楼层且该楼层 `pid` 大于 `last_replied_pid`（或 `allow_replied=1` 时取最新楼层）
- **THEN** cron 能检测到并触发回帖

#### Scenario: 无新楼层
- **WHEN** 最新楼层 `pid` 小于等于 `last_replied_pid` 且 `allow_replied=0`
- **THEN** 任务标记为 skipped，不重复回帖

### Requirement: 百度账号保存正确

系统 SHALL 保证用户在前端选择小号百度账号保存任务后，数据库 `pid` 字段与前端选择一致，不会变成大号。

#### Scenario: 选择小号保存
- **WHEN** 用户添加/编辑任务并选择非默认的小号百度账号
- **THEN** 调用 `PATCH /plugins/weltolk_autoreply/list` 写入的 `pid` 等于小号对应的 `tc_baiduid.id`

### Requirement: 菜单点击进入页面不 404

系统 SHALL 保证用户从左侧菜单点击“自动回帖”直接进入 `/plugin/weltolk_autoreply` 时不出现 404，无需按 F5 刷新。

#### Scenario: 首次点击
- **WHEN** 用户登录后首次从菜单点击“自动回帖”
- **THEN** 页面正常渲染，URL 保持 `/plugin/weltolk_autoreply`

## MODIFIED Requirements

无（本次为 bug 修复，不修改现有正确接口）。

## REMOVED Requirements

无。

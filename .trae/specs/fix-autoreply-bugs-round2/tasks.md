# Tasks

- [x] Task 1: 复现并定位 4 个 bug
  - [x] SubTask 1.1: 阅读 PHP 原版 `cron/check_and_reply.php`，确认定时回帖、随机挑选、概率、新楼层检测的精确逻辑
  - [x] SubTask 1.2: 对照 Go 版 `plugins/s_weltolk_autoreply.go` 的 `Action()`，找出差异点
  - [x] SubTask 1.3: 阅读前端 `tbsign_go_fe/app/pages/plugin/weltolk_autoreply.vue`，确认 pid 选择器与提交字段
  - [x] SubTask 1.4: 启动本地实例，复现 404 现象并确认触发条件（菜单点击 vs 直接刷新）

- [x] Task 2: 修复后端 cron 逻辑
  - [x] SubTask 2.1: 修复高水位/任务遍历逻辑，确保每分钟按 PHP 原版规则处理任务（随机挑选 + action_limit）
  - [x] SubTask 2.2: 修复 `reply_probability` 概率判断，避免被提前跳过或永远跳过
  - [x] SubTask 2.3: 修复 `new_floor` 模式新楼层识别，核对 `last_replied_pid` 更新时机与楼层排序
  - [x] SubTask 2.4: 修复 `keyword` 模式楼层检测与 last_replied_pid 推进

- [x] Task 3: 修复任务保存时百度账号错乱
  - [x] SubTask 3.1: 检查前端表单 `pid` 绑定与 `v-model`，确保提交的是选中账号的 `tc_baiduid.id`
  - [x] SubTask 3.2: 检查后端 `PATCH list` 是否误用 uid 查询默认账号或覆盖 pid
  - [x] SubTask 3.3: 通过 API 调用验证：小号保存后数据库 pid 与提交值一致

- [x] Task 4: 修复菜单点击 404
  - [x] SubTask 4.1: 检查 Nuxt 预渲染配置，确保 `/plugin/weltolk_autoreply` 生成 `index.html` 或正确 fallback
  - [x] SubTask 4.2: 检查 Go 静态文件路由 `//go:embed all:dist/*` 与 404 fallback（`200.html`）
  - [x] SubTask 4.3: 重新 `nuxt generate`，产物拷贝到 `tbsign_go/assets/dist`，验证菜单点击进入不再 404

- [x] Task 5: 编译、构建与端到端验证
  - [x] SubTask 5.1: `go build ./...` 与 `go vet ./...` 通过
  - [x] SubTask 5.2: 前端 `nuxt generate` 成功，产物嵌入后 `go build` 通过
  - [x] SubTask 5.3: 启动二进制，验证菜单点击进入 `/plugin/weltolk_autoreply` 为 200
  - [x] SubTask 5.4: 验证添加小号任务后数据库 pid 正确
  - [x] SubTask 5.5: 验证 cron 新楼层检测与随机/概率逻辑（可通过单元测试或模拟）

# Task Dependencies

- Task 1 → Task 2 / Task 3 / Task 4（先定位后修复）
- Task 4 → Task 5.2（先修复再构建）
- Task 2 / Task 3 → Task 5.4 / Task 5.5（修复后验证）

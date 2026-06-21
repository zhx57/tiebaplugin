# Tasks

- [x] Task 1: 克隆并分析 `tbsign_go_fe` 前端仓库
  - [ ] SubTask 1.1: 克隆 `https://github.com/BANKA2017/tbsign_go_fe`，检查 Node/Yarn 环境
  - [ ] SubTask 1.2: 阅读 `app/pages/plugin/loop_ban.vue`（或其它已有插件页面），掌握 Nuxt 页面结构、API 调用封装、UI 组件库用法、认证 token 传递方式
  - [ ] SubTask 1.3: 阅读 `nuxt.config.ts` 与 `app/layouts/`、`app/composables/`，理解路由约定与全局 API helper

- [x] Task 2: 创建 `app/pages/plugin/weltolk_autoreply.vue` 页面
  - [ ] SubTask 2.1: 页面骨架：`<script setup>` + `<template>`，复用现有插件页面的布局与认证逻辑
  - [ ] SubTask 2.2: 任务列表区：`GET /plugins/weltolk_autoreply/list`，表格展示 fname/tid/trigger_mode/reply_content/status/last_floor/last_reply_time/last_status/last_error/log，显示限额
  - [ ] SubTask 2.3: 添加任务表单：fname/tid/pid（下拉选百度账号）/reply_content/trigger_mode（new_floor|keyword）/reply_target（floor|sub_post）/match_keywords/allow_replied/reply_interval/reply_probability，提交 `PATCH /plugins/weltolk_autoreply/list`
  - [ ] SubTask 2.4: 删除按钮：`DELETE /plugins/weltolk_autoreply/list/:id`；清空按钮：`POST /plugins/weltolk_autoreply/list/empty`
  - [ ] SubTask 2.5: 测试回帖区：fname/tid/pid/reply_content/trigger_mode/reply_target/match_keywords/allow_replied，提交 `POST /plugins/weltolk_autoreply/test`，展示结果
  - [ ] SubTask 2.6: 设置区：`GET /plugins/weltolk_autoreply/settings` 显示全局与个人限额，`PUT /plugins/weltolk_autoreply/settings` 修改
  - [ ] SubTask 2.7: 开关：`GET /plugins/weltolk_autoreply/switch` 读状态，`POST /plugins/weltolk_autoreply/switch` 切换

- [x] Task 3: 构建前端并嵌入 Go 二进制
  - [ ] SubTask 3.1: 设置环境变量 `NUXT_BASE_PATH=/api` `NUXT_USE_COOKIE_TOKEN=1`，执行 `yarn install && yarn run generate`
  - [ ] SubTask 3.2: 将 `.output/public/` 内容拷贝到 `tbsign_go/assets/dist/`
  - [ ] SubTask 3.3: 在 `tbsign_go` 目录执行 `go build`，确认 `assets/dist` 被正确嵌入

- [x] Task 4: 验证
  - [ ] SubTask 4.1: 启动二进制（`-fe -api`），登录后点击菜单"自动回帖"，确认不再 404
  - [ ] SubTask 4.2: 验证任务列表加载、添加、删除、清空、测试、设置、开关功能正常
  - [ ] SubTask 4.3: `go build ./...` 与 `go vet ./...` 通过

# Task Dependencies
- Task 1 → Task 2（需先了解前端结构才能编写 Vue 页面）
- Task 2 → Task 3（需先完成 Vue 页面才能构建）
- Task 3 → Task 4（需先构建并嵌入才能验证）

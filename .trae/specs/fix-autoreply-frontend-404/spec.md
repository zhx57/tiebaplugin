# 修复自动回帖插件菜单 404 Spec

## Why

`weltolk_autoreply` 插件的后端 Go 代码（`plugins/s_weltolk_autoreply.go` + `model/tc_weltolk_autoreply_tasks.gen.go`）已完整移植并通过编译，API 路由由 `api/entry.go` 自动注册到 `/plugins/weltolk_autoreply/*`。但前端 Nuxt SPA（独立仓库 `tbsign_go_fe`，编译时嵌入 Go 二进制）中不存在 `app/pages/plugin/weltolk_autoreply.vue` 页面。用户启用插件后点击菜单"自动回帖"，SPA 路由匹配失败，返回 404。

## What Changes

- 克隆 `tbsign_go_fe` 仓库，分析现有插件页面（如 `loop_ban.vue`）的代码结构与 API 调用模式
- 在 `tbsign_go_fe` 仓库的 `app/pages/plugin/` 目录下新建 `weltolk_autoreply.vue`，实现完整的插件管理界面
- 页面功能对齐 PHP 版 `weltolk_autoreply_show.php` 与 Go 后端 9 个 API 端点
- 构建前端（`yarn run generate`），将 `.output/public` 拷贝到 `tbsign_go/assets/dist`
- 重新编译 Go 二进制，使前端嵌入后开箱即用

## Impact

- Affected specs: `port-weltolk-autoreply-to-go`（补充前端部分，原 spec 明确标注"前端 vue 页面属于另一仓库，本次不涉及"）
- Affected code: `tbsign_go_fe` 仓库新增 `app/pages/plugin/weltolk_autoreply.vue`；`tbsign_go/assets/dist` 更新为包含新页面的前端构建产物

## ADDED Requirements

### Requirement: 自动回帖插件前端页面

系统 SHALL 在前端 SPA 中提供 `weltolk_autoreply` 插件的管理页面，路径为 `/plugin/weltolk_autoreply`，使用户能在浏览器中管理自动回帖任务。

#### Scenario: 用户打开自动回帖插件页面
- **WHEN** 已启用插件的用户点击菜单"自动回帖"
- **THEN** 前端路由匹配成功，显示插件管理页面（而非 404）

#### Scenario: 查看任务列表
- **WHEN** 页面加载
- **THEN** 调用 `GET /plugins/weltolk_autoreply/list`，展示当前用户的所有任务（贴吧名、帖子ID、触发模式、回复内容、状态、最后回复楼层、最后执行时间、日志），并显示任务数量上限

#### Scenario: 添加任务
- **WHEN** 用户填写表单（贴吧名、帖子ID、百度账号、回复内容、触发模式、回复目标、匹配关键词、允许重复回复、回复间隔、回复概率）并提交
- **THEN** 调用 `PATCH /plugins/weltolk_autoreply/list`，成功后刷新列表

#### Scenario: 删除任务
- **WHEN** 用户点击某任务的删除按钮
- **THEN** 调用 `DELETE /plugins/weltolk_autoreply/list/:id`，成功后刷新列表

#### Scenario: 清空任务
- **WHEN** 用户点击"清空全部"并确认
- **THEN** 调用 `POST /plugins/weltolk_autoreply/list/empty`，成功后刷新列表

#### Scenario: 测试回帖
- **WHEN** 用户填写测试表单并点击"测试"
- **THEN** 调用 `POST /plugins/weltolk_autoreply/test`，显示测试结果

#### Scenario: 查看与修改设置
- **WHEN** 用户查看设置区域
- **THEN** 调用 `GET /plugins/weltolk_autoreply/settings` 显示全局与个人限额；修改后调用 `PUT /plugins/weltolk_autoreply/settings`

#### Scenario: 开关切换
- **WHEN** 用户切换插件开关
- **THEN** 调用 `POST /plugins/weltolk_autoreply/switch`，显示当前开关状态

### Requirement: 前端构建产物嵌入

系统 SHALL 在 Go 编译时将包含 `weltolk_autoreply.vue` 页面的前端构建产物嵌入到 `assets/dist`，使 `go build` 产出的二进制开箱即用。

#### Scenario: 编译后直接使用
- **WHEN** 用户使用 `build.sh` 或手动将前端构建产物拷贝到 `assets/dist` 后执行 `go build`
- **THEN** 编译产出的二进制包含完整前端，启用 `-fe` 后所有插件页面（含自动回帖）均可正常访问

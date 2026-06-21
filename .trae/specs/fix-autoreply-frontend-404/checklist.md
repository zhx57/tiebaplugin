# Checklist

## 根因确认
- [x] 确认 404 来自前端 SPA 路由（非后端 API）：后端 `api/entry.go` 已注册 `/plugins/weltolk_autoreply/*` 全部 9 条路由，`PluginPathPrecheck` 中间件仅在校验失败时返回 404"插件不可用"（非前端 404 页面）
- [x] 确认 `PluginNameFE = "weltolk_autoreply"` 非空，前端会生成菜单项并期望 `app/pages/plugin/weltolk_autoreply.vue` 存在
- [x] 确认 `tbsign_go_fe` 仓库中不存在 `app/pages/plugin/weltolk_autoreply.vue`

## 前端页面
- [x] `app/pages/plugin/weltolk_autoreply.vue` 已创建，文件名与 `PluginNameFE` 完全一致
- [x] 页面布局与现有插件页面（如 `loop_ban.vue`）风格一致
- [x] 任务列表正确调用 `GET /plugins/weltolk_autoreply/list` 并展示所有字段
- [x] 添加任务表单包含全部字段（fname/tid/pid/reply_content/trigger_mode/reply_target/match_keywords/allow_replied/reply_interval/reply_probability），调用 `PATCH /plugins/weltolk_autoreply/list`
- [x] 删除任务调用 `DELETE /plugins/weltolk_autoreply/list/:id`
- [x] 清空任务调用 `POST /plugins/weltolk_autoreply/list/empty`
- [x] 测试回帖调用 `POST /plugins/weltolk_autoreply/test`，展示结果
- [x] 设置区调用 `GET`/`PUT /plugins/weltolk_autoreply/settings`
- [x] 开关调用 `GET`/`POST /plugins/weltolk_autoreply/switch`
- [x] API 路径前缀正确（嵌入式前端为 `/api/plugins/...`，前后分离为 `/plugins/...`，由 Nuxt 的 `NUXT_BASE_PATH` 控制）

## 构建与嵌入
- [x] `nuxt generate` 成功，`.output/public` 包含 `weltolk_autoreply` 页面（预渲染日志可见 `/plugin/weltolk_autoreply (10ms)`）
- [x] `.output/public` 内容已拷贝到 `tbsign_go/assets/dist/`（836K，含 plugin/weltolk_autoreply 目录）
- [x] `go build` 成功，二进制 34M 包含嵌入的前端

## 验证
- [x] 启动二进制（`-fe -api`），菜单"自动回帖"可正常打开（不再 404）：`curl /plugin/weltolk_autoreply` 返回 HTTP 200，HTML 含 SSR 数据 `path:"/plugin/weltolk_autoreply"`
- [x] 对比已有插件 `loop_ban` 同样返回 HTTP 200，行为一致
- [x] `go build ./...` 通过
- [x] `go vet ./...` 通过

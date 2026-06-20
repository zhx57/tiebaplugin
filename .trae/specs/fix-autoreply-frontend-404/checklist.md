# Checklist

## 根因确认
- [ ] 确认 404 来自前端 SPA 路由（非后端 API）：后端 `api/entry.go` 已注册 `/plugins/weltolk_autoreply/*` 全部 9 条路由，`PluginPathPrecheck` 中间件仅在校验失败时返回 404"插件不可用"（非前端 404 页面）
- [ ] 确认 `PluginNameFE = "weltolk_autoreply"` 非空，前端会生成菜单项并期望 `app/pages/plugin/weltolk_autoreply.vue` 存在
- [ ] 确认 `tbsign_go_fe` 仓库中不存在 `app/pages/plugin/weltolk_autoreply.vue`

## 前端页面
- [ ] `app/pages/plugin/weltolk_autoreply.vue` 已创建，文件名与 `PluginNameFE` 完全一致
- [ ] 页面布局与现有插件页面（如 `loop_ban.vue`）风格一致
- [ ] 任务列表正确调用 `GET /plugins/weltolk_autoreply/list` 并展示所有字段
- [ ] 添加任务表单包含全部字段（fname/tid/pid/reply_content/trigger_mode/reply_target/match_keywords/allow_replied/reply_interval/reply_probability），调用 `PATCH /plugins/weltolk_autoreply/list`
- [ ] 删除任务调用 `DELETE /plugins/weltolk_autoreply/list/:id`
- [ ] 清空任务调用 `POST /plugins/weltolk_autoreply/list/empty`
- [ ] 测试回帖调用 `POST /plugins/weltolk_autoreply/test`，展示结果
- [ ] 设置区调用 `GET`/`PUT /plugins/weltolk_autoreply/settings`
- [ ] 开关调用 `GET`/`POST /plugins/weltolk_autoreply/switch`
- [ ] API 路径前缀正确（嵌入式前端为 `/api/plugins/...`，前后分离为 `/plugins/...`，由 Nuxt 的 `NUXT_BASE_PATH` 控制）

## 构建与嵌入
- [ ] `yarn run generate` 成功，`.output/public` 包含 `weltolk_autoreply` 页面
- [ ] `.output/public` 内容已拷贝到 `tbsign_go/assets/dist/`
- [ ] `go build` 成功，二进制包含嵌入的前端

## 验证
- [ ] 启动二进制（`-fe -api`），登录后菜单"自动回帖"可正常打开（不再 404）
- [ ] 任务列表/添加/删除/清空/测试/设置/开关功能正常
- [ ] `go build ./...` 通过
- [ ] `go vet ./...` 通过

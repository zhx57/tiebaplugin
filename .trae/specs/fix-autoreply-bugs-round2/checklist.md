# Checklist

## 根因定位
- [x] 已复现 4 个 bug（定时回帖/随机回复失效、识别不到新楼层、pid 保存错位、菜单点击 404）
- [x] 已对照 PHP 原版 `cron/check_and_reply.php` 明确定时、概率、楼层检测逻辑
- [x] 已确认前端 `weltolk_autoreply.vue` pid 选择器绑定字段

## 后端 cron 修复
- [x] `Action()` 每分钟按 PHP 原版规则遍历/随机挑选任务，并受 `weltolk_autoreply_action_limit` 限制
- [x] `reply_probability` 概率判断位置正确（只在真正发帖前判断），不会提前跳过
- [x] `new_floor` 模式：`weltolkGetLastFloorContent` 返回楼层按时间/楼层号排序，正确比较 `last_replied_pid`
- [x] `new_floor` 命中后正确更新 `last_replied_pid`、`last_floor`、`last_reply_time`
- [x] `keyword` 模式：无新楼层时 `last_replied_pid` 不推进；命中后按规则推进
- [x] 成功/失败/vcode/跳过的日志、状态、重试计数更新正确

## pid 保存修复
- [x] 前端 `v-model` 绑定的是 `tc_baiduid.id`，提交字段名为 `pid`
- [x] 后端 `PATCH /plugins/weltolk_autoreply/list` 校验 `pid` 属于当前 uid，并原样写入数据库
- [x] 验证：选择小号保存任务后，`tc_weltolk_autoreply_tasks.pid` 等于小号 `tc_baiduid.id`

## 前端 404 修复
- [x] Nuxt `nuxt.config.ts` 中 `generate.routes` 包含 `/plugin/weltolk_autoreply`（或采用 SPA fallback）
- [x] `nuxt generate` 后 `.output/public/plugin/weltolk_autoreply/index.html` 存在
- [x] Go 静态路由对未知路径正确 fallback 到 `200.html`
- [x] 启动二进制后，从菜单点击“自动回帖”直接进入，不再 404，无需刷新

## 编译与端到端验证
- [x] `go build ./...` 通过
- [x] `go vet ./...` 通过
- [x] 前端 `nuxt generate` 成功
- [x] 产物拷贝到 `tbsign_go/assets/dist` 后 `go build` 通过
- [x] 菜单点击进入 `/plugin/weltolk_autoreply` 返回 HTTP 200
- [x] 小号任务保存后数据库 pid 正确
- [x] cron 模拟能正确识别新楼层并按概率/随机规则执行

# Checklist

## 模型
- [ ] `model/tc_weltolk_autoreply_tasks.gen.go` 存在，包名 `model`
- [ ] `TcWeltolkAutoreplyTasks` 字段与 PHP `callback_install()` 建表语句一一对应（21 个字段）
- [ ] `tid` 与 `last_replied_pid` 使用 `int64`（对应 PHP bigint）
- [ ] `TableName()` 返回 `TableNameTcWeltolkAutoreplyTasks` 常量
- [ ] GORM tag 含 `column`/`type`/`primaryKey`/`autoIncrement`/`not null`/`default`，可在 MySQL/SQLite/PG 自动建表

## 插件注册
- [ ] `plugins/s_weltolk_autoreply.go` 包名 `_plugin`
- [ ] `init()` 调用 `PluginList.Register(WeltolkAutoReplyPlugin)`
- [ ] `PluginInfo.Name = "weltolk_autoreply"`（唯一，与 PHP 插件目录名一致）
- [ ] `PluginInfo.PluginNameFE = "weltolk_autoreply"`
- [ ] `Options` 含 `weltolk_autoreply_limit`/`weltolk_autoreply_id`/`weltolk_autoreply_action_limit`
- [ ] `Endpoints` 声明 9 个路由（switch GET/POST、list GET/PATCH/DELETE/:id/empty、test POST、settings GET/PUT）

## 生命周期
- [ ] `Install()` 写 Options + UpdatePluginInfo + CreateTable
- [ ] `Delete()` 删 Options + DeletePluginInfo + DropTable + 清理 tc_users_options（weltolk_autoreply_limit、weltolk_autoreply_open）
- [ ] `Upgrade()` 返回 nil
- [ ] `RemoveAccount` 支持 `uid`/`pid` 两种类型删除
- [ ] `Report`/`Reset`/`ExportAccount`/`ImportAccount` 已实现（可为空/基础实现）

## Cron 逻辑（核心，逐项对照 PHP check_and_reply.php）
- [ ] `Action()` 含 `CheckActive()` + `defer SetActive(false)`
- [ ] 高水位 `weltolk_autoreply_high_water` 断点续传逻辑正确（空结果重置、每任务后推进、轮末重置）
- [ ] 空回复内容 → skipped
- [ ] 按 uid 查 tc_baiduid 取 pid，找不到 → error
- [ ] `GetCookie(pid, true)` 取 bduss，为空 → error
- [ ] `weltolkGetReplyCount` 失败 → error
- [ ] new_floor 模式：取最新1楼，`allow_replied==0 && latest_pid<=last_replied_pid` → skipped
- [ ] keyword 模式：取最新20楼、算 keyword_max_seen_pid、筛新楼层、无新楼层 skipped 不推进
- [ ] keyword 匹配大小写不敏感（UTF-8），楼中楼回退逻辑正确
- [ ] keyword 未匹配 → 推进 last_replied_pid=keyword_max_seen_pid（allow_replied==1 时不推进）、skipped
- [ ] 回复间隔检查（last_reply_time>0 && elapsed<interval → skipped）
- [ ] 概率检查（reply_probability<100 时 rand(1,100)>probability → skipped）
- [ ] GetTbs 失败 → error
- [ ] GetFid==0 → error
- [ ] 变量替换 {floor}/{time}/{date}/{tid}/{username} 正确（new_floor 用 reply_count，keyword 用 floor_num）
- [ ] 楼中楼回复前缀 `回复 #(reply, {portrait}, {username}) :` 正确
- [ ] 成功 → last_floor/last_replied_pid/last_reply_time/retry_count=0/last_status=ok
- [ ] vcode → 推进水位、last_status=vcode、不增重试
- [ ] 非 vcode 失败 → retry_count+1，>=3 禁用任务（enabled=0）
- [ ] last_status/last_error/last_check_time/log 同步更新，log 追加格式 `[时间] 执行结果：xxx<br>`

## 贴吧 API 与 protobuf
- [ ] `weltolkCallTiebaJSONAPI` 参数、签名（md5+`tiebaclient!!!`）、URL、header、超时与 PHP 一致
- [ ] `weltolkGetLastFloorContent` 解析 post_list/sub_post_list，username/portrait 规则与 PHP 一致
- [ ] `autoreplyBuildPostProto` 字段顺序与条件编码与 PHP 完全一致（Common field 1~70、Data field 6~67）
- [ ] `autoreplyAddPost` boundary `-*_r1999`、URL `cmd=309731`、header、超时 60s 与 PHP 一致
- [ ] `autoreplyParseResponse` 解析 Error(field1/2) 与 need_vcode(DataRes.field14.field3)

## API endpoints
- [ ] switch GET/POST 读写用户 `weltolk_autoreply_open`
- [ ] list GET 返回当前 uid 任务 + count + limit（个人>全局>5）
- [ ] list PATCH 校验 pid 归属 + 必填 + 限额后入库
- [ ] list DELETE/:id 带 uid 校验
- [ ] list/empty 清空当前 uid
- [ ] test POST 移植 show.php test 逻辑
- [ ] settings GET/PUT 全局 + 个人限额

## 编译与静态检查（开箱即用）
- [ ] `go build ./...` 通过（无编译错误）
- [ ] `go vet ./...` 通过（无警告）
- [ ] 无未使用导入/变量
- [ ] 插件名 `weltolk_autoreply` 在 PluginList 中唯一，不与现有插件冲突
- [ ] 不修改任何现有文件（纯新增 2 个文件）

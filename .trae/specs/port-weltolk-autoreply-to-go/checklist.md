# Checklist

## 模型
- [x] `model/tc_weltolk_autoreply_tasks.gen.go` 存在，包名 `model`
- [x] `TcWeltolkAutoreplyTasks` 字段与 PHP `callback_install()` 建表语句一一对应（21 个字段）
- [x] `tid` 与 `last_replied_pid` 使用 `int64`（对应 PHP bigint）
- [x] `TableName()` 返回 `TableNameTcWeltolkAutoreplyTasks` 常量
- [x] GORM tag 含 `column`/`type`/`primaryKey`/`autoIncrement`/`not null`/`default`，可在 MySQL/SQLite/PG 自动建表

## 插件注册
- [x] `plugins/s_weltolk_autoreply.go` 包名 `_plugin`
- [x] `init()` 调用 `PluginList.Register(WeltolkAutoReplyPlugin)`
- [x] `PluginInfo.Name = "weltolk_autoreply"`（唯一，与 PHP 插件目录名一致）
- [x] `PluginInfo.PluginNameFE = "weltolk_autoreply"`
- [x] `Options` 含 `weltolk_autoreply_limit`/`weltolk_autoreply_id`/`weltolk_autoreply_action_limit`
- [x] `Endpoints` 声明 9 个路由（switch GET/POST、list GET/PATCH/DELETE/:id/empty、test POST、settings GET/PUT）

## 生命周期
- [x] `Install()` 写 Options + UpdatePluginInfo + CreateTable
- [x] `Delete()` 删 Options + DeletePluginInfo + DropTable + 清理 tc_users_options（weltolk_autoreply_limit、weltolk_autoreply_open）
- [x] `Upgrade()` 返回 nil
- [x] `RemoveAccount` 支持 `uid`/`pid` 两种类型删除
- [x] `Report`/`Reset`/`ExportAccount`/`ImportAccount` 已实现（可为空/基础实现）

## Cron 逻辑（核心，逐项对照 PHP check_and_reply.php）
- [x] `Action()` 含 `CheckActive()` + `defer SetActive(false)`
- [x] 高水位 `weltolk_autoreply_high_water` 断点续传逻辑正确（空结果重置、每任务后推进、轮末重置）
- [x] 空回复内容 → skipped
- [x] 按 uid 查 tc_baiduid 取 pid，找不到 → error
- [x] `GetCookie(pid, true)` 取 bduss，为空 → error
- [x] `weltolkGetReplyCount` 失败 → error
- [x] new_floor 模式：取最新1楼，`allow_replied==0 && latest_pid<=last_replied_pid` → skipped
- [x] keyword 模式：取最新20楼、算 keyword_max_seen_pid、筛新楼层、无新楼层 skipped 不推进
- [x] keyword 匹配大小写不敏感（UTF-8），楼中楼回退逻辑正确
- [x] keyword 未匹配 → 推进 last_replied_pid=keyword_max_seen_pid（allow_replied==1 时不推进）、skipped
- [x] 回复间隔检查（last_reply_time>0 && elapsed<interval → skipped）
- [x] 概率检查（reply_probability<100 时 rand(1,100)>probability → skipped）
- [x] GetTbs 失败 → error
- [x] GetFid==0 → error
- [x] 变量替换 {floor}/{time}/{date}/{tid}/{username} 正确（new_floor 用 reply_count，keyword 用 floor_num）
- [x] 楼中楼回复前缀 `回复 #(reply, {portrait}, {username}) :` 正确
- [x] 成功 → last_floor/last_replied_pid/last_reply_time/retry_count=0/last_status=ok
- [x] vcode → 推进水位、last_status=vcode、不增重试
- [x] 非 vcode 失败 → retry_count+1，>=3 禁用任务（enabled=0）
- [x] last_status/last_error/last_check_time/log 同步更新，log 追加格式 `[时间] 执行结果：xxx<br>`

## 贴吧 API 与 protobuf
- [x] `weltolkCallTiebaJSONAPI` 参数、签名（md5+`tiebaclient!!!`）、URL、header、超时与 PHP 一致
- [x] `weltolkGetLastFloorContent` 解析 post_list/sub_post_list，username/portrait 规则与 PHP 一致
- [x] `autoreplyBuildPostProto` 字段顺序与条件编码与 PHP 完全一致（Common field 1~70、Data field 6~67）
- [x] `autoreplyAddPost` boundary `-*_r1999`、URL `cmd=309731`、header、超时 60s 与 PHP 一致
- [x] `autoreplyParseResponse` 解析 Error(field1/2) 与 need_vcode(DataRes.field14.field3)

## API endpoints
- [x] switch GET/POST 读写用户 `weltolk_autoreply_open`
- [x] list GET 返回当前 uid 任务 + count + limit（个人>全局>5）
- [x] list PATCH 校验 pid 归属 + 必填 + 限额后入库
- [x] list DELETE/:id 带 uid 校验
- [x] list/empty 清空当前 uid
- [x] test POST 移植 show.php test 逻辑
- [x] settings GET/PUT 全局 + 个人限额

## 编译与静态检查（开箱即用）
- [x] `go build ./...` 通过（无编译错误）
- [x] `go vet ./...` 通过（无警告）
- [x] 无未使用导入/变量
- [x] 插件名 `weltolk_autoreply` 在 PluginList 中唯一，不与现有插件冲突
- [x] 新增文件为纯新增 2 个；`plugins/s_loop_ban.go` 有 1 处必要兼容性修复（Go 1.25.6 `errors.As` → `errors.Is`），否则无法编译

# Tasks

- [x] Task 1: 创建数据表模型 `model/tc_weltolk_autoreply_tasks.gen.go`
  - [x] SubTask 1.1: 定义 `TableNameTcWeltolkAutoreplyTasks` 常量与 `TcWeltolkAutoreplyTasks` 结构体，包名 `model`
  - [x] SubTask 1.2: 字段与 PHP `callback_install()` 建表语句一一对应（`id` int PK autoIncrement、`uid` int、`pid` int、`fname` text、`tid` bigint/int64、`last_floor` int、`last_replied_pid` bigint/int64、`last_reply_time` int、`last_status` varchar(32)、`last_error` text、`last_check_time` int、`log` longtext/text、`reply_content` text、`reply_interval` int、`reply_probability` int、`enabled` tinyint、`retry_count` int、`trigger_mode` varchar(20)、`reply_target` varchar(20)、`allow_replied` tinyint、`match_keywords` text），GORM tag 与 json tag 完整，`TableName()` 方法返回常量
  - [x] SubTask 1.3: 索引：`uid` 普通索引、`id` 主键，便于按 uid 查询与按 id 排序

- [x] Task 2: 创建插件主文件 `plugins/s_weltolk_autoreply.go` 框架
  - [x] SubTask 2.1: `package _plugin`，导入 `_function`、`model`、`_type`、`echo`、`gorm` 等依赖
  - [x] SubTask 2.2: `init()` 注册 `WeltolkAutoReplyPlugin`；定义 `WeltolkAutoReplyType` 内嵌 `PluginInfo`；`WeltolkAutoReplyPlugin = _function.VPtr(...)` 填充 `PluginInfo`（Name/PluginNameCN/PluginNameCNShort/PluginNameFE/Version/Options/SettingOptions/Endpoints）
  - [x] SubTask 2.3: `Options` 含 `weltolk_autoreply_limit=5`、`weltolk_autoreply_id=0`、`weltolk_autoreply_action_limit=50`；`SettingOptions` 含 `weltolk_autoreply_limit`（Min=0）、`weltolk_autoreply_action_limit`（Min=0）
  - [x] SubTask 2.4: `Endpoints` 声明：`GET switch`、`POST switch`、`GET list`、`PATCH list`、`DELETE list/:id`、`POST list/empty`、`POST test`、`GET settings`、`PUT settings`

- [x] Task 3: 实现 protobuf 编解码辅助函数
  - [x] SubTask 3.1: `autoreplyGenerateDeviceIDs()` 生成 cuid（`baidutiebaapp`+UUIDv4）、cuid_galaxy2（32大写hex+|+9大写base64字母数字）、c3_aid（A00-+32大写hex+-+8大写base64字母数字）、android_id（16 hex）、sample_id（16大写base64字母数字）、z_id（空），与 PHP `autoreply_generate_device_ids` 一致
  - [x] SubTask 3.2: protobuf 原语：`pbEncodeVarint`、`pbEncodeTag`、`pbEncodeString`、`pbEncodeInt32`、`pbEncodeInt64`、`pbEncodeDouble`、`pbEncodeMessage`，行为与 `autoreply_protobuf.php` 一致
  - [x] SubTask 3.3: `autoreplyBuildPostProto(bduss, stoken, tbs, fname, fid, tid, content, showName, quoteID, replyUID, floorNum, subPostID)` 按 PHP 字段顺序与条件编码构造 AddPostReqIdl 二进制
  - [x] SubTask 3.4: `autoreplyParseResponse(binary)` 解析顶层 Error（field1/2）与 DataRes→PostAntiInfo(field14)→need_vcode(field3)；`autoreplyReadVarint`、`autoreplySkipField` 辅助

- [x] Task 4: 实现贴吧 JSON API 与楼层解析
  - [x] SubTask 4.1: `weltolkCallTiebaJSONAPI(tid int64, bduss, pn, rn, r string) (map[string]any, error)` 构造参数、ksort、md5 签名（salt `tiebaclient!!!`）、POST `http://c.tieba.baidu.com/c/f/pb/page`，header 与超时与 PHP 一致，返回解析后的 map
  - [x] SubTask 4.2: `weltolkGetReplyCount(tid int64, bduss string) (int64, bool)` 取 `thread.reply_num`
  - [x] SubTask 4.3: `weltolkGetLastFloorContent(tid int64, bduss string, limit int) []AutoReplyFloor` 解析 `post_list`，提取 id/author_id/floor/username/portrait/content/sub_posts，username 与 portrait 规则与 PHP 一致

- [x] Task 5: 实现回帖 API 调用 `autoreplyAddPost`
  - [x] SubTask 5.1: 用 boundary `-*_r1999` 构造 multipart body（`data` 字段=protobuf），POST `https://tiebac.baidu.com/c/c/post/add?cmd=309731`，header 与 PHP 一致，超时 60s
  - [x] SubTask 5.2: 调用 `autoreplyParseResponse` 解析，返回 `AutoReplyPostResult{Success, ErrorCode, ErrorMsg, NeedVcode}`；curl/HTTP 错误处理与 PHP 一致

- [x] Task 6: 实现 `Action()` cron 主逻辑（核心，逐行对齐 `check_and_reply.php`）
  - [x] SubTask 6.1: `CheckActive()`/`defer SetActive(false)`；读取 `weltolk_autoreply_high_water` 高水位，查询 `enabled=1 AND id>=high_water ORDER BY id ASC`，空则重置 high_water=0 重查
  - [x] SubTask 6.2: 单任务循环：初始化 at_username/at_portrait/quote_id/reply_uid/floor_num/sub_post_id 变量；空回复内容跳过；查 tc_baiduid 取 pid；GetCookie 取 bduss；weltolkGetReplyCount；new_floor 模式取最新1楼判断 last_replied_pid
  - [x] SubTask 6.3: keyword 模式：取最新20楼、算 keyword_max_seen_pid、筛新楼层、关键词匹配（大小写不敏感 UTF-8）、楼中楼回退、未匹配推进水位
  - [x] SubTask 6.4: 公共步骤：回复间隔检查、概率检查、GetTbs、GetFid、变量替换（{floor}/{time}/{date}/{tid}/{username}）、楼中楼前缀、autoreplyAddPost
  - [x] SubTask 6.5: 结果处理：成功更新 last_floor/last_replied_pid/last_reply_time/retry_count=0/last_status=ok；vcode 推进水位不增重试；非 vcode 增 retry_count，>=3 禁用任务；每任务后推进 high_water=task_id+1；轮末重置 high_water=0
  - [x] SubTask 6.6: 日志 log 字段追加格式与 PHP 一致（`[时间] 执行结果：xxx<br>`），last_status/last_error/last_check_time 同步更新

- [x] Task 7: 实现生命周期方法
  - [x] SubTask 7.1: `Install()`：写 Options、UpdatePluginInfo、CreateTable
  - [x] SubTask 7.2: `Delete()`：删 Options、DeletePluginInfo、DropTable、清理 tc_users_options 中 weltolk_autoreply_limit 与 weltolk_autoreply_open
  - [x] SubTask 7.3: `Upgrade()` 返回 nil；`RemoveAccount(_type, id, tx)` 按 uid 或 pid 删任务；`Report` 返回空；`Reset(uid,pid,tid)` 重置任务 retry/last_status；`ExportAccount`/`ImportAccount` 导出导入任务（参考 s_loop_ban 模式）

- [x] Task 8: 实现 API endpoints
  - [x] SubTask 8.1: `PluginAutoReplyGetSwitch`/`PluginAutoReplySwitch`：读写用户 `weltolk_autoreply_open`
  - [x] SubTask 8.2: `PluginAutoReplyGetList`：返回当前 uid 任务列表 + count + limit（个人>全局>5）
  - [x] SubTask 8.3: `PluginAutoReplyAddTask`（PATCH list）：校验 pid 归属、必填、限额，入库
  - [x] SubTask 8.4: `PluginAutoReplyDelTask`（DELETE list/:id）：带 uid 校验删除
  - [x] SubTask 8.5: `PluginAutoReplyDelAll`（POST list/empty）：清空当前 uid 任务
  - [x] SubTask 8.6: `PluginAutoReplyTest`（POST test）：移植 show.php 的 test 逻辑
  - [x] SubTask 8.7: `PluginAutoReplyGetSettings`/`PluginAutoReplySetSettings`：全局 + 个人限额

- [x] Task 9: 编译与静态检查验证
  - [x] SubTask 9.1: `cd /workspace/tbsign_go && go build ./...` 通过
  - [x] SubTask 9.2: `cd /workspace/tbsign_go && go vet ./...` 通过
  - [x] SubTask 9.3: 检查无未使用导入/变量、命名冲突；确认 `init()` 注册名 `weltolk_autoreply` 唯一

# Task Dependencies
- Task 1 → Task 2（插件引用 model）
- Task 2 → Task 3/4/5（在框架内实现辅助函数）
- Task 3/4/5 → Task 6（Action 依赖辅助函数）
- Task 2 → Task 7/8（生命周期与 endpoints 依赖框架）
- Task 1~8 → Task 9（编译验证依赖所有代码完成）

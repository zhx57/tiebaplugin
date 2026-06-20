# 移植 weltolk_autoreply 自动回帖插件为 tbsign_go 标准插件 Spec

## Why

`XwibtT.zip` 内的 PHP 版百度贴吧云签到包含一个 `weltolk_autoreply`（自动回帖）插件，由 `weltolk_autoreply.php`、`weltolk_autoreply_callback.php`、`weltolk_autoreply_desc.php`、`weltolk_autoreply_setting.php`、`weltolk_autoreply_show.php`、`cron/check_and_reply.php`、`lib/autoreply_api.php`、`lib/autoreply_protobuf.php` 等文件组成。该插件能监控指定贴吧帖子的新楼层/关键词并自动回帖。

`tbsign_go`（已克隆自 https://github.com/BANKA2017/tbsign_go.git）是同一作者的 Go 重写版签到程序，其插件系统（`plugins/`、`plugins/hooks.go`、`plugins/_s_example.go`）与 PHP 版完全不同。需要把 PHP 版自动回帖插件完整移植为 tbsign_go 的 Go 标准插件（前缀 `s_`），保证业务逻辑与 PHP 版一致，且能开箱即用（编译通过、注册成功、cron 与 API 可用）。

## What Changes

- 新增 `model/tc_weltolk_autoreply_tasks.gen.go`：定义 `tc_weltolk_autoreply_tasks` 表模型（包名 `model`），字段与 PHP 版 `callback_install()` 建表语句一一对应（`id`/`uid`/`pid`/`fname`/`tid`/`last_floor`/`last_replied_pid`/`last_reply_time`/`last_status`/`last_error`/`last_check_time`/`log`/`reply_content`/`reply_interval`/`reply_probability`/`enabled`/`retry_count`/`trigger_mode`/`reply_target`/`allow_replied`/`match_keywords`）。其中 `tid`、`last_replied_pid` 使用 `int64`（PHP 版为 `bigint`），其余整型用 `int32`。
- 新增 `plugins/s_weltolk_autoreply.go`：标准插件实现，包名 `_plugin`，包含：
  - `init()` 注册 `WeltolkAutoReplyPlugin` 到 `PluginList`
  - `PluginInfo`：`Name="weltolk_autoreply"`、`PluginNameCN="自动回帖"`、`PluginNameCNShort="自动回帖"`、`PluginNameFE="weltolk_autoreply"`、`Version="1.0"`、`Options`（`weltolk_autoreply_limit`、`weltolk_autoreply_id`、`weltolk_autoreply_action_limit`）、`SettingOptions`、`Endpoints`
  - `Action()`：每分钟执行的 cron 任务，完整移植 `cron/check_and_reply.php` 的逻辑
  - `Install()`/`Delete()`/`Upgrade()`/`RemoveAccount()`/`Report()`/`Reset()`/`ExportAccount()`/`ImportAccount()` 生命周期方法
  - 内部辅助函数：protobuf 编码（`autoreplyBuildPostProto`）、protobuf 响应解析（`autoreplyParseResponse`）、贴吧 JSON API 调用（`weltolkCallTiebaJSONAPI`/`weltolkGetReplyCount`/`weltolkGetLastFloorContent`）、回帖 API 调用（`autoreplyAddPost`）、设备 ID 生成（`autoreplyGenerateDeviceIDs`）
  - API endpoints：`switch`、`list`(GET/PATCH/DELETE/empty)、`test`、`settings`(GET/PUT 全局限额 + 个人限额)
- 不修改任何现有文件，纯新增两个文件，确保 `go build ./...` 与 `go vet ./...` 通过。

## Impact

- Affected specs: 新增 tbsign_go 标准插件 `weltolk_autoreply`
- Affected code:
  - 新增 `model/tc_weltolk_autoreply_tasks.gen.go`
  - 新增 `plugins/s_weltolk_autoreply.go`
  - 依赖现有：`plugins/hooks.go`（PluginList/PluginInfo/Plugin 接口）、`functions/globals.go`（GetCookie/GetFid/VPtr）、`functions/fetch.go`（TBFetch/AddSign/GetTbs/MultipartBodyBuilder）、`functions/options.go`（GetOption/SetOption/GetUserOption/SetUserOption）、`functions/api.go`（ApiTemplate/EchoEmptyObject）、`model/tc_baiduid.gen.go`、`model/tc_users_options.gen.go`、`types/local.go`（TypeCookie）
- 前端 vue 页面属于另一仓库 `tbsign_go_fe`，本次不涉及；后端 API 与 cron 可独立工作（"开箱即用"指 Go 后端编译即用，前端 UI 可后续在 fe 仓库补 `app/pages/plugin/weltolk_autoreply.vue`）。

## ADDED Requirements

### Requirement: 数据表模型

系统 SHALL 在 `model/tc_weltolk_autoreply_tasks.gen.go` 中定义 `TcWeltolkAutoreplyTasks` 结构体，表名 `tc_weltolk_autoreply_tasks`，字段与 PHP 版建表语句完全对应，GORM tag 正确，`TableName()` 方法返回常量 `TableNameTcWeltolkAutoreplyTasks`。

#### Scenario: 模型字段映射
- **WHEN** 插件 `Install()` 调用 `_function.GormDB.W.Migrator().CreateTable(&model.TcWeltolkAutoreplyTasks{})`
- **THEN** 在 MySQL/SQLite/PostgreSQL 上均能自动建表，字段名与 PHP 版一致（`id` 主键自增、`uid`/`pid` int、`fname` varchar/text、`tid` bigint、`last_floor` int、`last_replied_pid` bigint、`last_reply_time` int、`last_status` varchar(32)、`last_error` text、`last_check_time` int、`log` longtext/text、`reply_content` text、`reply_interval` int 默认 300、`reply_probability` int 默认 100、`enabled` tinyint 默认 1、`retry_count` int 默认 0、`trigger_mode` varchar(20) 默认 'new_floor'、`reply_target` varchar(20) 默认 'floor'、`allow_replied` tinyint 默认 0、`match_keywords` text）

### Requirement: 插件注册与生命周期

系统 SHALL 在 `plugins/s_weltolk_autoreply.go` 的 `init()` 中调用 `PluginList.Register(WeltolkAutoReplyPlugin)`，使插件被 `InitPluginList()` 与 `InitCrontab()` 自动加载并每分钟调度 `Action()`。

#### Scenario: 安装
- **WHEN** 管理员在后台切换插件开关（`PluginSwitch`，`Ver=="-1"` 时自动 `Install()`）
- **THEN** 写入 `Options`（`weltolk_autoreply_limit=5`、`weltolk_autoreply_id=0`、`weltolk_autoreply_action_limit=50`），调用 `UpdatePluginInfo`，并 `CreateTable` 建表

#### Scenario: 卸载
- **WHEN** 管理员卸载插件（`PluginUninstall` → `Delete()`）
- **THEN** 删除 `Options`、`DeletePluginInfo`、`DropTable`，并清理 `tc_users_options` 中 `weltolk_autoreply_limit`（个人限额）记录

### Requirement: Cron 自动回帖逻辑（核心，必须与 PHP 版一致）

系统 SHALL 在 `Action()` 中完整移植 `cron/check_and_reply.php` 的处理流程，逻辑不得出错。

#### Scenario: 任务遍历与断点续传
- **WHEN** `Action()` 被每分钟调度且插件已激活（`CheckActive()` 返回 true）
- **THEN** 读取 `weltolk_autoreply_high_water` option 作为高水位 `high_water`，查询 `enabled=1 AND id >= high_water` 的任务按 `id ASC`；若结果为空则重置 `high_water=0` 重新查询；处理完每个任务后推进 `high_water = task_id + 1`；一轮走完重置 `high_water=0`。`defer SetActive(false)`

#### Scenario: 单任务处理步骤（new_floor 模式）
- **WHEN** 处理一个 `trigger_mode='new_floor'` 的任务
- **THEN** 依次执行：
  1. 回复内容为空 → 记 `last_status='skipped'`、`last_error=''`、`last_check_time=now`、log 追加"跳过：回复内容为空"，跳过
  2. 按 `uid` 查 `tc_baiduid` 取 `id` 作为 `pid`；找不到 → 记 `last_status='error'`、`last_error='未找到贴吧绑定信息'`，跳过
  3. `GetCookie(pid, true)` 取 BDUSS；为空 → 记 `last_status='error'`、`last_error='未获取到BDUSS'`，跳过
  4. `weltolkGetReplyCount(tid, bduss)` 取回复数；失败或 <0 → 记 `last_status='error'`、`last_error='获取回复数失败'`，跳过
  5. `weltolkGetLastFloorContent(tid, bduss, 1)` 取最新 1 楼；`allow_replied==0 && latest_pid <= last_replied_pid` → 记 `last_status='skipped'`，跳过；获取失败 → 记 `last_status='error'`、`last_error='获取楼层内容失败'`，跳过
  6. 检查回复间隔：`last_reply_time>0 && now-last_reply_time < reply_interval` → 记 `last_status='skipped'`，跳过
  7. 检查概率：`reply_probability<100` 时 `rand(1,100) > reply_probability` → 记 `last_status='skipped'`，跳过
  8. `GetTbs(bduss)` 取 tbs；失败 → 记 `last_status='error'`、`last_error='获取TBS失败'`，跳过
  9. `GetFid(fname)` 取 fid；为 0 → 记 `last_status='error'`、`last_error='获取fid失败'`，跳过
  10. 变量替换：`{floor}`→reply_count（new_floor 模式）、`{time}`→`now` 的 `2006-01-02 15:04:05`、`{date}`→`2006-01-02`、`{tid}`→tid、`{username}`→at_username
  11. 调用 `autoreplyAddPost(...)` 执行回帖
  12. 成功 → 更新 `last_floor=reply_count`、`last_replied_pid=(allow_replied==1?last_replied_pid:quote_id)`、`last_reply_time=now`、`retry_count=0`、`last_status='ok'`
  13. 失败且 `need_vcode` → 推进 `last_replied_pid`（同成功公式）、`last_status='vcode'`、`last_error='触发验证码'`，不增加重试
  14. 失败且非 vcode → `retry_count+1`；`>=3` → `enabled=0`、`last_status='error'`、`last_error='重试次数达上限: [code] msg'`；否则 `last_status='error'`、`last_error='[错误码 code] msg'`

#### Scenario: 关键词模式（keyword）
- **WHEN** 处理 `trigger_mode='keyword'` 的任务
- **THEN** `weltolkGetLastFloorContent(tid, bduss, 20)` 取最新 20 楼；计算本轮最大主楼 pid `keyword_max_seen_pid`；筛选新楼层（`allow_replied==1` 或 `floor_id > last_replied_pid`）；无新楼层 → `last_status='skipped'` 跳过（不推进水位）；按 `\n` 拆分 `match_keywords`，对每个新楼层内容做大小写不敏感的 UTF-8 子串匹配（`strings.Contains` + `strings.ToLower` 或等价实现）；命中后：若 `reply_target='subpost'` 且该楼有 `sub_posts`，遍历楼中楼匹配同一关键词，命中则用楼中楼的 `id`/`author_id`/`username`/`portrait`，未命中则回退主楼层；变量替换时 `{floor}` 用命中楼层号；楼中楼回复前缀加 `"回复 #(reply, {at_portrait}, {at_username}) :"`；未匹配关键词 → 推进 `last_replied_pid=(allow_replied==1?last_replied_pid:keyword_max_seen_pid)`、`last_status='skipped'`，跳过；成功后 `last_replied_pid=(allow_replied==1?last_replied_pid:keyword_max_seen_pid)`

### Requirement: 贴吧 JSON API 调用

系统 SHALL 实现 `weltolkCallTiebaJSONAPI(tid int64, bduss string, pn, rn, r string)`，与 PHP `weltolk_call_tieba_json_api` 完全一致。

#### Scenario: 请求构造
- **WHEN** 调用该函数
- **THEN** 构造参数 map（`_client_type=2`、`_client_version=12.41.7.1`、`_phone_imei=000000000000000`、`back=0`、`cuid=baidutiebaapp+8位随机数`、`floor_rn=3`、`from=tieba`、`kz=tid`、`lz=0`、`mark=0`、`model=2201123C`、`pn`、`r`、`rn`、`stErrorNums=1`、`stMethod=1`、`stMode=1`、`stTimesNum=1`、`stTime=100~850随机`、`stSize=round((rand/MaxInt*8+0.4)*stTime)`、`st_type=tb_frslist`、`with_floor=1`），按 key 排序拼接 `k=v`，`sign=md5(拼接+"tiebaclient!!!")`，POST 到 `http://c.tieba.baidu.com/c/f/pb/page`，header `User-Agent: bdtb for Android 12.41.7.1`、`Cookie: ka=open; BDUSS=<urlencoded bduss>`，body 为 `k=urlencode(v)` 用 `&` 连接，超时 15s 连接 / 5s 建连，返回解析后的 JSON map

### Requirement: 楼层内容解析

系统 SHALL 实现 `weltolkGetLastFloorContent(tid int64, bduss string, limit int)` 返回楼层切片，结构与 PHP 版一致。

#### Scenario: 解析 post_list
- **WHEN** JSON API 返回 `post_list`
- **THEN** 每个元素提取：`id`、`author_id`、`floor`、`username`（无 author.name_show 时为 `"用户{author_id}"`）、`portrait`（主楼为空串）、`content`（遍历 `content` 数组取 `type==0` 的 `text` 拼接）、`sub_posts`（解析 `sub_post_list.sub_post_list`，每项含 `id`/`author_id`/`username`/`portrait`/`content`，username 优先 `author.name_show` 其次 `author.name` 否则 `"用户{author_id}"`）

### Requirement: 回帖 protobuf 编码

系统 SHALL 实现 `autoreplyBuildPostProto`，生成的二进制与 PHP `autoreply_build_post_proto` 字段、顺序、条件编码完全一致。

#### Scenario: 字段编码
- **WHEN** 构造 AddPostReqIdl
- **THEN** Common（field 1 message）按 PHP 顺序编码 field 1~70（含 cuid/cuid_galaxy2/c3_aid/android_id/sample_id 随机生成、timestamp 毫秒、event_day=`年+月+日`数字、install_time=timestamp-30天）；Data（field 1 message 内层）按 PHP 顺序编码 field 6,7,8,9,10,16,18,19,26,28/29(仅 quote_id 空),30,31,32(仅 quote_id 空),45,46(仅 quote_id 非空),47,48,49(仅 quote_id 非空),50(仅 sub_post_id 非空),51,52,53,55(主题=13/楼层=0/楼中楼=不编码),58,60,20(仅 quote_id 且 reply_uid 非空),64,67；最外层 `encodeMessage(1, data)`。varint/string/int32/int64/double/message 编码规则与 `autoreply_protobuf.php` 一致

### Requirement: 回帖 API 调用与响应解析

系统 SHALL 实现 `autoreplyAddPost`，与 PHP `autoreply_add_post` 一致。

#### Scenario: 请求
- **WHEN** 调用回帖
- **THEN** 用 boundary `-*_r1999` 构造 multipart/form-data，`data` 字段为 protobuf 二进制；POST 到 `https://tiebac.baidu.com/c/c/post/add?cmd=309731`；header `Content-Type: multipart/form-data; boundary=-*_r1999`、`User-Agent: tieba/12.35.1.0`、`x_bd_data_type: protobuf`、`Accept-Encoding: gzip`、`Connection: keep-alive`、`Cookie: BDUSS=<bduss>; STOKEN=<stoken>;`；超时 60s

#### Scenario: 响应解析
- **WHEN** 收到 protobuf 响应
- **THEN** 解析顶层 field 1（Error：field1=errorno varint、field2=errmsg string）与 field 2（DataRes→field14 PostAntiInfo→field3 need_vcode string，非 0 即 true）；返回 `success=(errorno==0)`、`error_code`、`error_msg`、`need_vcode`；curl 错误返回 `error_code=-1`，HTTP 非 2xx 返回 `error_code=http_code`

### Requirement: API 端点

系统 SHALL 暴露以下 endpoints（路径前缀 `/plugins/weltolk_autoreply/`，由 `api/entry.go` 自动挂载）：

#### Scenario: switch
- **WHEN** `GET/POST switch`
- **THEN** 读取/切换用户 `weltolk_autoreply_open` option（与 ver4_ban 一致）

#### Scenario: list
- **WHEN** `GET list`
- **THEN** 返回当前 uid 的任务列表（含 count/limit），limit 取 `weltolk_autoreply_limit`（个人 > 全局 > 5）
- **WHEN** `PATCH list`
- **THEN** 新增任务：校验 pid 归属（`tc_baiduid.id=pid AND uid=uid`）、必填字段、限额；`trigger_mode`/`reply_target`/`match_keywords`/`allow_replied`/`reply_interval`/`reply_probability`/`enabled` 入库
- **WHEN** `DELETE list/:id`
- **THEN** 删除指定 id 任务（带 uid 校验）
- **WHEN** `POST list/empty`
- **THEN** 清空当前 uid 所有任务

#### Scenario: test
- **WHEN** `POST test`
- **THEN** 移植 `weltolk_autoreply_show.php` 的 test 逻辑：按 trigger_mode 取楼层/关键词匹配，变量替换，调用 `autoreplyAddPost`，返回 success/need_vcode/error

#### Scenario: settings
- **WHEN** `GET settings`
- **THEN** 返回全局限额 `weltolk_autoreply_limit` 与当前用户个人限额
- **WHEN** `PUT settings`
- **THEN** 管理员可改全局限额；用户可改个人限额（0 表示清除个人覆盖）

### Requirement: 开箱即用与编译验证

系统 SHALL 通过 `go build ./...` 与 `go vet ./...`，无编译错误、无未使用导入、命名不与现有插件冲突。

#### Scenario: 编译
- **WHEN** 在 `tbsign_go` 根目录执行 `go build ./...`
- **THEN** 成功生成二进制，插件被 `init()` 注册

#### Scenario: vet
- **WHEN** 执行 `go vet ./...`
- **THEN** 无警告

## MODIFIED Requirements

无（纯新增插件，不修改现有功能）。

## REMOVED Requirements

无。

<?php

if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

/**
 * 定时任务：检查新回复并自动回帖
 * 频率：每60秒执行一次
 */
function cron_weltolk_autoreply()
{
    global $m;

    // 确保数据表结构完整（兼容存量表缺列）
    $table = DB_PREFIX . 'weltolk_autoreply_tasks';
    $columns = array();
    $result = $m->query('SHOW COLUMNS FROM `' . $table . '`');
    while ($row = $m->fetch_array($result)) {
        $columns[$row['Field']] = true;
    }
    $missing_fields = array(
        'pid'              => "ALTER TABLE `{$table}` ADD `pid` int NOT NULL DEFAULT 0 AFTER `uid`",
        'last_status'      => "ALTER TABLE `{$table}` ADD `last_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '' AFTER `last_reply_time`",
        'last_error'       => "ALTER TABLE `{$table}` ADD `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci AFTER `last_status`",
        'last_check_time'  => "ALTER TABLE `{$table}` ADD `last_check_time` int DEFAULT 0 AFTER `last_error`",
        'log'              => "ALTER TABLE `{$table}` ADD `log` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci AFTER `last_check_time`",
        'last_replied_pid' => "ALTER TABLE `{$table}` ADD `last_replied_pid` bigint NOT NULL DEFAULT 0 AFTER `last_floor`",
        'trigger_mode'     => "ALTER TABLE `{$table}` ADD `trigger_mode` varchar(20) NOT NULL DEFAULT 'new_floor'",
        'reply_target'     => "ALTER TABLE `{$table}` ADD `reply_target` varchar(20) NOT NULL DEFAULT 'floor' AFTER `trigger_mode`",
        'allow_replied'     => "ALTER TABLE `{$table}` ADD `allow_replied` tinyint(1) NOT NULL DEFAULT 0 AFTER `reply_target`",
        'match_keywords'   => "ALTER TABLE `{$table}` ADD `match_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci",
    );
    foreach ($missing_fields as $field => $sql) {
        if (!isset($columns[$field])) {
            $m->query($sql);
        }
    }

    require_once __DIR__ . '/../lib/autoreply_api.php';

    $now = time();
    $log_time = date('Y-m-d H:i:s', $now);

    // Step 0: 断点续传 —— 从上次处理的位置继续，避免 PHP 超时导致星饿
    $high_water = (int)option::get('weltolk_autoreply_high_water');
    $tasks = $m->query(
        "SELECT * FROM `" . DB_PREFIX . "weltolk_autoreply_tasks`
         WHERE `enabled` = 1 AND `id` >= {$high_water}
         ORDER BY `id` ASC"
    );

    // 如果没查到任何任务，重置高水位从头开始
    if ($m->num_rows($tasks) == 0) {
        option::set('weltolk_autoreply_high_water', 0);
        $tasks = $m->query(
            "SELECT * FROM `" . DB_PREFIX . "weltolk_autoreply_tasks`
             WHERE `enabled` = 1
             ORDER BY `id` ASC"
        );
    }

    $total = 0;
    $replied = 0;
    $skipped = 0;
    $failed = 0;

    while ($task = $m->fetch_array($tasks)) {
        // 初始化本轮变量（避免未定义变量错误）
        $at_username = '';
        $at_portrait = '';
        $quote_id = '';
        $reply_uid = '';
        $floor_num = '';
        $sub_post_id = '';
        $total++;
        $task_id       = (int)$task['id'];
        $uid           = (int)$task['uid'];
        $fname         = $task['fname'];
        $tid           = (int)$task['tid'];
        $last_floor    = (int)$task['last_floor'];
        $last_reply_time = (int)$task['last_reply_time'];
        $reply_content = $task['reply_content'];
        $reply_interval  = (int)$task['reply_interval'];
        $reply_probability = (int)$task['reply_probability'];
        $retry_count   = (int)$task['retry_count'];
        $last_replied_pid = (int)$task['last_replied_pid'];
        $trigger_mode   = isset($task['trigger_mode']) ? $task['trigger_mode'] : 'new_floor';
        $match_keywords = isset($task['match_keywords']) ? $task['match_keywords'] : '';
        $reply_target  = isset($task['reply_target']) ? $task['reply_target'] : 'floor';
        $allow_replied  = isset($task['allow_replied']) ? (int)$task['allow_replied'] : 0;

        echo "[任务 #{$task_id}] [贴吧:{$fname}] [帖子:{$tid}] 开始处理...\n";

        // 如果没有回复内容，跳过
        if (empty($reply_content)) {
            echo "[任务 #{$task_id}] 回复内容为空，跳过\n";
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `last_status` = 'skipped',
                `last_error` = '',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：回复内容为空<br>')
                WHERE `id` = {$task_id}");
            $skipped++;
            continue;
        }

        // Step 1: 获取用户 BDUSS / STOKEN
        $bind = $m->fetch_array($m->query(
            "SELECT `id` FROM `" . DB_NAME . "`.`" . DB_PREFIX . "baiduid` WHERE `uid` = {$uid} LIMIT 1"
        ));
        if (empty($bind['id'])) {
            echo "[任务 #{$task_id}] [Step1] 未找到 uid={$uid} 的贴吧绑定信息，跳过\n";
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `last_status` = 'error',
                `last_error` = '未找到贴吧绑定信息',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：未找到贴吧绑定信息<br>')
                WHERE `id` = {$task_id}");
            $skipped++;
            continue;
        }
        $pid = $bind['id'];

        $cookie = misc::getCookie($pid, true);
        if (empty($cookie['bduss'])) {
            echo "[任务 #{$task_id}] [Step1] 未获取到 pid={$pid} 的 BDUSS，跳过\n";
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `pid` = {$pid},
                `last_status` = 'error',
                `last_error` = '未获取到BDUSS',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：未获取到BDUSS<br>')
                WHERE `id` = {$task_id}");
            $skipped++;
            continue;
        }
        $bduss  = $cookie['bduss'];
        $stoken = isset($cookie['stoken']) ? $cookie['stoken'] : '';

        echo "[任务 #{$task_id}] [Step1] 获取 BDUSS 成功, pid={$pid}\n";

        // Step 2: 获取帖子最新回复数（带超时保护）
        echo "[任务 #{$task_id}] [Step2] 获取帖子回复数...\n";
        $reply_count = weltolk_get_reply_count($tid, $bduss);
        if ($reply_count === false || $reply_count < 0) {
            echo "[任务 #{$task_id}] [Step2] 获取帖子回复数失败，跳过\n";
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `pid` = {$pid},
                `last_status` = 'error',
                `last_error` = '获取回复数失败',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：获取回复数失败<br>')
                WHERE `id` = {$task_id}");
            $skipped++;
            continue;
        }

        echo "[任务 #{$task_id}] [Step2] 当前回复数: {$reply_count}, 上次记录: {$last_floor}\n";

        // Step 3: 检查是否有新回复
        // 关键词模式使用 last_replied_pid 水位判断，不依赖 reply_count
        if ($trigger_mode !== 'keyword') {
            // 新楼层模式：使用 last_replied_pid 判断是否有新楼层
            echo "[任务 #{$task_id}] [Step3] 新楼层模式，检查最新楼层...\n";
            $latest_floors = weltolk_get_last_floor_content($tid, $bduss, 1);
            $latest_pid = 0;
            if (!empty($latest_floors)) {
                $latest_pid = isset($latest_floors[0]['id']) ? (int)$latest_floors[0]['id'] : 0;
            }
            if ($allow_replied == 0 && $latest_pid <= $last_replied_pid) {
                echo "[任务 #{$task_id}] [Step3] 没有新楼层（latest_pid={$latest_pid} <= last_replied_pid={$last_replied_pid}），跳过\n";
                $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                    `pid` = {$pid},
                    `last_status` = 'skipped',
                    `last_error` = '',
                    `last_check_time` = {$now},
                    `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：没有新楼层<br>')
                    WHERE `id` = {$task_id}");
                $skipped++;
                continue;
            }
            // allow_replied=1 或有新楼层时继续，但需确保获取到了楼层数据
            if (empty($latest_floors)) {
                echo "[任务 #{$task_id}] [Step3] 获取最新楼层内容失败，跳过\n";
                $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                    `pid` = {$pid},
                    `last_status` = 'error',
                    `last_error` = '获取楼层内容失败',
                    `last_check_time` = {$now},
                    `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：获取楼层内容失败<br>')
                    WHERE `id` = {$task_id}");
                $skipped++;
                continue;
            }
            // 有新楼层，使用已获取的数据
            $latest = $latest_floors[0];
            $quote_id = isset($latest['id']) ? (string)$latest['id'] : '';
            $reply_uid = isset($latest['author_id']) ? (string)$latest['author_id'] : '';
            $floor_num = isset($latest['floor']) ? (string)$latest['floor'] : '';
            $at_username = isset($latest['username']) ? $latest['username'] : '';
            $at_portrait = isset($latest['portrait']) ? $latest['portrait'] : '';
            $sub_post_id = '';
            echo "[任务 #{$task_id}] [Step3] 新楼层: pid={$quote_id}, floor={$floor_num}, user={$at_username}\n";
        }

        // === 关键词匹配模式 ===
        $keyword_max_seen_pid = 0; // 关键词模式本轮扫描到的最大主楼 pid
        if ($trigger_mode === 'keyword') {
            // 关键词模式重置楼层上下文（新楼层模式已在上面设置）
            $at_username = ''; // 用于 @楼层用户
            $at_portrait = ''; // 楼层用户 portrait
            $quote_id = '';    // 被回复楼层的 post_id
            $reply_uid = '';   // 被回复用户的 ID
            $floor_num = '';   // 被回复的楼层号
            $sub_post_id = ''; // 楼中楼 post_id（命中楼中楼时设置）
            // TiebaLite 逻辑：r=1 倒序获取最新20楼，通过 last_replied_pid 判断新楼层
            echo "[任务 #{$task_id}] [Step3b] 关键词匹配模式（TiebaLite倒序），抓取最新20层...\n";
            $floors = weltolk_get_last_floor_content($tid, $bduss, 20);

            if (empty($floors)) {
                echo "[任务 #{$task_id}] [Step3b] 获取楼层内容失败，跳过\n";
                $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                    `pid` = {$pid},
                    `last_status` = 'skipped',
                    `last_error` = '获取楼层内容失败',
                    `last_check_time` = {$now},
                    `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：获取楼层内容失败<br>')
                    WHERE `id` = {$task_id}");
                $skipped++;
                continue;
            }

            // 计算本轮扫描到的最大主楼 pid（用于水位推进）
            foreach ($floors as $floor) {
                $floor_id = isset($floor['id']) ? (int)$floor['id'] : 0;
                if ($floor_id > $keyword_max_seen_pid) {
                    $keyword_max_seen_pid = $floor_id;
                }
            }

            // 筛选新楼层：id > last_replied_pid（allow_replied=1 时遍历所有楼层）
            $new_floors = array();
            echo "[任务 #{$task_id}] [Step3b] last_replied_pid={$last_replied_pid}，筛选新楼层...\n";
            foreach ($floors as $floor) {
                $floor_id = isset($floor['id']) ? (int)$floor['id'] : 0;
                if ($allow_replied == 1 || $floor_id > $last_replied_pid) {
                    $new_floors[] = $floor;
                }
            }

            if (empty($new_floors)) {
                // 无新楼层：跳过，不推进水位（最大 pid 不超过 last_replied_pid）
                echo "[任务 #{$task_id}] [Step3b] 无新楼层，跳过\n";
                $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                    `pid` = {$pid},
                    `last_status` = 'skipped',
                    `last_error` = '',
                    `last_check_time` = {$now},
                    `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：无新楼层<br>')
                    WHERE `id` = {$task_id}");
                $skipped++;
                continue;
            }

            echo "[任务 #{$task_id}] [Step3b] 发现 " . count($new_floors) . " 个新楼层，开始关键词匹配...\n";

            // 检查关键词匹配 —— 只遍历新楼层
            $matched = false;
            $matched_keyword = '';
            if (!empty(trim($match_keywords))) {
                $keywords = explode("\n", $match_keywords);

                foreach ($new_floors as $floor) {
                    $floor_content = isset($floor['content']) ? $floor['content'] : '';
                    if (empty($floor_content)) {
                        continue;
                    }
                    echo "[任务 #{$task_id}] [Step3b] 检查楼层 #{$floor['floor']} 用户: {$floor['username']}, 内容(前50字): " . mb_substr($floor_content, 0, 50, 'UTF-8') . "\n";

                    foreach ($keywords as $kw) {
                        $kw = trim($kw);
                        if (!empty($kw) && mb_stripos($floor_content, $kw, 0, 'UTF-8') !== false) {
                            $matched = true;
                            $matched_keyword = $kw;
                            $quote_id = isset($floor['id']) ? (string)$floor['id'] : '';
                            $floor_num = isset($floor['floor']) ? (string)$floor['floor'] : '';

                            // 楼中楼回复模式：遍历楼中楼进行关键词匹配
                            if ($reply_target === 'subpost' && !empty($floor['sub_posts'])) {
                                $sub_matched = false;
                                foreach ($floor['sub_posts'] as $sp) {
                                    $sp_content = isset($sp['content']) ? $sp['content'] : '';
                                    if (!empty($sp_content) && mb_stripos($sp_content, $kw, 0, 'UTF-8') !== false) {
                                        // 楼中楼关键词命中
                                        // 注意：floor_num 保持为主楼层号（用于 protobuf field 48），不使用楼中楼内部编号
                                        $sub_post_id = isset($sp['id']) ? (string)$sp['id'] : '';
                                        $reply_uid = isset($sp['author_id']) ? (string)$sp['author_id'] : '';
                                        $at_username = isset($sp['username']) ? $sp['username'] : '';
                                        $at_portrait = isset($sp['portrait']) ? $sp['portrait'] : '';
                                        $sub_matched = true;
                                        echo "[任务 #{$task_id}] [Step3b] 楼中楼关键词命中: sub_post_id={$sub_post_id}, user={$at_username}\n";
                                        break;
                                    }
                                }
                                if (!$sub_matched) {
                                    // 楼中楼未命中，回退到主楼层回复
                                    echo "[任务 #{$task_id}] [Step3b] 楼中楼未匹配关键词，回退主楼层回复\n";
                                    $sub_post_id = '';
                                    $at_username = $floor['username'];
                                    $at_portrait = isset($floor['portrait']) ? $floor['portrait'] : '';
                                    $reply_uid = isset($floor['author_id']) ? (string)$floor['author_id'] : '';
                                }
                            } else {
                                // 主楼层回复模式
                                $sub_post_id = '';
                                $at_username = $floor['username'];
                                $at_portrait = isset($floor['portrait']) ? $floor['portrait'] : '';
                                $reply_uid = isset($floor['author_id']) ? (string)$floor['author_id'] : '';
                            }
                            break 2;
                        }
                    }
                }
            }

            if (!$matched) {
                // 有新楼但关键词未匹配：跳过，但推进水位避免下次重复扫描
                echo "[任务 #{$task_id}] [Step3b] 关键词未匹配，跳过（水位推进至 {$keyword_max_seen_pid}）\n";
                $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                    `pid` = {$pid},
                    `last_replied_pid` = " . ($allow_replied == 1 ? $last_replied_pid : $keyword_max_seen_pid) . ",
                    `last_status` = 'skipped',
                    `last_error` = '',
                    `last_check_time` = {$now},
                    `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：关键词未匹配（水位推进至{$keyword_max_seen_pid}）<br>')
                    WHERE `id` = {$task_id}");
                $skipped++;
                continue;
            }

            echo "[任务 #{$task_id}] [Step3b] 关键词匹配成功: \"{$matched_keyword}\" @楼层 #{$floor_num} 用户: {$at_username}\n";
        }
        // === 关键词匹配模式结束 ===

        // Step 4: 检查回复间隔
        $elapsed = $now - $last_reply_time;
        if ($last_reply_time > 0 && $elapsed < $reply_interval) {
            $remaining = $reply_interval - $elapsed;
            echo "[任务 #{$task_id}] [Step4] 回复间隔未到 (需等待 {$remaining} 秒)，跳过\n";
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `pid` = {$pid},
                `last_status` = 'skipped',
                `last_error` = '',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：回复间隔未到（需等待 {$remaining} 秒）<br>')
                WHERE `id` = {$task_id}");
            $skipped++;
            continue;
        }

        // Step 5: 检查回复概率
        if ($reply_probability < 100) {
            $rand = random_int(1, 100);
            if ($rand > $reply_probability) {
                echo "[任务 #{$task_id}] [Step5] 概率未命中 ({$rand} > {$reply_probability})，跳过\n";
                $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                    `pid` = {$pid},
                    `last_status` = 'skipped',
                    `last_error` = '',
                    `last_check_time` = {$now},
                    `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：概率未命中<br>')
                    WHERE `id` = {$task_id}");
                $skipped++;
                continue;
            }
        }

        // Step 6: 获取 tbs
        echo "[任务 #{$task_id}] [Step6] 获取 TBS...\n";
        $tbs = misc::getTbs($uid, $bduss);
        if (empty($tbs)) {
            echo "[任务 #{$task_id}] [Step6] 获取 TBS 失败，跳过\n";
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `pid` = {$pid},
                `last_status` = 'error',
                `last_error` = '获取TBS失败',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：获取TBS失败<br>')
                WHERE `id` = {$task_id}");
            $skipped++;
            continue;
        }

        // Step 7: 获取 fid
        echo "[任务 #{$task_id}] [Step7] 获取 fid...\n";
        $fid = misc::getFid($fname);
        if (empty($fid)) {
            echo "[任务 #{$task_id}] [Step7] 获取贴吧 fid 失败，跳过\n";
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `pid` = {$pid},
                `last_status` = 'error',
                `last_error` = '获取fid失败',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：获取fid失败<br>')
                WHERE `id` = {$task_id}");
            $skipped++;
            continue;
        }

        // Step 8: 变量替换
        // {floor} 替换：关键词模式使用命中楼层号，新楼层模式使用 reply_count
        $floor_for_replace = ($trigger_mode === 'keyword' && !empty($floor_num)) ? $floor_num : (string)$reply_count;
        $final_content = str_replace(
            array('{floor}', '{time}', '{date}', '{tid}', '{username}'),
            array($floor_for_replace, date('Y-m-d H:i:s', $now), date('Y-m-d', $now), (string)$tid, $at_username),
            $reply_content
        );
        echo "[任务 #{$task_id}] [Step8] 回帖内容替换完成: " . substr($final_content, 0, 50) . "...\n";

// 楼中楼回复模式：添加内容前缀（对齐 TiebaLite）
if ($trigger_mode === 'keyword' && $reply_target === 'subpost' && !empty($sub_post_id) && !empty($at_username)) {
    $final_content = "回复 #(reply, {$at_portrait}, {$at_username}) :{$final_content}";
    echo "[任务 #{$task_id}] [Step8] 楼中楼回复前缀已添加\n";
}

        // Step 9: 执行回帖
        $show_name = '贴吧用户';
        echo "[任务 #{$task_id}] [Step9] 开始回帖...\n";

        $result = autoreply_add_post($bduss, $stoken, $tbs, $fname, $fid, $tid, $final_content, $show_name, $quote_id, $reply_uid, $floor_num, $sub_post_id);

        if ($result['success']) {
            echo "[任务 #{$task_id}] [Step9] 回帖成功!\n";
            // 关键词模式：推进水位到本轮最大 pid
            $new_last_replied_pid = ($allow_replied == 1) ? $last_replied_pid : (($trigger_mode === 'keyword') ? $keyword_max_seen_pid : (int)$quote_id);
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `pid` = {$pid},
                `last_floor` = {$reply_count},
                `last_replied_pid` = {$new_last_replied_pid},
                `last_reply_time` = {$now},
                `retry_count` = 0,
                `last_status` = 'ok',
                `last_error` = '',
                `last_check_time` = {$now},
                `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：操作成功<br>')
                WHERE `id` = {$task_id}");
            $replied++;
        } else {
            echo "[任务 #{$task_id}] [Step9] 回帖失败: [错误码 {$result['error_code']}] {$result['error_msg']}\n";

            if ($result['need_vcode']) {
                // 需要验证码 —— 非代码错误，不增加重试计数
                echo "[任务 #{$task_id}] [Step9] 触发验证码，不增加重试次数\n";
                // 推进水位避免下次 cron 重复回复同一楼层
                $vcode_new_pid = ($allow_replied == 1) ? $last_replied_pid : (($trigger_mode === 'keyword') ? $keyword_max_seen_pid : (int)$quote_id);
                $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                    `pid` = {$pid},
                    `last_floor` = {$reply_count},
                    `last_replied_pid` = {$vcode_new_pid},
                    `last_status` = 'vcode',
                    `last_error` = '触发验证码',
                    `last_check_time` = {$now},
                    `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：跳过：触发验证码<br>')
                    WHERE `id` = {$task_id}");
            } else {
                // 非验证码错误，增加重试计数
                $new_retry = $retry_count + 1;
                if ($new_retry >= 3) {
                    echo "[任务 #{$task_id}] [Step9] 重试次数已达上限 ({$new_retry}/3)，禁用任务\n";
                    $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                        `pid` = {$pid},
                        `last_floor` = {$reply_count},
                        `retry_count` = {$new_retry},
                        `enabled` = 0,
                        `last_status` = 'error',
                        `last_error` = '" . $m->escape_string('重试次数达上限: ['. (int)$result['error_code'] .'] '.$result['error_msg']) . "',
                        `last_check_time` = {$now},
                        `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：失败：重试次数达上限，任务已禁用#[" . (int)$result['error_code'] . "] " . $result['error_msg'] . "<br>')
                        WHERE `id` = {$task_id}");
                } else {
                    echo "[任务 #{$task_id}] [Step9] 重试次数: {$new_retry}/3\n";
                    $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                        `pid` = {$pid},
                        `last_floor` = {$reply_count},
                        `retry_count` = {$new_retry},
                        `last_status` = 'error',
                        `last_error` = '" . $m->escape_string('[错误码 '. (int)$result['error_code'].'] '.$result['error_msg']) . "',
                        `last_check_time` = {$now},
                        `log` = CONCAT(COALESCE(`log`, ''), '[{$log_time}] 执行结果：操作失败#[" . (int)$result['error_code'] . "] " . $result['error_msg'] . "<br>')
                        WHERE `id` = {$task_id}");
                }
            }
            $failed++;
        }

        // 推进高水位到下一个任务（断点续传：PHP 超时后下一轮从当前任务重新开始）
        option::set('weltolk_autoreply_high_water', $task_id + 1);
    }

    echo "\n--- 本轮处理完毕 ---\n";
    echo "总任务: {$total}, 回帖成功: {$replied}, 跳过: {$skipped}, 失败: {$failed}\n";

    // 一轮走完，重置高水位
    option::set('weltolk_autoreply_high_water', 0);
}

/**
 * 调用 c.tieba.baidu.com JSON API 获取帖子数据（统一入口）
 *
 * @param int    $tid   帖子 ID
 * @param string $bduss BDUSS cookie
 * @param string $pn    页码（默认 1）
 * @param string $rn    每页条数（默认 1）
 * @param string $r     排序：0=倒序（最新在前）1=正序（最早在前）
 * @return array|null   API 返回的 JSON 数组，失败返回 null
 */
function weltolk_call_tieba_json_api($tid, $bduss, $pn = '1', $rn = '1', $r = '0')
{
    $secret = 'tiebaclient!!!';
    $st_time = random_int(100, 850);
    $st_size = round((random_int(0, PHP_INT_MAX) / PHP_INT_MAX * 8 + 0.4) * $st_time);
    $cuid = 'baidutiebaapp' . random_int(10000000, 99999999);

    $params = array(
        '_client_type'    => '2',
        '_client_version' => '12.41.7.1',
        '_phone_imei'     => '000000000000000',
        'back'            => '0',
        'cuid'            => $cuid,
        'floor_rn'        => '3',
        'from'            => 'tieba',
        'kz'              => (string)$tid,
        'lz'              => '0',
        'mark'            => '0',
        'model'           => '2201123C',
        'pn'              => $pn,
        'r'               => $r,
        'rn'              => $rn,
        'stErrorNums'     => '1',
        'stMethod'        => '1',
        'stMode'          => '1',
        'stTimesNum'      => '1',
        'stTime'          => (string)$st_time,
        'stSize'          => (string)$st_size,
        'st_type'         => 'tb_frslist',
        'with_floor'      => '1',
    );

    // MD5 签名: 按 key 排序 → 拼接 key=value → MD5(拼接 + tiebaclient!!!)
    ksort($params);
    $raw = '';
    foreach ($params as $k => $v) {
        $raw .= $k . '=' . $v;
    }
    $params['sign'] = md5($raw . $secret);

    // 构造 POST body
    $body = '';
    foreach ($params as $k => $v) {
        if ($body !== '') {
            $body .= '&';
        }
        $body .= $k . '=' . urlencode($v);
    }

    $ch = new wcurl('http://c.tieba.baidu.com/c/f/pb/page', array(
        'User-Agent: bdtb for Android 12.41.7.1',
        'Cookie: ka=open; BDUSS=' . urlencode($bduss),
    ));
    $ch->setTimeOut(15000);
    $ch->set(CURLOPT_CONNECTTIMEOUT_MS, 5000);
    $ch->set(CURLOPT_POST, 1);
    $ch->set(CURLOPT_POSTFIELDS, $body);

    $resp = $ch->exec();
    $ch->close();

    if (empty($resp)) {
        return null;
    }

    $json = json_decode($resp, true);
    if (!$json) {
        return null;
    }

    return $json;
}

/**
 * 从贴吧 JSON API 获取帖子回复总数
 *
 * @param int    $tid   帖子 ID
 * @param string $bduss BDUSS cookie
 * @return int|false 回复数，失败返回 false
 */
function weltolk_get_reply_count($tid, $bduss)
{
    $json = weltolk_call_tieba_json_api($tid, $bduss, '1', '1', '0');

    if (!$json) {
        echo "[GetReplyCount] API 请求失败，tid={$tid}\n";
        return false;
    }

    if (isset($json['thread']['reply_num'])) {
        return (int)$json['thread']['reply_num'];
    }

    echo "[GetReplyCount] 响应中无 reply_num，tid={$tid}\n";
    return false;
}

/**
 * 从贴吧 JSON API 获取最新楼层的用户名和内容（用于关键词匹配）
 *
 * @param int    $tid   帖子 ID
 * @param string $bduss BDUSS cookie
 * @param int    $limit 获取楼层数（默认 1）
 * @return array 返回楼层数据数组，每个元素包含 id, author_id, floor, username, content
 */
function weltolk_get_last_floor_content($tid, $bduss, $limit = 1)
{
    // r=0 倒序获取最新楼层
    $json = weltolk_call_tieba_json_api($tid, $bduss, '1', (string)$limit, '0');

    if (!$json) {
        return array();
    }

    $post_list = isset($json['post_list']) ? $json['post_list'] : array();
    if (empty($post_list)) {
        return array();
    }

    $result = array();

    foreach ($post_list as $post) {
        // 提取用户名（JSON API 不再返回 author.name_show，仅返回 author_id）
        $author_id = isset($post['author_id']) ? $post['author_id'] : null;
        $username = $author_id ? "用户{$author_id}" : '';

        // 提取纯文本内容
        $content = '';
        if (isset($post['content']) && is_array($post['content'])) {
            foreach ($post['content'] as $c) {
                if (isset($c['type']) && $c['type'] == 0 && isset($c['text'])) {
                    $content .= $c['text'];
                }
            }
        }

        // 解析楼中楼数据
        $sub_posts = array();
        if (isset($post['sub_post_list']) && is_array($post['sub_post_list'])) {
            $sub_list_data = isset($post['sub_post_list']['sub_post_list']) ? $post['sub_post_list']['sub_post_list'] : array();
            if (is_array($sub_list_data)) {
                foreach ($sub_list_data as $sp) {
                    $sp_author_id = isset($sp['author_id']) ? $sp['author_id'] : null;
                    $sp_username = $sp_author_id ? "用户{$sp_author_id}" : '';
                    $sp_portrait = '';
                    // 尝试从 author 对象获取 portrait 和 username
                    if (isset($sp['author']) && is_array($sp['author'])) {
                        if (isset($sp['author']['portrait'])) {
                            $sp_portrait = $sp['author']['portrait'];
                        }
                        if (isset($sp['author']['name_show']) && $sp['author']['name_show']) {
                            $sp_username = $sp['author']['name_show'];
                        } elseif (isset($sp['author']['name']) && $sp['author']['name']) {
                            $sp_username = $sp['author']['name'];
                        }
                    }
                    // 提取楼中楼纯文本内容
                    $sp_content = '';
                    if (isset($sp['content']) && is_array($sp['content'])) {
                        foreach ($sp['content'] as $c) {
                            if (isset($c['type']) && $c['type'] == 0 && isset($c['text'])) {
                                $sp_content .= $c['text'];
                            }
                        }
                    }
                    $sub_posts[] = array(
                        'id'        => isset($sp['id']) ? $sp['id'] : null,
                        'author_id' => $sp_author_id,
                        'username'  => $sp_username,
                        'portrait'  => $sp_portrait,
                        'content'   => $sp_content,
                    );
                }
            }
        }

        $result[] = array(
            'id'        => isset($post['id']) ? $post['id'] : null,
            'author_id' => $author_id,
            'floor'     => isset($post['floor']) ? $post['floor'] : null,
            'username'  => $username,
            'portrait'  => '',
            'content'   => $content,
            'sub_posts' => $sub_posts,
        );
    }

    return $result;
}

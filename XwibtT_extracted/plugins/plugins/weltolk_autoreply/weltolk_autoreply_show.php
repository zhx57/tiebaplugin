<?php if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); }
global $m;
$uid = UID;

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
    'match_keywords'   => "ALTER TABLE `{$table}` ADD `match_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci",
    'reply_target'     => "ALTER TABLE `{$table}` ADD `reply_target` varchar(20) NOT NULL DEFAULT 'floor'",
    'allow_replied'    => "ALTER TABLE `{$table}` ADD `allow_replied` tinyint(1) NOT NULL DEFAULT 0 AFTER `reply_target`",
);
foreach ($missing_fields as $field => $sql) {
    if (!isset($columns[$field])) {
        $m->query($sql);
    }
}

// 获取用户的任务数量上限（个人覆盖 > 全局默认 > 5）
function autoreply_get_user_limit($uid) {
    $personal = intval(option::uget('weltolk_autoreply_limit', $uid));
    if ($personal > 0) return $personal;
    $global = intval(option::get('weltolk_autoreply_limit'));
    return $global > 0 ? $global : 5;
}

// 初始化页面消息
$page_msg = '';

// 获取用户绑定的百度账号列表
$baidu_accounts = [];
$baidu_result = $m->query("SELECT `id`,`name` FROM `".DB_NAME."`.`".DB_PREFIX."baiduid` WHERE `uid`={$uid}");
while ($r = $m->fetch_array($baidu_result)) {
    $baidu_accounts[] = $r;
}

// 删除逻辑（带 uid 校验）
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $m->query("DELETE FROM `".DB_PREFIX."weltolk_autoreply_tasks` WHERE `id` = {$delete_id} AND `uid` = {$uid}");
    redirect('index.php?plugin=weltolk_autoreply&msg='.urlencode('删除成功'));
}

// 引入 cron 函数（获取楼层内容用）
require_once __DIR__ . '/cron/check_and_reply.php';

// 测试发帖
if (isset($_GET['test'])) {
    $fname         = isset($_POST['fname']) ? $m->escape_string($_POST['fname']) : '';
    $tid           = isset($_POST['tid']) ? intval($_POST['tid']) : 0;
    $reply_content = isset($_POST['reply_content']) ? $m->escape_string($_POST['reply_content']) : '';
    $pid           = isset($_POST['pid']) ? intval($_POST['pid']) : 0;

    // 读取用户设置：优先从数据库（编辑模式），其次从 POST（新建模式）
    $trigger_mode   = 'new_floor';
    $reply_target   = 'floor';
    $match_keywords = '';
    $allow_replied  = 0;
    if (isset($_GET['edit'])) {
        // 编辑模式：从数据库读取
        $edit_id = intval($_GET['edit']);
        $edit_task = $m->fetch_array($m->query("SELECT * FROM `".DB_PREFIX."weltolk_autoreply_tasks` WHERE `id` = {$edit_id} AND `uid` = {$uid}"));
        if ($edit_task) {
            $trigger_mode   = isset($edit_task['trigger_mode']) ? $edit_task['trigger_mode'] : 'new_floor';
            $reply_target   = isset($edit_task['reply_target']) ? $edit_task['reply_target'] : 'floor';
            $match_keywords = isset($edit_task['match_keywords']) ? $edit_task['match_keywords'] : '';
            $allow_replied  = isset($edit_task['allow_replied']) ? (int)$edit_task['allow_replied'] : 0;
        }
    } else {
        // 新建模式：从 POST 读取
        $trigger_mode   = isset($_POST['trigger_mode']) ? $m->escape_string($_POST['trigger_mode']) : 'new_floor';
        $reply_target   = isset($_POST['reply_target']) ? $m->escape_string($_POST['reply_target']) : 'floor';
        $match_keywords = isset($_POST['match_keywords']) ? $m->escape_string($_POST['match_keywords']) : '';
        $allow_replied  = isset($_POST['allow_replied']) ? 1 : 0;
    }

    $skip_test = false;

    // 校验 pid 归属
    $pid_check = $m->fetch_array($m->query("SELECT COUNT(*) as c FROM `".DB_NAME."`.`".DB_PREFIX."baiduid` WHERE `id` = {$pid} AND `uid` = {$uid}"));
    if ($pid_check['c'] == 0) {
        $page_msg .= '<div class="alert alert-danger">越权操作：该百度账号不属于您</div>';
    } elseif (empty($fname) || empty($tid) || empty($reply_content) || empty($pid)) {
        $page_msg .= '<div class="alert alert-danger">测试发帖失败：请填写贴吧名称、帖子ID、回帖内容，并选择发帖账号。</div>';
    } else {
        $cookie = misc::getCookie($pid, true);
        $bduss  = $cookie['bduss'] ?? '';
        $stoken = $cookie['stoken'] ?? '';

        if (empty($bduss)) {
            $page_msg .= '<div class="alert alert-danger">测试发帖失败：无法获取所选账号的 BDUSS。</div>';
        } else {
            $tbs = misc::getTbs($uid, $bduss);
            $fid = misc::getFid($fname);

            // 获取楼层信息
            $quote_id = '';
            $reply_uid = '';
            $floor_num = '';
            $sub_post_id = '';
            $at_username = '';
            $at_portrait = '';
            $matched_keyword = '';
            $test_info = '';

            if ($trigger_mode === 'keyword') {
                // 关键词模式：获取最新20楼并匹配
                if (empty(trim($match_keywords))) {
                    $page_msg .= '<div class="alert alert-warning">关键词模式但未设置关键词，将作为主题回复。<br><small>提示：请先设置关键词再测试。</small></div>';
                } else {
                    $floors = weltolk_get_last_floor_content($tid, $bduss, 20);
                    if (empty($floors)) {
                        $page_msg .= '<div class="alert alert-danger">获取楼层内容失败，无法测试关键词匹配。</div>';
                    } else {
                        $keywords = explode("\n", $match_keywords);
                        $matched = false;
                        foreach ($floors as $floor) {
                            $floor_content = isset($floor['content']) ? $floor['content'] : '';
                            if (empty($floor_content)) continue;
                            foreach ($keywords as $kw) {
                                $kw = trim($kw);
                                if (!empty($kw) && mb_stripos($floor_content, $kw, 0, 'UTF-8') !== false) {
                                    $matched = true;
                                    $matched_keyword = $kw;
                                    $quote_id = isset($floor['id']) ? (string)$floor['id'] : '';
                                    $floor_num = isset($floor['floor']) ? (string)$floor['floor'] : '';
                                    $at_username = isset($floor['username']) ? $floor['username'] : '';
                                    $at_portrait = isset($floor['portrait']) ? $floor['portrait'] : '';
                                    $reply_uid = isset($floor['author_id']) ? (string)$floor['author_id'] : '';

                                    // 楼中楼模式：额外匹配楼中楼
                                    if ($reply_target === 'subpost' && !empty($floor['sub_posts'])) {
                                        $sub_matched = false;
                                        foreach ($floor['sub_posts'] as $sp) {
                                            $sp_content = isset($sp['content']) ? $sp['content'] : '';
                                            if (!empty($sp_content) && mb_stripos($sp_content, $kw, 0, 'UTF-8') !== false) {
                                                $sub_post_id = isset($sp['id']) ? (string)$sp['id'] : '';
                                                $reply_uid = isset($sp['author_id']) ? (string)$sp['author_id'] : '';
                                                $at_username = isset($sp['username']) ? $sp['username'] : '';
                                                $at_portrait = isset($sp['portrait']) ? $sp['portrait'] : '';
                                                $sub_matched = true;
                                                break;
                                            }
                                        }
                                    }
                                    break 2;
                                }
                            }
                        }
                        if (!$matched) {
                            $page_msg .= '<div class="alert alert-warning"><strong>关键词未匹配任何楼层</strong>，无法测试发帖。<br><small>最新 ' . count($floors) . ' 个楼层中未找到任何匹配关键词的内容。</small></div>';
                            $skip_test = true;
                        } else {
                            $test_info = "（关键词: {$matched_keyword} @#{$floor_num} 用户: {$at_username}）";
                        }
                    }
                }
            } else {
                // 新楼层模式：获取最新楼层作为回复目标
                $latest_floors = weltolk_get_last_floor_content($tid, $bduss, 1);
                if (!empty($latest_floors)) {
                    $latest = $latest_floors[0];
                    $quote_id = isset($latest['id']) ? (string)$latest['id'] : '';
                    $reply_uid = isset($latest['author_id']) ? (string)$latest['author_id'] : '';
                    $floor_num = isset($latest['floor']) ? (string)$latest['floor'] : '';
                    $at_username = isset($latest['username']) ? $latest['username'] : '';
                    $at_portrait = isset($latest['portrait']) ? $latest['portrait'] : '';
                    $test_info = "（回复 @#{$floor_num} {$at_username}）";
                } else {
                    $page_msg .= '<div class="alert alert-danger">获取最新楼层内容失败，将作为主题回复。</div>';
                }
            }

            // 替换变量
            $floor_for_replace = !empty($floor_num) ? $floor_num : '测试';
            $content = str_replace(
                ['{floor}', '{time}', '{date}', '{tid}', '{username}'],
                [$floor_for_replace, date('Y-m-d H:i:s'), date('Y-m-d'), (string)$tid, $at_username],
                $reply_content
            );

            // 楼中楼回复模式：添加内容前缀（对齐 TiebaLite）
            if ($trigger_mode === 'keyword' && $reply_target === 'subpost' && !empty($sub_post_id) && !empty($at_username)) {
                $content = "回复 #(reply, {$at_portrait}, {$at_username}) :{$content}";
            }

            // 执行发帖（关键词未匹配则跳过）
            if (!$skip_test) {
                require_once __DIR__ . '/lib/autoreply_api.php';
                $result = autoreply_add_post($bduss, $stoken, $tbs, $fname, $fid, $tid, $content, '贴吧用户', $quote_id, $reply_uid, $floor_num, $sub_post_id);

                if ($result['success']) {
                    $matchInfo = !empty($test_info) ? ' ' . htmlspecialchars($test_info) : '';
                    $page_msg .= '<div class="alert alert-success"><strong>测试成功！</strong>' . $matchInfo . ' 回帖已发送。帖子链接：<a href="https://tieba.baidu.com/p/'.$tid.'" target="_blank">查看帖子</a></div>';
                } elseif ($result['need_vcode']) {
                    $page_msg .= '<div class="alert alert-warning"><strong>需要验证码！</strong>请手动在贴吧App验证后重试。</div>';
                } else {
                    $page_msg .= '<div class="alert alert-danger"><strong>发帖失败</strong>: ['.$result['error_code'].'] '.htmlspecialchars($result['error_msg']).'</div>';
                    $page_msg .= '<div class="alert alert-info"><small>调试: '.htmlspecialchars($result['raw_debug']).'</small></div>';
                }
            }
        }
    }
}

// 保存逻辑（save 和 save_pause）
if (isset($_GET['save']) || isset($_GET['save_pause'])) {
    $fname            = isset($_POST['fname']) ? $m->escape_string($_POST['fname']) : '';
    $tid              = isset($_POST['tid']) ? intval($_POST['tid']) : 0;
    $reply_content    = isset($_POST['reply_content']) ? $m->escape_string($_POST['reply_content']) : '';
    $reply_interval   = isset($_POST['reply_interval']) ? intval($_POST['reply_interval']) : 300;
    $reply_probability = isset($_POST['reply_probability']) ? intval($_POST['reply_probability']) : 100;
    $pid              = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
    $trigger_mode     = isset($_POST['trigger_mode']) ? $m->escape_string($_POST['trigger_mode']) : 'new_floor';
    $match_keywords   = isset($_POST['match_keywords']) ? $m->escape_string($_POST['match_keywords']) : '';
    $reply_target     = isset($_POST['reply_target']) ? $m->escape_string($_POST['reply_target']) : 'floor';
    $allow_replied    = isset($_POST['allow_replied']) ? 1 : 0;
    $enabled          = isset($_GET['save_pause']) ? 0 : 1;

    // 校验 pid 归属
    $pid_check = $m->fetch_array($m->query("SELECT COUNT(*) as c FROM `".DB_NAME."`.`".DB_PREFIX."baiduid` WHERE `id` = {$pid} AND `uid` = {$uid}"));
    if ($pid_check['c'] == 0) {
        $page_msg .= '<div class="alert alert-danger">越权操作：该百度账号不属于您</div>';
    } elseif (empty($fname) || empty($tid) || empty($reply_content)) {
        $page_msg .= '<div class="alert alert-danger">请填写所有必填字段（贴吧名称、帖子ID、回帖内容）</div>';
    } else {
        if (isset($_GET['edit'])) {
            $edit_id = intval($_GET['edit']);
            $m->query("UPDATE `".DB_PREFIX."weltolk_autoreply_tasks` SET
                `pid` = {$pid},
                `fname` = '{$fname}',
                `tid` = {$tid},
                `reply_content` = '{$reply_content}',
                `reply_interval` = {$reply_interval},
                `reply_probability` = {$reply_probability},
                `trigger_mode` = '{$trigger_mode}',
                `reply_target` = '{$reply_target}',
                `allow_replied` = {$allow_replied},
                `match_keywords` = '{$match_keywords}',
                `enabled` = {$enabled}
                WHERE `id` = {$edit_id} AND `uid` = {$uid}");
            redirect('index.php?plugin=weltolk_autoreply&msg='.urlencode('保存成功'));
        } else {
            // 检查限额
            $limit = autoreply_get_user_limit($uid);
            $user_count = $m->fetch_array($m->query("SELECT COUNT(*) as c FROM `".DB_PREFIX."weltolk_autoreply_tasks` WHERE `uid` = {$uid}"));
            if ($user_count['c'] >= $limit) {
                $page_msg .= '<div class="alert alert-warning">已达到最大任务数限制（'.$limit.' 条），请删除不需要的任务后再添加新的。</div>';
            } else {
                $m->query("INSERT INTO `".DB_PREFIX."weltolk_autoreply_tasks`
                    (`uid`, `pid`, `fname`, `tid`, `reply_content`, `reply_interval`, `reply_probability`, `trigger_mode`, `reply_target`, `allow_replied`, `match_keywords`, `enabled`)
                    VALUES ({$uid}, {$pid}, '{$fname}', {$tid}, '{$reply_content}', {$reply_interval}, {$reply_probability}, '{$trigger_mode}', '{$reply_target}', {$allow_replied}, '{$match_keywords}', {$enabled})");
                redirect('index.php?plugin=weltolk_autoreply&msg='.urlencode('保存成功'));
            }
        }
    }
}

// 编辑模式：预填数据
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_result = $m->query("SELECT * FROM `".DB_PREFIX."weltolk_autoreply_tasks` WHERE `id` = {$edit_id} AND `uid` = {$uid}");
    $edit_data = $m->fetch_array($edit_result);
}

// 获取当前用户的任务列表
$tasks = [];
$task_result = $m->query("SELECT * FROM `".DB_PREFIX."weltolk_autoreply_tasks` WHERE `uid` = {$uid} ORDER BY `id` DESC");
while ($t = $m->fetch_array($task_result)) {
    $tasks[] = $t;
}

// ★★★ loadhead() 必须在所有 echo 之前 ★★★
loadhead();
?>

<h2>自动回帖</h2>
<br>

<?php if (!empty($page_msg)): ?>
    <?php echo $page_msg; ?>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
<?php endif; ?>

<?php if (empty($baidu_accounts)): ?>
    <div class="alert alert-warning">您需要先绑定至少一个百度账号才可以使用本功能。请前往 <a href="index.php?mod=baiduid">贴吧管理</a> 绑定账号。</div>
<?php else: ?>

<form method="post" action="index.php?plugin=weltolk_autoreply&save<?php if ($edit_data) { echo '&edit='.$edit_data['id']; } ?>" id="autoreplyForm">

<!-- 分区 1：选择发帖账号 -->
<div class="panel panel-default">
    <div class="panel-heading"><strong>1. 选择发帖账号</strong></div>
    <div class="panel-body">
        <div class="form-group">
            <label class="control-label">发帖账号 <a href="javascript:void(0);" onclick="showHelp('pid')" class="label label-info" style="font-size:11px;">?</a></label>
            <select class="form-control" name="pid" required>
                <option value="">-- 请选择百度账号 --</option>
                <?php foreach ($baidu_accounts as $acc): ?>
                    <?php
                    $selected = '';
                    if ($edit_data && $edit_data['pid'] == $acc['id']) {
                        $selected = 'selected';
                    } elseif (!$edit_data && count($baidu_accounts) == 1) {
                        $selected = 'selected';
                    }
                    ?>
                    <option value="<?php echo $acc['id']; ?>"<?php echo $selected; ?>><?php echo htmlspecialchars($acc['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<!-- 分区 2：目标帖子 -->
<div class="panel panel-default">
    <div class="panel-heading"><strong>2. 目标帖子</strong></div>
    <div class="panel-body">
        <div class="form-group">
            <label class="control-label">贴吧名称 <a href="javascript:void(0);" onclick="showHelp('fname')" class="label label-info" style="font-size:11px;">?</a></label>
            <input type="text" class="form-control" name="fname" placeholder="例如：天堂鸡汤" required value="<?php echo $edit_data ? htmlspecialchars($edit_data['fname']) : ''; ?>">
        </div>
        <div class="form-group">
            <label class="control-label">帖子 ID <a href="javascript:void(0);" onclick="showHelp('tid')" class="label label-info" style="font-size:11px;">?</a></label>
            <input type="number" class="form-control" name="tid" placeholder="帖子链接中 p/ 后面的数字" required value="<?php echo $edit_data ? htmlspecialchars($edit_data['tid']) : ''; ?>">
        </div>
    </div>
</div>

<!-- 分区 3：回复内容 -->
<div class="panel panel-default">
    <div class="panel-heading"><strong>3. 回复内容</strong></div>
    <div class="panel-body">
        <div class="form-group">
            <label class="control-label">回帖内容 <a href="javascript:void(0);" onclick="showHelp('content')" class="label label-info" style="font-size:11px;">?</a></label>
            <textarea class="form-control" name="reply_content" rows="4" placeholder="支持变量：{floor} 当前楼层, {time} 当前时间, {date} 日期, {tid} 帖子ID, {username} 楼层用户名" required><?php echo $edit_data ? htmlspecialchars($edit_data['reply_content']) : ''; ?></textarea>
            <span class="help-block">
                <small>可用变量：<code>{floor}</code> 当前楼层（回复数） <code>{time}</code> 当前时间 <code>{date}</code> 日期 <code>{tid}</code> 帖子ID <code>{username}</code> 楼层用户名</small>
            </span>
        </div>
    </div>
</div>

<!-- 分区 4：自动执行规则 -->
<div class="panel panel-default">
    <div class="panel-heading"><strong>4. 自动执行规则</strong></div>
    <div class="panel-body">
        <div style="display:flex; gap:15px;">
            <div style="flex:1;">
                <label class="control-label">回帖间隔（秒） <a href="javascript:void(0);" onclick="showHelp('interval')" class="label label-info" style="font-size:11px;">?</a></label>
                <input type="number" class="form-control" name="reply_interval" placeholder="默认 300" value="<?php echo $edit_data ? htmlspecialchars($edit_data['reply_interval']) : '300'; ?>" min="10">
            </div>
            <div style="flex:1;">
                <label class="control-label">回帖概率（%） <a href="javascript:void(0);" onclick="showHelp('probability')" class="label label-info" style="font-size:11px;">?</a></label>
                <input type="number" class="form-control" name="reply_probability" placeholder="1-100，默认 100" value="<?php echo $edit_data ? htmlspecialchars($edit_data['reply_probability']) : '100'; ?>" min="1" max="100">
            </div>
        </div>
        <div style="margin-top:12px;">
            <label class="control-label">触发模式 <a href="javascript:void(0);" onclick="showHelp('trigger')" class="label label-info" style="font-size:11px;">?</a></label>
            <div>
                <label class="radio-inline">
                    <input type="radio" name="trigger_mode" value="new_floor" <?php if (!$edit_data || $edit_data['trigger_mode'] == 'new_floor') echo 'checked'; ?> onclick="toggleKeywords()">
                    新楼层即回复
                </label>
                <label class="radio-inline">
                    <input type="radio" name="trigger_mode" value="keyword" <?php if ($edit_data && $edit_data['trigger_mode'] == 'keyword') echo 'checked'; ?> onclick="toggleKeywords()">
                    关键词匹配回复
                </label>
            </div>
        </div>
        <div id="keywordsGroup" style="display:<?php echo ($edit_data && $edit_data['trigger_mode'] == 'keyword') ? 'block' : 'none'; ?>; margin-top:12px;">
            <label class="control-label">匹配关键词 <a href="javascript:void(0);" onclick="showHelp('keywords')" class="label label-info" style="font-size:11px;">?</a></label>
            <textarea class="form-control" name="match_keywords" rows="3" placeholder="一行一个关键词，楼层内容包含任一即触发回复"><?php echo $edit_data ? htmlspecialchars($edit_data['match_keywords']) : ''; ?></textarea>
        </div>
        <div id="replyTargetGroup" style="display:<?php echo ($edit_data && $edit_data['trigger_mode'] == 'keyword') ? 'block' : 'none'; ?>; margin-top:12px;">
            <label class="control-label">回复目标 <a href="javascript:void(0);" onclick="showHelp('reply_target')" class="label label-info" style="font-size:11px;">?</a></label>
            <div>
                <label class="radio-inline">
                    <input type="radio" name="reply_target" value="floor" <?php if (!$edit_data || $edit_data['reply_target'] == 'floor') echo 'checked'; ?>>
                    主楼层回复
                </label>
                <label class="radio-inline">
                    <input type="radio" name="reply_target" value="subpost" <?php if ($edit_data && $edit_data['reply_target'] == 'subpost') echo 'checked'; ?>>
                    楼中楼回复
                </label>
            </div>
        </div>
        <div class="checkbox" style="margin-top:12px;">
            <label>
                <input type="checkbox" name="allow_replied" value="1" <?php if ($edit_data && $edit_data['allow_replied'] == 1) echo 'checked'; ?>> 允许回复已回复过的楼层
            </label>
        </div>
        <div class="checkbox" style="margin-top:12px;">
            <label>
                <input type="checkbox" name="enabled" <?php if (!$edit_data || $edit_data['enabled'] == 1) { echo 'checked'; } ?>> 是否启用
            </label>
        </div>
    </div>
</div>

<!-- 操作按钮 -->
<div class="form-group">
    <button type="button" class="btn btn-info" onclick="submitForm('test')"><i class="glyphicon glyphicon-send"></i> 测试发一条</button>
    <button type="button" class="btn btn-primary" onclick="submitForm('save')"><i class="glyphicon glyphicon-ok"></i> <?php echo isset($_GET['edit']) ? '保存修改' : '保存并启用'; ?></button>
    <?php if (!isset($_GET['edit'])): ?>
    <button type="button" class="btn btn-warning" onclick="submitForm('save_pause')"><i class="glyphicon glyphicon-pause"></i> 保存但暂停</button>
    <?php endif; ?>
    <?php if (isset($_GET['edit'])): ?>
    <a href="index.php?plugin=weltolk_autoreply" class="btn btn-default">取消编辑</a>
    <?php endif; ?>
</div>

</form>

<?php endif; ?>

<!-- 帮助弹窗（全局，不依赖是否有任务） -->
<div class="modal fade" id="helpModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="helpTitle">帮助</h4>
            </div>
            <div class="modal-body" id="helpBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">知道了</button>
            </div>
        </div>
    </div>
</div>

<script>
var helpTexts = {
    interval: '<h5>回帖间隔</h5><p>两次自动回帖之间<b>最少</b>等待的秒数。</p><p><b>示例：</b>设为 300 表示回帖后至少等 5 分钟才可能再次回帖。</p><p><b>建议：</b>不低于 60 秒，避免触发贴吧频率限制。</p>',
    probability: '<h5>回帖概率</h5><p>检测到新回复时<b>实际执行回帖</b>的概率。</p><p><b>示例：</b>设为 50 表示每次检测到新楼层，有一半的机率会回帖，一半跳过。</p><p><b>建议：</b>设 100 则每次都回，设低值可降低封号风险。</p>',
    trigger: '<h5>触发模式</h5><p><b>新楼层即回复：</b>只要帖子有新楼层就触发回帖（需满足间隔和概率条件）。</p><p><b>关键词匹配回复：</b>帖子有新楼层时，先检查楼层内容是否包含你设置的关键词，匹配成功才回帖，且会自动在回帖内容前加上 @楼层用户名 前缀。</p>',
    keywords: '<h5>匹配关键词</h5><p>仅在触发模式为"关键词匹配回复"时生效。</p><p><b>格式：</b>一行一个关键词。</p><p><b>示例：</b><br><code>求助</code><br><code>怎么解决</code><br><code>报错</code></p><p>当最新楼层内容包含<code>求助</code>、<code>怎么解决</code>或<code>报错</code>任一词语时触发回帖。</p><p><b>提示：</b>不区分大小写，回复内容可用 <code>{username}</code> 变量引用楼层用户名。</p>',
    reply_target: '<h5>回复目标</h5><p><b>主楼层回复：</b>直接回复帖子的楼层</p><p><b>楼中楼回复：</b>回复楼层中的楼中楼评论（需要关键词模式）</p>',
    allow_replied: '开启后，每次执行都会重新扫描所有楼层（包括已回复过的），不推进水位。默认关闭，只回复新楼层。',
    content: '<h5>回帖内容变量</h5><p><b>{floor}</b> — 当前楼层数（回复数）</p><p><b>{time}</b> — 当前时间，如 18:30:00</p><p><b>{date}</b> — 当前日期，如 2026-06-16</p><p><b>{tid}</b> — 帖子ID</p><p><b>{username}</b> — 楼层用户名（仅关键词模式）</p><p><b>示例：</b><code>第{floor}楼打卡！{date}</code> → <code>第100楼打卡！2026-06-16</code></p>',
    fname: '<h5>贴吧名称</h5><p>目标贴吧的<b>名称</b>，不是网址。</p><p><b>正确：</b><code>天堂鸡汤</code></p><p><b>错误：</b><code>https://tieba.baidu.com/f?kw=天堂鸡汤</code></p>',
    tid: '<h5>帖子 ID</h5><p>帖子的<b>纯数字 ID</b>，从帖子网址中获取。</p><p><b>示例：</b>帖子链接为 <code>https://tieba.baidu.com/p/12345678</code>，则 ID 为 <code>12345678</code></p>',
    pid: '<h5>发帖账号</h5><p>选择用于自动回帖的<b>百度贴吧账号</b>。必须已在云签到中绑定。</p><p><b>提示：</b>如无可用账号，请先前往 <a href="index.php?mod=baiduid">贴吧管理</a> 绑定。</p>'
};

function showHelp(key) {
    document.getElementById('helpTitle').innerText = '帮助';
    document.getElementById('helpBody').innerHTML = helpTexts[key] || '暂无帮助信息。';
    jQuery('#helpModal').modal('show');
}

function submitForm(action) {
    var form = document.getElementById('autoreplyForm');
    form.action = 'index.php?plugin=weltolk_autoreply&' + action<?php if (isset($_GET['edit'])) { echo " + '&edit=" . intval($_GET['edit']) . "'"; } ?>;
    form.submit();
}

function toggleKeywords() {
    var radios = document.getElementsByName('trigger_mode');
    var mode = 'new_floor';
    for (var i = 0; i < radios.length; i++) {
        if (radios[i].checked) { mode = radios[i].value; break; }
    }
    var keywordMode = (mode === 'keyword');
    document.getElementById('keywordsGroup').style.display = keywordMode ? 'block' : 'none';
    var replyTargetGroup = document.getElementById('replyTargetGroup');
    if (replyTargetGroup) {
        replyTargetGroup.style.display = keywordMode ? 'block' : 'none';
    }
}

function deleteTask(id) {
    var btn = event.target.closest('a');
    var fname = btn.getAttribute('data-fname');
    var tid = btn.getAttribute('data-tid');
    var content = btn.getAttribute('data-content');
    document.getElementById('del_fname').textContent = fname;
    document.getElementById('del_tid').textContent = tid;
    document.getElementById('del_content').textContent = content;
    document.getElementById('del_confirm').href = 'index.php?plugin=weltolk_autoreply&delete=' + id;
    jQuery('#deleteModal').modal('show');
}
</script>

<?php if (!empty($tasks)): ?>
<hr>
<h3>已添加的任务</h3>
<div class="table-responsive">
<table class="table table-striped table-bordered">
    <thead>
        <tr>
            <th>贴吧</th>
            <th>帖子ID</th>
            <th>回复内容</th>
            <th>间隔</th>
            <th>概率</th>
            <th>状态</th>
            <th>上次检测</th>
            <th>上次错误</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($tasks as $task): ?>
        <?php
        $content_preview = mb_substr($task['reply_content'], 0, 20, 'UTF-8');
        if (mb_strlen($task['reply_content'], 'UTF-8') > 20) {
            $content_preview .= '...';
        }
        $status_icon = '';
        $status_text = '';
        $status_class = '';
        if ($task['enabled'] == 1) {
            if (!empty($task['last_status']) && $task['last_status'] == 'error') {
                $status_icon = 'glyphicon glyphicon-exclamation-sign';
                $status_text = '异常';
                $status_class = 'label label-warning';
            } elseif (!empty($task['last_status']) && $task['last_status'] == 'vcode') {
                $status_icon = 'glyphicon glyphicon-lock';
                $status_text = '需验证码';
                $status_class = 'label label-warning';
            } else {
                $status_icon = 'glyphicon glyphicon-play';
                $status_text = '运行中';
                $status_class = 'label label-success';
            }
        } else {
            if (!empty($task['last_status']) && $task['last_status'] == 'error') {
                $status_icon = 'glyphicon glyphicon-ban-circle';
                $status_text = '失败已停用';
                $status_class = 'label label-danger';
            } else {
                $status_icon = 'glyphicon glyphicon-pause';
                $status_text = '已暂停';
                $status_class = 'label label-default';
            }
        }
        $last_check_display = !empty($task['last_check_time']) ? date('m-d H:i', $task['last_check_time']) : '-';
        $error_preview = !empty($task['last_error']) ? mb_substr($task['last_error'], 0, 30, 'UTF-8') : '';
        ?>
        <tr>
            <td><?php echo htmlspecialchars($task['fname']); ?></td>
            <td><a href="https://tieba.baidu.com/p/<?php echo htmlspecialchars($task['tid']); ?>" target="_blank"><?php echo htmlspecialchars($task['tid']); ?></a></td>
            <td title="<?php echo htmlspecialchars($task['reply_content']); ?>"><?php echo htmlspecialchars($content_preview); ?></td>
            <td><?php echo $task['reply_interval']; ?>s</td>
            <td><?php echo $task['reply_probability']; ?>%</td>
            <td><span class="<?php echo $status_class; ?>"><i class="<?php echo $status_icon; ?>"></i> <?php echo $status_text; ?></span></td>
            <td><?php echo $last_check_display; ?></td>
            <td title="<?php echo htmlspecialchars((string)$task['last_error']); ?>"><?php echo $error_preview ? htmlspecialchars($error_preview) : '-'; ?></td>
            <td>
                <a href="index.php?plugin=weltolk_autoreply&edit=<?php echo $task['id']; ?>" class="btn btn-xs btn-default"><i class="glyphicon glyphicon-edit"></i></a>
                <button class="btn btn-xs btn-info" data-toggle="modal" data-target="#LogTask<?php echo $task['id']; ?>"><i class="glyphicon glyphicon-list-alt"></i></button>
                <a href="javascript:void(0);" class="btn btn-xs btn-danger" onclick="deleteTask(<?php echo $task['id']; ?>);" data-id="<?php echo $task['id']; ?>" data-fname="<?php echo htmlspecialchars($task['fname']); ?>" data-tid="<?php echo htmlspecialchars($task['tid']); ?>" data-content="<?php echo htmlspecialchars(mb_substr($task['reply_content'], 0, 30, 'UTF-8')); ?>"><i class="glyphicon glyphicon-trash"></i></a>
            </td>
        </tr>

        <!-- 日志 Modal -->
        <div class="modal fade" id="LogTask<?php echo $task['id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title">日志详情</h4>
                    </div>
                    <div class="modal-body">
                        <div class="input-group" style="width:100%;">
                            <?php
                            $log_content = empty($task['log']) ? '暂无日志' : $task['log'];
                            // 先转义所有 HTML，再把 &lt;br&gt; 还原为 <br> 保留换行
                            $log_content = str_replace('&lt;br&gt;', '<br>', htmlspecialchars($log_content, ENT_QUOTES, 'UTF-8'));
                            echo $log_content;
                            ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">关闭</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- 删除确认 Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">确认删除</h4>
            </div>
            <div class="modal-body">
                <p>确定要删除这个任务吗？</p>
                <table class="table table-bordered" style="margin-bottom:0;">
                    <tr><td style="width:80px;">贴吧</td><td id="del_fname"></td></tr>
                    <tr><td>帖子ID</td><td id="del_tid"></td></tr>
                    <tr><td>回复内容</td><td id="del_content"></td></tr>
                </table>
                <p class="text-danger" style="margin-top:10px;"><strong>此操作不可撤销。</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <a href="#" id="del_confirm" class="btn btn-danger">确认删除</a>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<?php if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

global $m;

// CSRF 防护：生成和验证 token
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['weltolk_autoreply_csrf_token'])) {
    $_SESSION['weltolk_autoreply_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['weltolk_autoreply_csrf_token'];

// 验证 CSRF token（对写操作）
function weltolk_autoreply_check_csrf() {
    $token = isset($_REQUEST['csrf_token']) ? $_REQUEST['csrf_token'] : '';
    return !empty($token) && hash_equals($_SESSION['weltolk_autoreply_csrf_token'] ?? '', $token);
}

// 辅助函数：根据 uid 获取用户名
function autoreply_get_username($uid)
{
    global $m;
    $uid = intval($uid);
    $r = $m->fetch_array($m->query("SELECT `name` FROM `" . DB_PREFIX . "users` WHERE `id` = {$uid}"));
    return $r ? $r['name'] : '未知用户';
}

// 获取用户的任务数量上限（个人覆盖 > 全局默认 > 5）
function autoreply_get_user_limit($uid) {
    $personal = intval(option::uget('weltolk_autoreply_limit', $uid));
    if ($personal > 0) return $personal;
    $global = intval(option::get('weltolk_autoreply_limit'));
    return $global > 0 ? $global : 5;
}

// 显示消息
if (isset($_GET['msg'])) {
    echo '<div class="alert alert-success">' . htmlspecialchars($_GET['msg']) . '</div>';
}

// 删除操作
if (isset($_GET['delete'])) {
    if (!weltolk_autoreply_check_csrf()) {
        die('CSRF token 验证失败');
    }
    $delete_id = intval($_GET['delete']);
    $m->query("DELETE FROM `" . DB_PREFIX . "weltolk_autoreply_tasks` WHERE `id` = {$delete_id}");
    redirect('index.php?mod=admin:setplug&plug=weltolk_autoreply&msg=' . urlencode('删除成功'));
}

// 保存限额设置
if (isset($_GET['savelimit'])) {
    if (!weltolk_autoreply_check_csrf()) {
        die('CSRF token 验证失败');
    }
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 5;
    if ($limit < 1) $limit = 1;
    option::set('weltolk_autoreply_limit', $limit);
    redirect('index.php?mod=admin:setplug&plug=weltolk_autoreply&msg=' . urlencode('限额已保存为 ' . $limit . ' 条'));
}

// 保存个人限额
if (isset($_GET['saveuserlimit'])) {
    if (!weltolk_autoreply_check_csrf()) {
        die('CSRF token 验证失败');
    }
    $limit_uid = isset($_POST['limit_uid']) ? intval($_POST['limit_uid']) : 0;
    $limit_val = isset($_POST['limit_val']) ? intval($_POST['limit_val']) : 0;
    if ($limit_uid > 0) {
        if ($limit_val < 1) {
            // 设为 0 表示清除个人覆盖，回归全局默认
            option::uset('weltolk_autoreply_limit', 0, $limit_uid);
            redirect('index.php?mod=admin:setplug&plug=weltolk_autoreply&msg=' . urlencode('已清除用户 ' . autoreply_get_username($limit_uid) . ' 的个人限额，将使用全局默认'));
        } else {
            option::uset('weltolk_autoreply_limit', $limit_val, $limit_uid);
            redirect('index.php?mod=admin:setplug&plug=weltolk_autoreply&msg=' . urlencode('用户 ' . autoreply_get_username($limit_uid) . ' 个人限额已设为 ' . $limit_val . ' 条'));
        }
    }
}

// 保存操作
if (isset($_GET['save'])) {
    if (!weltolk_autoreply_check_csrf()) {
        die('CSRF token 验证失败');
    }
    $uid = isset($_POST['uid']) ? intval($_POST['uid']) : 0;
    $fname = isset($_POST['fname']) ? $m->escape_string($_POST['fname']) : '';
    $tid = isset($_POST['tid']) ? intval($_POST['tid']) : 0;
    $reply_content = isset($_POST['reply_content']) ? $m->escape_string($_POST['reply_content']) : '';
    $reply_interval = isset($_POST['reply_interval']) ? intval($_POST['reply_interval']) : 300;
    $reply_probability = isset($_POST['reply_probability']) ? intval($_POST['reply_probability']) : 100;
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $trigger_mode = isset($_POST['trigger_mode']) ? $m->escape_string($_POST['trigger_mode']) : 'new_floor';
    $match_keywords = isset($_POST['match_keywords']) ? $m->escape_string($_POST['match_keywords']) : '';
    $reply_target = isset($_POST['reply_target']) ? $m->escape_string($_POST['reply_target']) : 'floor';
    $allow_replied = isset($_POST['allow_replied']) ? 1 : 0;

    if (empty($uid) || empty($fname) || empty($tid) || empty($reply_content)) {
        echo '<div class="alert alert-danger">请填写所有必填字段（用户、贴吧名称、帖子ID、回帖内容）</div>';
    } else {
        if (isset($_GET['edit'])) {
            // 更新
            $edit_id = intval($_GET['edit']);
            $m->query("UPDATE `" . DB_PREFIX . "weltolk_autoreply_tasks` SET
                `uid` = {$uid},
                `fname` = '{$fname}',
                `tid` = {$tid},
                `reply_content` = '{$reply_content}',
                `reply_interval` = {$reply_interval},
                `reply_probability` = {$reply_probability},
                `enabled` = {$enabled},
                `trigger_mode` = '{$trigger_mode}',
                `reply_target` = '{$reply_target}',
                `allow_replied` = {$allow_replied},
                `match_keywords` = '{$match_keywords}'
                WHERE `id` = {$edit_id}");
            redirect('index.php?mod=admin:setplug&plug=weltolk_autoreply&msg=' . urlencode('保存成功'));
        } else {
            // 检查用户任务数是否达限额
            $limit = autoreply_get_user_limit($uid);
            $user_count = $m->fetch_array($m->query("SELECT COUNT(*) as c FROM `" . DB_PREFIX . "weltolk_autoreply_tasks` WHERE `uid` = {$uid}"));
            if ($user_count['c'] >= $limit) {
                echo '<div class="alert alert-danger">用户 ' . autoreply_get_username($uid) . ' 已达到最大任务数限制（' . $limit . ' 条），请先删除旧任务。</div>';
            } else {
                // 新增
                $m->query("INSERT INTO `" . DB_PREFIX . "weltolk_autoreply_tasks`
                    (`uid`, `fname`, `tid`, `reply_content`, `reply_interval`, `reply_probability`, `enabled`, `trigger_mode`, `reply_target`, `allow_replied`, `match_keywords`)
                    VALUES ({$uid}, '{$fname}', {$tid}, '{$reply_content}', {$reply_interval}, {$reply_probability}, {$enabled}, '{$trigger_mode}', '{$reply_target}', {$allow_replied}, '{$match_keywords}')");
                redirect('index.php?mod=admin:setplug&plug=weltolk_autoreply&msg=' . urlencode('保存成功'));
            }
        }
    }
}

// 如果是编辑模式，获取现有数据
$edit_data = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    $edit_result = $m->query("SELECT * FROM `" . DB_PREFIX . "weltolk_autoreply_tasks` WHERE `id` = {$edit_id}");
    $edit_data = $m->fetch_array($edit_result);
}
?>

<h2>自动回帖设置</h2>
<p style="color:#337ab7; font-weight:bold;">管理员全局概览 — 可查看和管理所有用户任务</p>
<br>

<!-- 全局限额设置 -->
<div class="panel panel-info">
    <div class="panel-heading"><strong>默认任务数量上限</strong></div>
    <div class="panel-body">
        <form method="post" action="index.php?mod=admin:setplug&plug=weltolk_autoreply&savelimit" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label>全局默认上限：</label>
                <input type="number" class="form-control" name="limit" value="<?php echo intval(option::get('weltolk_autoreply_limit')) ?: 5; ?>" min="1" style="width:80px;">
                <span class="help-block" style="display:inline; margin-left:10px;">条（对所有未单独设置的用户生效）</span>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">保存</button>
        </form>
    </div>
</div>

<!-- 个人限额设置 -->
<div class="panel panel-warning">
    <div class="panel-heading"><strong>单独设置用户任务上限</strong></div>
    <div class="panel-body">
        <form method="post" action="index.php?mod=admin:setplug&plug=weltolk_autoreply&saveuserlimit" class="form-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label>用户：</label>
                <select name="limit_uid" class="form-control" required style="width:200px;">
                    <option value="">-- 选择用户 --</option>
                    <?php
                    $all_users = $m->query("SELECT `id`, `name` FROM `" . DB_PREFIX . "users` ORDER BY `id`");
                    while ($u = $m->fetch_array($all_users)) {
                        echo '<option value="' . $u['id'] . '">' . htmlspecialchars($u['name']) . ' (ID:' . $u['id'] . ')</option>';
                    }
                    ?>
                </select>
            </div>
            <div class="form-group">
                <label>任务上限：</label>
                <input type="number" class="form-control" name="limit_val" placeholder="条" min="0" style="width:80px;">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">设置个人限额</button>
            <span class="help-block"><small>设为 0 则清除个人覆盖，恢复全局默认。</small></span>
        </form>
    </div>
</div>

<form method="post" action="index.php?mod=admin:setplug&plug=weltolk_autoreply&save<?php if ($edit_data) { echo '&edit=' . $edit_data['id']; } ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <div class="input-group">
        <span class="input-group-addon">用户</span>
        <select name="uid" class="form-control" required>
            <option value="">请选择用户</option>
            <?php
            $users = $m->query("SELECT `id`, `name` FROM `" . DB_PREFIX . "users`");
            while ($u = $m->fetch_array($users)) {
                $selected = ($edit_data && $edit_data['uid'] == $u['id']) ? ' selected' : '';
                echo '<option value="' . $u['id'] . '"' . $selected . '>' . htmlspecialchars($u['name']) . '</option>';
            }
            ?>
        </select>
    </div>
    <br>
    <div class="input-group">
        <span class="input-group-addon">贴吧名称</span>
        <input type="text" class="form-control" name="fname" placeholder="例如：游戏" value="<?php echo $edit_data ? htmlspecialchars($edit_data['fname']) : ''; ?>" required>
    </div>
    <br>
    <div class="input-group">
        <span class="input-group-addon">帖子ID</span>
        <input type="number" class="form-control" name="tid" placeholder="帖子的数字ID" value="<?php echo $edit_data ? htmlspecialchars($edit_data['tid']) : ''; ?>" required>
    </div>
    <br>
    <div class="input-group">
        <span class="input-group-addon">回帖内容<br><small>变量：{floor} {time} {tid} {date}</small></span>
        <textarea class="form-control" name="reply_content" rows="4" placeholder="支持变量：{floor} 楼层, {time} 时间, {tid} 帖子ID, {date} 日期" required><?php echo $edit_data ? htmlspecialchars($edit_data['reply_content']) : ''; ?></textarea>
    </div>
    <br>
    <div class="input-group">
        <span class="input-group-addon">回帖间隔(秒)</span>
        <input type="number" class="form-control" name="reply_interval" placeholder="默认 300" value="<?php echo $edit_data ? htmlspecialchars($edit_data['reply_interval']) : '300'; ?>" min="10">
    </div>
    <br>
    <div class="input-group">
        <span class="input-group-addon">回帖概率(%)</span>
        <input type="number" class="form-control" name="reply_probability" placeholder="1-100，默认 100" value="<?php echo $edit_data ? htmlspecialchars($edit_data['reply_probability']) : '100'; ?>" min="1" max="100">
    </div>
    <br>
    <div class="input-group">
        <span class="input-group-addon">触发模式</span>
        <div style="padding:6px 12px; background:#fff; border:1px solid #ccc; border-left:0; display:table-cell; width:100%;">
            <label class="radio-inline">
                <input type="radio" name="trigger_mode" value="new_floor" <?php if (!$edit_data || $edit_data['trigger_mode'] == 'new_floor') echo 'checked'; ?> onchange="toggleKeywordsSetting()">
                新楼层即回复
            </label>
            <label class="radio-inline">
                <input type="radio" name="trigger_mode" value="keyword" <?php if ($edit_data && $edit_data['trigger_mode'] == 'keyword') echo 'checked'; ?> onchange="toggleKeywordsSetting()">
                关键词匹配回复
            </label>
        </div>
    </div>
    <br>
    <div class="input-group" id="keywordsGroupSetting" style="display:<?php echo ($edit_data && $edit_data['trigger_mode'] == 'keyword') ? '' : 'none'; ?>;">
        <span class="input-group-addon">匹配关键词<br><small>一行一个</small></span>
        <textarea class="form-control" name="match_keywords" rows="3" placeholder="关键词匹配模式下，楼层内容含任一关键词即触发回复@楼层用户"><?php echo $edit_data ? htmlspecialchars($edit_data['match_keywords']) : ''; ?></textarea>
    </div>
    <br>
    <div class="input-group" id="replyTargetGroupSetting" style="display:<?php echo ($edit_data && $edit_data['trigger_mode'] == 'keyword') ? '' : 'none'; ?>;">
        <span class="input-group-addon">回复目标</span>
        <div style="padding:6px 12px; background:#fff; border:1px solid #ccc; border-left:0; display:table-cell; width:100%;">
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
    <br>
    <div class="checkbox">
        <label>
            <input type="checkbox" name="allow_replied" value="1" <?php if ($edit_data && $edit_data['allow_replied'] == 1) echo 'checked'; ?>> 允许回复已回复过的楼层
        </label>
    </div>
    <br>
    <label>
        <input type="checkbox" name="enabled" <?php
            if ($edit_data) {
                echo $edit_data['enabled'] ? 'checked' : '';
            } else {
                echo 'checked';
            }
        ?>> 是否启用
    </label>
    <br><br>
    <input type="submit" class="btn btn-primary" value="<?php echo $edit_data ? '更新任务' : '添加任务'; ?>">
    <?php if ($edit_data) { ?>
        <a href="index.php?mod=admin:setplug&plug=weltolk_autoreply" class="btn btn-default">取消编辑</a>
    <?php } ?>
</form>

<br><br>
<h3>现有任务列表</h3>
<br>
<table class="table table-striped">
    <thead>
        <tr>
            <th>ID</th>
            <th>用户</th>
            <th>贴吧</th>
            <th>帖子ID</th>
            <th>回帖内容</th>
            <th>间隔(秒)</th>
            <th>概率(%)</th>
            <th>上次楼层</th>
            <th>上次回帖</th>
            <th>重试次数</th>
            <th>限额</th>
            <th>状态</th>
            <th>操作</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $tasks = $m->query("SELECT * FROM `" . DB_PREFIX . "weltolk_autoreply_tasks` ORDER BY `id` DESC");
    while ($task = $m->fetch_array($tasks)) {
        ?>
        <tr>
            <td><?php echo $task['id']; ?></td>
            <td><?php echo htmlspecialchars(autoreply_get_username($task['uid'])); ?></td>
            <td><?php echo htmlspecialchars($task['fname']); ?></td>
            <td><?php echo $task['tid']; ?></td>
            <td><?php echo htmlspecialchars($task['reply_content']); ?></td>
            <td><?php echo $task['reply_interval']; ?></td>
            <td><?php echo $task['reply_probability']; ?></td>
            <td><?php echo $task['last_floor']; ?></td>
            <td><?php echo $task['last_reply_time'] > 0 ? date('Y-m-d H:i:s', $task['last_reply_time']) : '-'; ?></td>
            <td><?php echo $task['retry_count']; ?></td>
            <td>
                <?php
                $task_limit = autoreply_get_user_limit($task['uid']);
                $personal = intval(option::uget('weltolk_autoreply_limit', $task['uid']));
                echo $task_limit;
                if ($personal > 0) {
                    echo ' <small style="color:#337ab7;">(个人)</small>';
                } else {
                    echo ' <small style="color:#999;">(默认)</small>';
                }
                ?>
            </td>
            <td>
                <?php if ($task['enabled']) { ?>
                    <span style="color:green;">启用</span>
                <?php } else { ?>
                    <span style="color:red;">禁用</span>
                <?php } ?>
            </td>
            <td>
                <a href="index.php?mod=admin:setplug&plug=weltolk_autoreply&edit=<?php echo $task['id']; ?>" class="btn btn-default btn-sm" title="编辑"><span class="glyphicon glyphicon-edit"></span></a>
                <a href="index.php?mod=admin:setplug&plug=weltolk_autoreply&delete=<?php echo $task['id']; ?>&csrf_token=<?php echo htmlspecialchars($csrf_token); ?>" class="btn btn-default btn-sm" title="删除" onclick="return confirm('确定要删除该任务吗？');"><span class="glyphicon glyphicon-remove"></span></a>
            </td>
        </tr>
        <?php
    }
    ?>
    </tbody>
</table>

<script>
function toggleKeywordsSetting() {
    var mode = document.querySelector('input[name="trigger_mode"]:checked').value;
    var keywordMode = (mode === 'keyword');
    document.getElementById('keywordsGroupSetting').style.display = keywordMode ? '' : 'none';
    var replyTargetGroup = document.getElementById('replyTargetGroupSetting');
    if (replyTargetGroup) {
        replyTargetGroup.style.display = keywordMode ? '' : 'none';
    }
}
</script>

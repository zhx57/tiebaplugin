<?php
/**
 * 测试：回复内容含"我是"的楼层，回复内容"666"
 * 用法：在终端执行 php test_floor_reply.php
 * 请修改下面的 BDUSS 和帖子 ID
 */

$BDUSS = 'lZnemhWRVVYZTFGZ1B...';  // ← 改成你的 BDUSS
$TID   = '9534834391';              // ← 改成帖子 ID

require __DIR__ . '/../../init.php';
require __DIR__ . '/lib/autoreply_api.php';

// 偷 cron 里的函数
function _test_call_json_api($tid, $bduss, $pn = '1', $rn = '1', $r = '0')
{
    $secret = 'tiebaclient!!!';
    $st_time = rand(100, 850);
    $st_size = round((mt_rand() / mt_getrandmax() * 8 + 0.4) * $st_time);
    $cuid = 'baidutiebaapp' . rand(10000000, 99999999);

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

    ksort($params);
    $raw = '';
    foreach ($params as $k => $v) { $raw .= $k . '=' . $v; }
    $params['sign'] = md5($raw . $secret);

    $body = '';
    foreach ($params as $k => $v) {
        if ($body !== '') $body .= '&';
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

    if (empty($resp)) return null;
    return json_decode($resp, true);
}

echo "=== 1. 获取帖子最近楼层 ===\n";

$bduss = $BDUSS;
$tid   = $TID;

// 获取 fid 和 tbs
global $m;
$bind = $m->fetch_array($m->query(
    "SELECT `uid` FROM `" . DB_NAME . "`.`" . DB_PREFIX . "baiduid` WHERE `bduss` = '" . $m->escape_string($bduss) . "' LIMIT 1"
));
$uid = $bind['uid'] ?? 0;
echo "uid = {$uid}\n";

$tbs = misc::getTbs($uid, $bduss);
echo "tbs = {$tbs}\n";

// 先拿帖子标题确定贴吧名
$json = _test_call_json_api($tid, $bduss, '1', '1', '0');
$fname = $json['forum']['name'] ?? '';
echo "贴吧: {$fname}, 标题: " . ($json['thread']['title'] ?? '?') . ", 总回复: " . ($json['thread']['reply_num'] ?? '?') . "\n";

$fid = misc::getFid($fname);
echo "fid = {$fid}\n";

// 获取最近 20 层
$json = _test_call_json_api($tid, $bduss, '1', '20', '1');
$post_list = $json['post_list'] ?? [];

echo "\n=== 2. 搜索含「我是」的楼层 ===\n";
$target = null;
foreach ($post_list as $post) {
    $content = '';
    if (isset($post['content']) && is_array($post['content'])) {
        foreach ($post['content'] as $c) {
            if (($c['type'] ?? -1) == 0 && isset($c['text'])) $content .= $c['text'];
        }
    }
    $floor  = $post['floor'] ?? '?';
    $uname  = $post['author']['name_show'] ?? $post['author']['name'] ?? '?';
    echo "  楼层 #{$floor} {$uname}: " . mb_substr($content, 0, 40, 'UTF-8') . "\n";

    if (mb_stripos($content, '我是', 0, 'UTF-8') !== false) {
        $target = $post;
        echo "  >>> 命中！\n";
        break;
    }
}

if (!$target) {
    echo "\n未找到含「我是」的楼层\n";
    exit;
}

echo "\n=== 3. 回复楼层 ===";
echo "\n  post_id   = " . $target['id'];
echo "\n  author_id = " . ($target['author']['id'] ?? '?');
echo "\n  floor     = " . ($target['floor'] ?? '?');
echo "\n  username  = " . ($target['author']['name_show'] ?? $target['author']['name'] ?? '?');
echo "\n  回复内容  = 666\n";

$stoken = '';
$result = autoreply_add_post(
    $bduss,
    $stoken,
    $tbs,
    $fname,
    $fid,
    $tid,
    '666',                             // 回复内容
    '贴吧用户',                         // show_name
    (string)$target['id'],             // quote_id
    (string)($target['author']['id'] ?? ''), // reply_uid
    (string)($target['floor'] ?? '')   // floor_num
);

echo "\n=== 4. 结果 ===\n";
echo "success    = " . ($result['success'] ? 'true' : 'false') . "\n";
echo "error_code = " . $result['error_code'] . "\n";
echo "error_msg  = " . $result['error_msg'] . "\n";
echo "need_vcode = " . ($result['need_vcode'] ? 'true' : 'false') . "\n";

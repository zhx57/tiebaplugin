<?php
/**
 * 独立测试脚本 —— 搜索含关键词的楼层并回复 666
 * 不依赖任何框架，直接粘贴到容器终端执行：
 *   php /tmp/test_reply.php
 *
 * 修改下面的三个参数即可
 */
$BDUSS   = 'lZnemhWRVVYZTFGZ1BDajVSNkVjNkpqV3R6ekxDSy1ydW5seXBaZ1kyUEQ0MDlxSVFBQUFBJCQAAAAAAQAAAAEAAADGoosvxubIy8vXysA2AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMNWKGrDVihqe';
$TID     = '9534834391';
$KEYWORD = '丢了';
$REPLY   = '666';

// ====================== 工具函数 ======================

function http_get($url, $cookie = '', $ua = 'Mozilla/5.0') {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
    ]);
    if ($cookie) curl_setopt($ch, CURLOPT_HTTPHEADER, ["Cookie: $cookie", "User-Agent: $ua"]);
    if (!$cookie) curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    $r = curl_exec($ch); curl_close($ch);
    return $r;
}

function http_post($url, $body, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',
    ]);
    $r = curl_exec($ch); curl_close($ch);
    return $r;
}

// ====================== Protobuf 编码 ======================

function pb_varint($v) {
    $r = ''; $v = (int)$v;
    while ($v >= 0x80) { $r .= chr(($v & 0x7F) | 0x80); $v >>= 7; }
    return $r . chr($v & 0x7F);
}
function pb_tag($n, $w) { return pb_varint(($n << 3) | $w); }
function pb_str($n, $v) { $t = pb_tag($n, 2); $v = (string)$v; return $t . pb_varint(strlen($v)) . $v; }
function pb_int($n, $v) { return pb_tag($n, 0) . pb_varint((int)$v); }
function pb_msg($n, $d) { $t = pb_tag($n, 2); return $t . pb_varint(strlen($d)) . $d; }
function pb_double($n, $v) { return pb_tag($n, 1) . pack('d', (float)$v); }

// ====================== 获取 fid ======================

function get_fid($fname) {
    $html = http_get('https://tieba.baidu.com/f?kw=' . urlencode($fname), 'ka=open');
    if (preg_match('/"forum_id":(\d+)/', $html, $m)) return $m[1];
    if (preg_match('/fid=(\d+)/',         $html, $m)) return $m[1];
    return '';
}

// ====================== 获取 tbs ======================

function get_tbs($bduss) {
    $html = http_get('http://tieba.baidu.com/dc/common/tbs', "BDUSS=$bduss", 'bdtb for Android 12.41.7.1');
    $json = json_decode($html, true);
    return $json['tbs'] ?? '';
}

// ====================== JSON API ======================

function tieba_api($tid, $bduss, $pn, $rn, $r) {
    $secret = 'tiebaclient!!!';
    $st_time = rand(100, 850);
    $st_size = round((mt_rand() / mt_getrandmax() * 8 + 0.4) * $st_time);
    $cuid = 'baidutiebaapp' . rand(10000000, 99999999);
    $params = [
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
    ];
    ksort($params);
    $raw = ''; foreach ($params as $k => $v) $raw .= "$k=$v";
    $params['sign'] = md5($raw . $secret);
    $body = ''; foreach ($params as $k => $v) { if ($body !== '') $body .= '&'; $body .= "$k=" . urlencode($v); }
    $resp = http_post('http://c.tieba.baidu.com/c/f/pb/page', $body, [
        'User-Agent: bdtb for Android 12.41.7.1',
        'Cookie: ka=open; BDUSS=' . $bduss,
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    return $resp ? json_decode($resp, true) : null;
}

// ====================== 发帖 API ======================

function add_post($bduss, $tbs, $fid, $fname, $tid, $content, $quote_id = '', $reply_uid = '', $floor_num = '', $sub_post_id = '') {
    // 构造 CommonReq
    $ts = (int)(microtime(true) * 1000);
    $event_day = date('Y') . (int)date('n') . (int)date('j');
    $install_ts = $ts - 86400 * 30;
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff),
        random_int(0,0x0fff)|0x4000,random_int(0,0x3fff)|0x8000,
        random_int(0,0xffff),random_int(0,0xffff),random_int(0,0xffff));
    $cuid = 'baidutiebaapp' . $uuid;
    $cuid_galaxy2 = strtoupper(bin2hex(random_bytes(16))) . '|' . strtoupper(substr(str_replace(['+','/','='],'',base64_encode(random_bytes(8))),0,9));
    $c3_aid = 'A00-' . strtoupper(bin2hex(random_bytes(16))) . '-' . strtoupper(substr(str_replace(['+','/','='],'',base64_encode(random_bytes(8))),0,8));
    $android_id = bin2hex(random_bytes(8));
    $sample_id = strtoupper(substr(str_replace(['+','/','='],'',base64_encode(random_bytes(12))),0,16));

    $common = '';
    $common .= pb_int(1, 2);
    $common .= pb_str(2, '12.35.1.0');
    $common .= pb_str(3, $cuid);
    $common .= pb_str(5, '000000000000000');
    $common .= pb_str(6, '1008621x');
    $common .= pb_str(7, $cuid_galaxy2);
    $common .= pb_int(8, $ts);
    $common .= pb_str(9, 'SM-G988N');
    $common .= pb_str(10, $bduss);
    $common .= pb_str(11, $tbs);
    $common .= pb_int(12, 1);
    $common .= pb_str(24, '1.0.3');
    $common .= pb_str(25, '9');
    $common .= pb_str(26, 'samsung');
    $common .= pb_str(28, '3.0.0');
    $common .= pb_str(29, '');
    $common .= pb_str(30, '');
    $common .= pb_str(31, '');
    $common .= pb_str(32, $cuid_galaxy2);
    $common .= pb_str(33, '');
    $common .= pb_str(35, $c3_aid);
    $common .= pb_str(36, $sample_id);
    $common .= pb_int(37, 720);
    $common .= pb_int(38, 1280);
    $common .= pb_double(39, 1.5);
    $common .= pb_int(40, 0);
    $common .= pb_int(41, 0);
    $common .= pb_str(42, '2.34.0');
    $common .= pb_str(43, '3340042');
    $common .= pb_str(44, '1038000');
    $common .= pb_int(49, $install_ts);
    $common .= pb_int(50, $install_ts);
    $common .= pb_int(51, $install_ts);
    $common .= pb_str(53, $event_day);
    $common .= pb_str(54, $android_id);
    $common .= pb_int(55, 1);
    $common .= pb_str(56, '');
    $common .= pb_int(57, 1);
    $common .= pb_str(60, '0');
    $common .= pb_str(61, '');
    $common .= pb_str(62, 'aiotieba/1.0');
    $common .= pb_int(63, 1);
    $common .= pb_str(70, '0.4');

    // 构造 DataReq
    $data  = pb_msg(1, $common);
    $data .= pb_str(6, '1');
    $data .= pb_str(7, '0');
    $data .= pb_str(8, '0');
    $data .= pb_str(9, '0');
    $data .= pb_str(10, '0');
    $data .= pb_str(16, '12');
    $data .= pb_str(18, '1');
    $data .= pb_str(19, $content);
    $data .= pb_str(26, (string)$fid);
    $data .= pb_str(28, '');
    $data .= pb_str(29, '');
    $data .= pb_str(30, $fname);
    $data .= pb_str(31, '0');
    $data .= pb_str(44, (string)$fid);
    $data .= pb_str(45, (string)$tid);
    if (!empty($sub_post_id)) {
        // 楼中楼模式: sub_post_id 非空
        $data .= pb_str(20, $reply_uid);
        $data .= pb_str(46, $sub_post_id);
        $data .= pb_str(48, $floor_num);
        $data .= pb_str(49, $sub_post_id);
        $data .= pb_str(50, $sub_post_id);
        // field 55 not encoded for subpost mode
    } elseif (!empty($quote_id)) {
        // 楼层回复模式
        $data .= pb_str(20, $reply_uid);
        $data .= pb_str(46, $quote_id);
        $data .= pb_str(48, $floor_num);
        $data .= pb_str(49, $quote_id);
    }
    $data .= pb_str(51, '0');
    if (!empty($sub_post_id)) {
        // field 55 NOT encoded
    } elseif (!empty($quote_id)) {
        $data .= pb_str(55, '0');
    } else {
        $data .= pb_str(55, '3');
    }
    $data .= pb_str(58, '贴吧用户');
    $data .= pb_str(60, '0');
    $data .= pb_int(64, 0);
    $data .= pb_int(67, 0);

    $req = pb_msg(1, $data);

    // multipart/form-data
    $boundary = '-*_r1999';
    $body  = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"data\"; filename=\"file\"\r\n";
    $body .= "\r\n";
    $body .= $req;
    $body .= "\r\n--$boundary--\r\n";

    $resp = http_post('https://tiebac.baidu.com/c/c/post/add?cmd=309731', $body, [
        "Content-Type: multipart/form-data; boundary=$boundary",
        'User-Agent: aiotieba/1.0',
        'x_bd_data_type: protobuf',
        'Accept-Encoding: gzip',
        "Cookie: BDUSS=$bduss",
    ]);

    // 简单解析响应
    $success = (strpos($resp, "\x08\x00") !== false) || (strlen($resp) < 100 && $resp !== '');
    $errMsg  = '';
    if (preg_match('/errmsg.*?([\x{4e00}-\x{9fff}]+)/u', $resp, $m)) $errMsg = $m[1];
    return ['success' => $success, 'raw_len' => strlen($resp), 'errmsg' => $errMsg];
}

// ====================== 主流程 ======================

echo "=== 1. 获取帖子信息 ===\n";
$bduss = $BDUSS; $tid = $TID;

$json = tieba_api($tid, $bduss, '1', '1', '0');
if (!$json) { echo "API 请求失败\n"; exit(1); }
$fname = $json['forum']['name'] ?? '';
$reply_num = $json['thread']['reply_num'] ?? 0;
echo "贴吧: {$fname}  回复数: {$reply_num}\n";

$fid = get_fid($fname);
echo "fid: {$fid}\n";

$tbs = get_tbs($bduss);
echo "tbs: {$tbs}\n";

echo "\n=== 2. 搜索含「{$KEYWORD}」的楼层 ===\n";
$json = tieba_api($tid, $bduss, '1', '20', '0');
$post_list = $json['post_list'] ?? [];
$target = null;
foreach ($post_list as $post) {
    $content = '';
    if (isset($post['content']) && is_array($post['content']))
        foreach ($post['content'] as $c)
            if (($c['type'] ?? -1) == 0 && isset($c['text'])) $content .= $c['text'];
    $uname = $post['author']['name_show'] ?? $post['author']['name'] ?? '?';
    echo "  #{$post['floor']} {$uname}: " . mb_substr($content, 0, 40, 'UTF-8') . "\n";
    if (mb_stripos($content, $KEYWORD, 0, 'UTF-8') !== false) {
        $target = $post;
        echo "  >>> 命中!\n";
        break;
    }
}
if (!$target) { echo "未找到含「{$KEYWORD}」的楼层\n"; exit; }

echo "\n=== 3. 回复楼层 ===\n";
$qid = (string)$target['id'];
$ruid = (string)($target['author']['id'] ?? '');
$fnum = (string)($target['floor'] ?? '');
$uname = $target['author']['name_show'] ?? $target['author']['name'] ?? '';
$portrait = $target['author']['portrait'] ?? '';
// 楼中楼格式: 回复 #(reply, portrait, username) :内容
$reply_content = "回复 #(reply, {$portrait}, {$uname}) :{$REPLY}";
echo "  post_id={$qid}  author_id={$ruid}  floor={$fnum}\n";
echo "  内容: {$reply_content}\n";

// 楼中楼回复: sub_post_id = 楼层 post id
$result = add_post($bduss, $tbs, $fid, $fname, $tid, $reply_content, $qid, $ruid, $fnum, $qid);

echo "\n=== 4. 结果 ===\n";
echo "  success=" . ($result['success'] ? 'true' : 'false') . "\n";
echo "  raw_len={$result['raw_len']}\n";
if ($result['errmsg']) echo "  errmsg={$result['errmsg']}\n";

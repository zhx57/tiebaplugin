<?php
if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

require_once __DIR__ . '/autoreply_protobuf.php';

/**
 * Generate random device identifier fields
 * Returns an array with cuid, cuid_galaxy2, c3_aid
 */
function autoreply_generate_device_ids()
{
    $android_id = bin2hex(random_bytes(8));

    // UUID v4
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        (random_int(0, 0x0fff) | 0x4000),
        (random_int(0, 0x3fff) | 0x8000),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );

    $cuid = 'baidutiebaapp' . $uuid;

    // cuid_galaxy2: 32 uppercase hex + "|" + 9 uppercase alphanumeric
    $cuid_galaxy2 = strtoupper(bin2hex(random_bytes(16))) . '|' .
        strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(8))), 0, 9));

    // c3_aid: "A00-" + 32 uppercase hex/alpha + "-" + 8 uppercase alphanumeric
    $c3_aid = 'A00-' . strtoupper(bin2hex(random_bytes(16))) . '-' .
        strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(8))), 0, 8));

    $sample_id = strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 16));

    return array(
        'cuid'          => $cuid,
        'cuid_galaxy2'  => $cuid_galaxy2,
        'c3_aid'        => $c3_aid,
        'android_id'    => $android_id,
        'z_id'          => '',
        'sample_id'     => $sample_id,
    );
}

/**
 * Build the AddPostReqIdl protobuf binary
 */
function autoreply_build_post_proto($bduss, $stoken, $tbs, $fname, $fid, $tid, $content, $show_name, $quote_id = '', $reply_uid = '', $floor_num = '', $sub_post_id = '')
{
    $p = 'AutoreplyProtobuf';
    $dev = autoreply_generate_device_ids();
    $timestamp = (int)(microtime(true) * 1000);
    $event_day = date('Y') . (int)date('n') . (int)date('j');
    $install_time = $timestamp - 86400 * 30;

    $common = '';
    $common .= $p::encodeInt32(1, 2);
    $common .= $p::encodeString(2, '12.35.1.0');
    $common .= $p::encodeString(3, $dev['cuid']);
    $common .= $p::encodeString(5, '000000000000000');
    $common .= $p::encodeString(6, '1020031h');
    $common .= $p::encodeString(7, $dev['cuid_galaxy2']);
    $common .= $p::encodeInt64(8, $timestamp);
    $common .= $p::encodeString(9, 'SM-G988N');
    $common .= $p::encodeString(10, $bduss);
    $common .= $p::encodeString(11, $tbs);
    $common .= $p::encodeInt32(12, 1);
    $common .= $p::encodeString(24, '1.0.3');
    $common .= $p::encodeString(25, '9');
    $common .= $p::encodeString(26, 'samsung');
    $common .= $p::encodeString(28, '3.0.0');
    $common .= $p::encodeString(29, '');
    $common .= $p::encodeString(30, $stoken);
    $common .= $p::encodeString(31, $dev['z_id']);
    $common .= $p::encodeString(32, $dev['cuid_galaxy2']);
    $common .= $p::encodeString(33, '');
    $common .= $p::encodeString(34, '');
    $common .= $p::encodeString(35, $dev['c3_aid']);
    $common .= $p::encodeString(36, $dev['sample_id']);
    $common .= $p::encodeInt32(37, 720);
    $common .= $p::encodeInt32(38, 1280);
    $common .= $p::encodeDouble(39, 1.5);
    $common .= $p::encodeInt32(40, 0);
    $common .= $p::encodeInt32(41, 0);
    $common .= $p::encodeString(42, '2.34.0');
    $common .= $p::encodeString(43, '3340042');
    $common .= $p::encodeString(44, '1038000');
    $common .= $p::encodeInt64(49, $install_time);
    $common .= $p::encodeInt64(50, $install_time);
    $common .= $p::encodeInt64(51, $install_time);
    $common .= $p::encodeString(53, $event_day);
    $common .= $p::encodeString(54, $dev['android_id']);
    $common .= $p::encodeInt32(55, 1);
    $common .= $p::encodeString(56, '');
    $common .= $p::encodeInt32(57, 1);
    $common .= $p::encodeString(60, '0');
    $common .= $p::encodeString(61, '');
    $common .= $p::encodeString(62, 'tieba/12.35.1.0');
    $common .= $p::encodeInt32(63, 1);
    $common .= $p::encodeString(70, '0.4');

    $data = '';
    $data .= $p::encodeMessage(1, $common);
    $data .= $p::encodeString(6, '1');         // anonymous
    $data .= $p::encodeString(7, '0');         // can_no_forum
    $data .= $p::encodeString(8, '0');         // is_feedback
    $data .= $p::encodeString(9, '0');         // takephoto_num
    $data .= $p::encodeString(10, '0');        // entrance_type
    $data .= $p::encodeString(16, '12');       // vcode_tag
    $data .= $p::encodeString(18, '1');        // new_vcode
    $data .= $p::encodeString(19, $content);   // content
    $data .= $p::encodeString(26, (string)$fid);  // fid
    // field 28 v_fid 和 field 29 v_fname 仅在 quote_id 为空时编码
    if (empty($quote_id)) {
        $data .= $p::encodeString(28, '');     // v_fid
        $data .= $p::encodeString(29, '');     // v_fname
    }
    $data .= $p::encodeString(30, $fname);     // kw
    $data .= $p::encodeString(31, '0');        // is_barrage
    // field 32 barrage_time 仅在 quote_id 为空时编码
    if (empty($quote_id)) {
        $data .= $p::encodeString(32, '0');    // barrage_time
    }
    $data .= $p::encodeString(45, (string)$tid);  // tid
    // field 46 quote_id, 49 repostid 仅在 quote_id 非空时编码
    if (!empty($quote_id)) {
        $data .= $p::encodeString(46, $quote_id);   // quote_id
    }
    $data .= $p::encodeString(47, '0');        // is_twzhibo_thread
    $data .= $p::encodeString(48, (string)$floor_num);  // floor_num 被回复的楼层号
    if (!empty($quote_id)) {
        $data .= $p::encodeString(49, $quote_id);   // repostid
    }
    // field 50 sub_post_id 仅在 sub_post_id 非空时编码
    if (!empty($sub_post_id)) {
        $data .= $p::encodeString(50, $sub_post_id);  // sub_post_id
    }
    $data .= $p::encodeString(51, '0');        // is_ad
    $data .= $p::encodeString(52, '0');        // is_addition
    $data .= $p::encodeString(53, '0');        // is_giftpost
    // field 55 post_from: 主题="13", 楼层="0", 楼中楼=不编码
    if (empty($quote_id) && empty($sub_post_id)) {
        $data .= $p::encodeString(55, '13');   // post_from 主题回复
    } elseif (empty($sub_post_id)) {
        $data .= $p::encodeString(55, '0');    // post_from 楼层回复
    }
    // sub_post_id 非空时不编码 field 55
    $data .= $p::encodeString(58, $show_name); // name_show
    $data .= $p::encodeString(60, '0');        // is_pictxt
    // field 20 reply_uid 仅在 quote_id 非空且 reply_uid 非空时编码
    if (!empty($quote_id) && !empty($reply_uid)) {
        $data .= $p::encodeString(20, $reply_uid);  // reply_uid
    }
    $data .= $p::encodeInt32(64, 0);           // show_custom_figure
    $data .= $p::encodeInt32(67, 0);           // is_show_bless

    $req = $p::encodeMessage(1, $data);

    return $req;
}

/**
 * Simple protobuf response parser
 * Extracts error.errorno, error.errmsg, and data.info.need_vcode
 */
function autoreply_parse_response($binary)
{
    $errorno = 0;
    $errmsg = '';
    $need_vcode = false;
    $raw_debug = bin2hex(substr($binary, 0, 128));

    $pos = 0;
    $len = strlen($binary);

    while ($pos < $len) {
        $result = autoreply_read_varint($binary, $pos);
        if ($result === false) {
            break;
        }
        list($tag, $newPos) = $result;
        $pos = $newPos;

        $field_number = $tag >> 3;
        $wire_type = $tag & 0x07;

        if ($wire_type == 0) {
            $result = autoreply_read_varint($binary, $pos);
            if ($result === false) {
                break;
            }
            $pos = $result[1];
        } elseif ($wire_type == 1) {
            if ($pos + 8 > $len) {
                break;
            }
            $pos += 8;
        } elseif ($wire_type == 2) {
            $result = autoreply_read_varint($binary, $pos);
            if ($result === false) {
                break;
            }
            $length = (int)$result[0];
            $pos = $result[1];

            $subdata = substr($binary, $pos, $length);

            if ($field_number == 1) {
                // Error message
                $err = autoreply_parse_error($subdata);
                $errorno = $err['errorno'];
                $errmsg = $err['errmsg'];
            } elseif ($field_number == 2) {
                // DataRes message
                $need_vcode = autoreply_parse_need_vcode($subdata);
            }

            $pos += $length;
        } elseif ($wire_type == 5) {
            if ($pos + 4 > $len) {
                break;
            }
            $pos += 4;
        } else {
            break;
        }
    }

    return array(
        'errorno'    => $errorno,
        'errmsg'     => $errmsg,
        'need_vcode' => $need_vcode,
        'raw_debug'  => $raw_debug,
    );
}

/**
 * Parse Error submessage: field 1 = errorno (varint), field 2 = errmsg (string)
 */
function autoreply_parse_error($data)
{
    $errorno = 0;
    $errmsg = '';

    $pos = 0;
    $len = strlen($data);

    while ($pos < $len) {
        $result = autoreply_read_varint($data, $pos);
        if ($result === false) {
            break;
        }
        list($tag, $newPos) = $result;
        $pos = $newPos;

        $field_number = $tag >> 3;
        $wire_type = $tag & 0x07;

        if ($wire_type == 0 && $field_number == 1) {
            $result = autoreply_read_varint($data, $pos);
            if ($result === false) {
                break;
            }
            $errorno = (int)$result[0];
            $pos = $result[1];
        } elseif ($wire_type == 2 && $field_number == 2) {
            $result = autoreply_read_varint($data, $pos);
            if ($result === false) {
                break;
            }
            $length = (int)$result[0];
            $pos = $result[1];
            $errmsg = substr($data, $pos, $length);
            $pos += $length;
        } else {
            $newPos = autoreply_skip_field($data, $pos, $wire_type);
            if ($newPos === false) {
                break;
            }
            $pos = $newPos;
        }
    }

    return array('errorno' => $errorno, 'errmsg' => $errmsg);
}

/**
 * Parse DataRes message to find PostAntiInfo.info.need_vcode
 * Looks for field 14 (info submessage), then field 3 (need_vcode string) within it
 */
function autoreply_parse_need_vcode($data)
{
    $need_vcode = false;

    $pos = 0;
    $len = strlen($data);

    while ($pos < $len) {
        $result = autoreply_read_varint($data, $pos);
        if ($result === false) {
            break;
        }
        list($tag, $newPos) = $result;
        $pos = $newPos;

        $field_number = $tag >> 3;
        $wire_type = $tag & 0x07;

        if ($wire_type == 0) {
            // Skip varint
            $result = autoreply_read_varint($data, $pos);
            if ($result === false) {
                break;
            }
            $pos = $result[1];
        } elseif ($wire_type == 2) {
            $result = autoreply_read_varint($data, $pos);
            if ($result === false) {
                break;
            }
            $length = (int)$result[0];
            $pos = $result[1];

            if ($field_number == 14) {
                // PostAntiInfo message
                $subdata = substr($data, $pos, $length);
                $spos = 0;
                $slen = strlen($subdata);

                while ($spos < $slen) {
                    $sresult = autoreply_read_varint($subdata, $spos);
                    if ($sresult === false) {
                        break;
                    }
                    list($stag, $snewPos) = $sresult;
                    $spos = $snewPos;

                    $sfield = $stag >> 3;
                    $swire = $stag & 0x07;

                    if ($swire == 2 && $sfield == 3) {
                        $sresult = autoreply_read_varint($subdata, $spos);
                        if ($sresult !== false) {
                            $slen2 = (int)$sresult[0];
                            $spos = $sresult[1];
                            $need_vcode_value = substr($subdata, $spos, $slen2);
                            $need_vcode = ((int)$need_vcode_value) !== 0;
                        }
                        break;
                    } elseif ($swire == 0) {
                        $sresult = autoreply_read_varint($subdata, $spos);
                        if ($sresult === false) {
                            break;
                        }
                        $spos = $sresult[1];
                    } elseif ($swire == 2) {
                        $sresult = autoreply_read_varint($subdata, $spos);
                        if ($sresult === false) {
                            break;
                        }
                        $spos = $sresult[1] + (int)$sresult[0];
                    } else {
                        break;
                    }
                }
            }

            $pos += $length;
        } else {
            break;
        }
    }

    return $need_vcode;
}

function autoreply_skip_field($data, $pos, $wire_type)
{
    if ($wire_type == 0) {
        $result = autoreply_read_varint($data, $pos);
        if ($result === false) {
            return false;
        }
        return $result[1];
    }

    if ($wire_type == 1) {
        return $pos + 8;
    }

    if ($wire_type == 2) {
        $result = autoreply_read_varint($data, $pos);
        if ($result === false) {
            return false;
        }
        return $result[1] + (int)$result[0];
    }

    if ($wire_type == 5) {
        return $pos + 4;
    }

    return false;
}

/**
 * Read a protobuf varint from binary data starting at $pos
 * Returns array(value, newPos) or false on failure
 */
function autoreply_read_varint($data, $pos)
{
    $value = 0;
    $shift = 0;
    $len = strlen($data);

    while ($pos < $len) {
        $byte = ord($data[$pos]);
        $pos++;
        $value |= ($byte & 0x7F) << $shift;
        if (($byte & 0x80) === 0) {
            return array($value, $pos);
        }
        $shift += 7;
    }

    return false;
}

/**
 * Call the Baidu Tieba add_post API
 *
 * @param string $bduss     User BDUSS cookie
 * @param string $stoken    User STOKEN
 * @param string $tbs       Anti-CSRF token
 * @param string $fname     Forum name (tieba name)
 * @param int    $fid       Forum ID
 * @param int    $tid       Thread ID
 * @param string $content   Post content
 * @param string $show_name Display name for the post (shown in thread list)
 * @param string $quote_id  Quote post ID for floor reply (empty = new floor)
 * @param string $reply_uid UID of the user being replied to
 * @param string $floor_num Floor number being replied to
 * @param string $sub_post_id Sub-post ID for 楼中楼 reply (empty = not subpost)
 * @return array ['success' => bool, 'error_code' => int, 'error_msg' => string, 'need_vcode' => bool, 'raw_debug' => string]
 */
function autoreply_add_post($bduss, $stoken, $tbs, $fname, $fid, $tid, $content, $show_name, $quote_id = '', $reply_uid = '', $floor_num = '', $sub_post_id = '')
{
    // 1. Build the protobuf binary
    $proto_binary = autoreply_build_post_proto($bduss, $stoken, $tbs, $fname, $fid, $tid, $content, $show_name, $quote_id, $reply_uid, $floor_num, $sub_post_id);

    // 2. Build multipart/form-data body
    $boundary = '-*_r1999';
    $body = '--' . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="data"; filename="file"' . "\r\n";
    $body .= "\r\n";
    $body .= $proto_binary;
    $body .= "\r\n";
    $body .= '--' . $boundary . '--' . "\r\n";

    // 3. Set up curl
    $url = 'https://tiebac.baidu.com/c/c/post/add?cmd=309731';

    $headers = array(
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'User-Agent: tieba/12.35.1.0',
        'x_bd_data_type: protobuf',
        'Accept-Encoding: gzip',
        'Connection: keep-alive',
        'Cookie: BDUSS=' . $bduss . '; STOKEN=' . $stoken . ';',
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // 4. Handle curl errors
    if ($response === false) {
        return array(
            'success'    => false,
            'error_code' => -1,
            'error_msg'  => 'CURL Error: ' . $curl_error,
            'need_vcode' => false,
            'raw_debug'  => '',
        );
    }

    if ($http_code < 200 || $http_code >= 300) {
        return array(
            'success'    => false,
            'error_code' => $http_code,
            'error_msg'  => 'HTTP Error: ' . $http_code,
            'need_vcode' => false,
            'raw_debug'  => bin2hex(substr((string)$response, 0, 128)),
        );
    }

    // 5. Parse the protobuf response
    $parsed = autoreply_parse_response($response);

    $error_code = $parsed['errorno'];
    $error_msg  = $parsed['errmsg'];
    $need_vcode = $parsed['need_vcode'];

    $success = ($error_code === 0);

    return array(
        'success'    => $success,
        'error_code' => $error_code,
        'error_msg'  => $error_msg,
        'need_vcode' => $need_vcode,
        'raw_debug'  => $parsed['raw_debug'],
    );
}

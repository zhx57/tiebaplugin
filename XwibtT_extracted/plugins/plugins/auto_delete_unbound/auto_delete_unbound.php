<?php
/*
Plugin Name: Auto Delete Unbound Users
Version: 1.0
Plugin URL:
Description: 注册3小时后未绑定百度账号的用户将被自动删除
For: 5.0
Author: Trae
Author URL:
*/

if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

/**
 * 记录新注册用户的注册时间
 * 通过 admin_reg_3（自助注册）和 admin_users_add（管理员添加）钩子触发
 */
function auto_delete_unbound_record_reg_time() {
    global $m;
    $uid = (int)$m->insert_id();
    if ($uid > 0) {
        option::uset('reg_time', time(), $uid);
    }
}

addAction('admin_reg_3', 'auto_delete_unbound_record_reg_time');
addAction('admin_users_add', 'auto_delete_unbound_record_reg_time');

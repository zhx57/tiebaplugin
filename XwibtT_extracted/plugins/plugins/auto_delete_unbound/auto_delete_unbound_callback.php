<?php
if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

/**
 * 插件安装回调
 * 无需建表，使用现有的 users_options 表存储注册时间
 */
function callback_install() {
    // No tables needed
}

/**
 * 插件激活回调
 * 注册计划任务，每5分钟执行一次清理
 */
function callback_init() {
    cron::set('auto_delete_unbound', 'plugins/auto_delete_unbound/cron/cleanup.php', 0, 0, 300);
}

/**
 * 插件禁用回调
 * 移除计划任务
 */
function callback_inactive() {
    cron::del('auto_delete_unbound');
}

/**
 * 插件卸载回调
 * 移除计划任务并清除所有用户的注册时间记录
 */
function callback_remove() {
    global $m;
    cron::del('auto_delete_unbound');
    $m->query("DELETE FROM `" . DB_NAME . "`.`" . DB_PREFIX . "users_options` WHERE `name` = 'reg_time'");
}

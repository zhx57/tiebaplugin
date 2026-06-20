<?php

if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

/**
 * 安装插件时会被调用
 */
function callback_install()
{
    weltolk_autoreply_ensure_table();
}

function weltolk_autoreply_ensure_table()
{
    global $m;
    $table = DB_PREFIX . 'weltolk_autoreply_tasks';

    $m->query('
        CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'weltolk_autoreply_tasks` (
         `id` int NOT NULL AUTO_INCREMENT,
         `uid` int NOT NULL,
         `pid` int NOT NULL DEFAULT 0,
         `fname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
         `tid` bigint NOT NULL,
         `last_floor` int DEFAULT 0,
         `last_reply_time` int DEFAULT 0,
         `last_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT \'\',
         `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
         `last_check_time` int DEFAULT 0,
         `reply_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
         `reply_interval` int DEFAULT 300,
         `reply_probability` int DEFAULT 100,
         `enabled` tinyint DEFAULT 1,
         `retry_count` int DEFAULT 0,
         `trigger_mode` varchar(20) NOT NULL DEFAULT \'new_floor\',
         `reply_target` varchar(20) NOT NULL DEFAULT \'floor\',
         `allow_replied` tinyint(1) NOT NULL DEFAULT 0,
         `match_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
         PRIMARY KEY (`id`)
        ) CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ');

    $columns = array();
    $result = $m->query('SHOW COLUMNS FROM `' . $table . '`');
    while ($row = $m->fetch_array($result)) {
        $columns[$row['Field']] = true;
    }

    $fields = array(
        'pid' => "ALTER TABLE `{$table}` ADD `pid` int NOT NULL DEFAULT 0 AFTER `uid`",
        'last_status' => "ALTER TABLE `{$table}` ADD `last_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '' AFTER `last_reply_time`",
        'last_error' => "ALTER TABLE `{$table}` ADD `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci AFTER `last_status`",
        'last_check_time' => "ALTER TABLE `{$table}` ADD `last_check_time` int DEFAULT 0 AFTER `last_error`",
        'log' => "ALTER TABLE `{$table}` ADD `log` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci AFTER `last_check_time`",
        'last_replied_pid' => "ALTER TABLE `{$table}` ADD `last_replied_pid` bigint NOT NULL DEFAULT 0 AFTER `last_floor`",
        'trigger_mode' => "ALTER TABLE `{$table}` ADD `trigger_mode` varchar(20) NOT NULL DEFAULT 'new_floor'",
        'reply_target' => "ALTER TABLE `{$table}` ADD `reply_target` varchar(20) NOT NULL DEFAULT 'floor' AFTER `trigger_mode`",
        'allow_replied' => "ALTER TABLE `{$table}` ADD `allow_replied` tinyint(1) NOT NULL DEFAULT 0 AFTER `reply_target`",
        'match_keywords' => "ALTER TABLE `{$table}` ADD `match_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci",
    );

    foreach ($fields as $field => $sql) {
        if (!isset($columns[$field])) {
            $m->query($sql);
        }
    }
}

/**
 * 激活插件时会被调用
 */
function callback_init()
{
    weltolk_autoreply_ensure_table();
    option::set('weltolk_autoreply_limit', 5);
    cron::set('weltolk_autoreply', 'plugins/weltolk_autoreply/cron/check_and_reply.php', 0, 0, 60);
}

/**
 * 禁用插件时会被调用
 */
function callback_inactive()
{
    cron::del('weltolk_autoreply');
}

/**
 * 卸载插件时会被调用
 * 卸载插件前，如果插件是激活的，会自动禁用并调用 callback_inactive()
 */
function callback_remove()
{
    global $m;
    $m->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "weltolk_autoreply_tasks`");
}

/**
 * 升级插件时会被调用
 * 系统会传入当前数据库的版本号、当前插件文件中说明的版本号
 * 必须有返回值，如果返回新的版本号，新版本号由系统记录到数据库；如果返回false，将终止操作且不记录到数据库
 */
function callback_update($ver1, $ver2)
{
    //ver1 是当前数据库的版本号
    //ver2 是当前插件文件中说明的版本号，即 插件名_desc.php 的 ['plugin']['version'] 的值
    weltolk_autoreply_ensure_table();
    return $ver2;
}

/**
 * 插件自定义保存设置函数
 * 插件调用方法：setting.php?mod=setplugin:插件名称
 * 然后系统会调用 插件名_callback.php 的 callback_setting()
 */
function callback_setting()
{
}

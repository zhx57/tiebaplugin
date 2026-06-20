<?php
if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

function cron_auto_delete_unbound() {
    global $m;

    $threshold = time() - 10800; // 3 hours = 10800 seconds

    // Step 1: Collect candidate UIDs
    // Find users whose registration time exceeds 3 hours
    $candidates = array();
    $result = $m->query(
        "SELECT `uid` FROM `" . DB_NAME . "`.`" . DB_PREFIX . "users_options` " .
        "WHERE `name` = 'reg_time' AND `value` < {$threshold}"
    );

    while ($row = $m->fetch_array($result)) {
        $uid = (int)$row['uid'];

        // Skip admin users — never auto-delete administrators
        $user = $m->once_fetch_array(
            "SELECT `role` FROM `" . DB_NAME . "`.`" . DB_PREFIX . "users` " .
            "WHERE `id` = {$uid} LIMIT 1"
        );
        if (empty($user) || $user['role'] === 'admin') {
            continue;
        }

        // Skip users who have already bound at least one Baidu account
        $baidu = $m->once_fetch_array(
            "SELECT COUNT(*) AS c FROM `" . DB_NAME . "`.`" . DB_PREFIX . "baiduid` " .
            "WHERE `uid` = {$uid} LIMIT 1"
        );
        if (!empty($baidu) && $baidu['c'] > 0) {
            continue;
        }

        $candidates[] = $uid;
    }

    // Step 2: Delete all candidate users
    // DeleteUser() handles: CleanUser (tieba data) + option::udel (user options) + users table
    // We additionally clean up orphaned baiduid records
    foreach ($candidates as $uid) {
        DeleteUser($uid);
        $m->query(
            "DELETE FROM `" . DB_NAME . "`.`" . DB_PREFIX . "baiduid` " .
            "WHERE `uid` = {$uid}"
        );
    }
}

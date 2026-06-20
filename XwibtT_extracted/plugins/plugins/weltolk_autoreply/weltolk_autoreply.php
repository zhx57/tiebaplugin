<?php

if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}

function weltolk_autoreply_nav()
{
    echo '<li ';
    if (isset($_GET['plugin']) && $_GET['plugin'] == 'weltolk_autoreply') {
        echo 'class="active"';
    }
    echo '><a href="index.php?plugin=weltolk_autoreply"><span class="glyphicon glyphicon-comment"></span> 自动回帖</a></li>';
}

addAction('navi_1', 'weltolk_autoreply_nav');
addAction('navi_7', 'weltolk_autoreply_nav');

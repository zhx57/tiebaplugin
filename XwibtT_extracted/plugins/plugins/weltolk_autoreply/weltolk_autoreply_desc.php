<?php

if (!defined('SYSTEM_ROOT')) {
    die('Insufficient Permissions');
}
//没有标注必填的，都是选填，但是按照规范，请保留该键，只需将值留空即可
return array(
    'plugin' => array(
        'name'        => '自动回帖',            //插件名称，必填
        'version'     => '1.0',                 //插件版本号
        'description' => '监控指定贴吧帖子新回复并自动回帖',  //插件描述
        'onsale'      =>  false,                 //bool 插件是否已在产品中心上架
        'url'         => '',  //插件地址，比如哪里可以下载到这个插件
        'for'         => 'v4.98+',                 //适用的云签到版本，all为所有版本，版本后面跟+表示适用于该版本或更高版本，如V4.0+
        'forphp'      => 'all'                  //适用的PHP版本，如果定义了，系统就在安装和激活时进行版本对比，如果版本低于forphp，自动禁止下一步操作，all为所有版本
    ),
    'author' => array(
        'author'      => 'Weltolk',            //作者名称
        'email'       => '',   //作者邮箱
        'url'         => ''   //作者的个人网站
    ),
    'view'   => array(
        //以下设置均只影响插件列表页面是否有对应按钮
        'setting'     => true,  //bool 插件是否有设置页面，必填
        'show'        => true,  //bool 插件是否有展示页面，必填
        'vip'         => false, //bool 插件是否有只给VIP看的页面，必填
        'private'     => false, //bool 插件是否有只给管理员看的页面，必填
        'public'      => false, //bool 插件是否有给任何人（包括未登录的）看的页面，必填
        'update'      => false, //bool 插件如果有新版本，是否在插件列表页面显示升级按钮
    ),
    'page'   => array(
        //规定插件有哪些自定义页面，不需要自定义页面可留空
    )
);

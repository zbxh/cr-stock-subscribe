<?php
$base='addon\\cr_stock_subscribe\\controller\\AdminController::';
return [['title'=>'auth_plugin_stock_subscribe','url'=>'index','description'=>'补货订阅','parent'=>'auth_product','child'=>[
    ['title'=>'auth_stock_subscribe_view','url'=>'index','description'=>'查看商品、订阅和需求统计','auth_rule'=>[$base.'products',$base.'subscriptions']],
    ['title'=>'auth_stock_subscribe_setting','url'=>'index','description'=>'管理设置、适用商品、商家群及个人地址','auth_rule'=>[$base.'products',$base.'settings',$base.'saveSettings',$base.'saveProducts',$base.'endpoints',$base.'saveEndpoint',$base.'deleteEndpoint',$base.'testEndpoint',$base.'personalEndpoints',$base.'disablePersonalEndpoint',$base.'enablePersonalEndpoint']],
    ['title'=>'auth_stock_subscribe_broadcast','url'=>'index','description'=>'预览和发送补货群发','auth_rule'=>[$base.'products',$base.'preview',$base.'broadcast']],
    ['title'=>'auth_stock_subscribe_log','url'=>'index','description'=>'查看通知记录','auth_rule'=>[$base.'records']],
    ['title'=>'auth_stock_subscribe_review','url'=>'index','description'=>'登记结果未知通知的人工核对','auth_rule'=>[$base.'review']],
    ['title'=>'auth_stock_subscribe_manage','url'=>'index','description'=>'取消订阅及重试明确失败的通知','auth_rule'=>[$base.'cancel',$base.'retry']],
]]];

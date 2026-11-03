<?php
use think\facade\Route;
require_once WEB_ROOT . 'plugins/addon/cr_stock_subscribe/bootstrap.php';

$client=[['GET','entry','entry'],['GET','products','products'],['GET','subscriptions','subscriptions'],['POST','subscriptions','save'],['POST','subscriptions/:id/cancel','cancel'],['GET','endpoints','endpoints'],['POST','endpoints','saveEndpoint'],['DELETE','endpoints/:id','deleteEndpoint'],['POST','endpoints/:id/test','testEndpoint'],['GET','records','records']];
$admin=[['GET','personal_endpoints','personalEndpoints'],['POST','personal_endpoints/:id/disable','disablePersonalEndpoint'],['POST','personal_endpoints/:id/enable','enablePersonalEndpoint'],['GET','settings','settings'],['POST','settings','saveSettings'],['GET','products','products'],['POST','products','saveProducts'],['GET','subscriptions','subscriptions'],['POST','subscriptions/:id/cancel','cancel'],['GET','endpoints','endpoints'],['POST','endpoints','saveEndpoint'],['DELETE','endpoints/:id','deleteEndpoint'],['POST','endpoints/:id/test','testEndpoint'],['POST','broadcast/preview','preview'],['POST','broadcast','broadcast'],['GET','records','records'],['POST','records/:id/retry','retry'],['POST','records/:id/review','review']];
foreach ([['console/v1',$client,'clientarea\\IndexController',\app\http\middleware\CheckHome::class], [DIR_ADMIN . '/v1',$admin,'AdminController',\app\http\middleware\CheckAdmin::class]] as $group) {
    [$prefix,$routes,$controller,$middleware]=$group;
    Route::group($prefix,function () use ($routes,$controller) {
        foreach ($routes as [$method,$path,$action]) {
            Route::rule('stock_subscribe/' . $path,'\\addon\\cr_stock_subscribe\\controller\\' . $controller . '@' . $action,$method)->append(['_plugin'=>'cr_stock_subscribe','_controller'=>$controller==='AdminController' ? 'admin' : 'index','_action'=>parse_name($action)]);
        }
    })->middleware($middleware)->middleware(\app\http\middleware\ParamFilter::class);
}

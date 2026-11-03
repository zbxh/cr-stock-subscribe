<?php
// This loader is deliberately limited to this independent plugin.
spl_autoload_register(static function ($class) {
    $prefix = 'addon\\cr_stock_subscribe\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) { require_once $file; }
});

// V10 has no status callback for addons. Its ORM event runs in the normal
// status transaction, even on the request that disables this plugin's hooks.
\think\facade\Event::listen('model.app\\admin\\model\\PluginModel.BeforeUpdate',static function ($model) {
    if ($model->name === 'CrStockSubscribe' && $model->module === 'addon' && array_key_exists('status',$model->getChangedData())) {
        \addon\cr_stock_subscribe\logic\Engine::stop('插件启停后重新建立库存基线');
    }
});

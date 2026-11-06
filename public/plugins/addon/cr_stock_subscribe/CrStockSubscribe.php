<?php
namespace addon\cr_stock_subscribe;

require_once __DIR__ . '/bootstrap.php';

use app\common\lib\Plugin;
use addon\cr_stock_subscribe\logic\Store;
use addon\cr_stock_subscribe\logic\Schema;
use addon\cr_stock_subscribe\logic\Engine;
use addon\cr_stock_subscribe\logic\Worker;
use addon\cr_stock_subscribe\logic\Notice;

class CrStockSubscribe extends Plugin
{
    public $info=['name'=>'CrStockSubscribe','title'=>'补货订阅','description'=>'按商品库存门槛订阅补货，支持邮件、短信、个人及商家群 Webhook、多商品合并和后台群发。作者 QQ：793305720，交流群：603107399。','author'=>'Cr','author_url'=>'https://wpa.qq.com/msgrd?v=3&uin=793305720&site=qq&menu=yes','version'=>Store::VERSION];
    public function CrStockSubscribeidcsmartauthorize() {}
    public function install()
    {
        Schema::install();
        \think\facade\Event::listen('after_plugin_install',[$this,'afterPluginInstall']);
        return true;
    }
    public function afterPluginInstall($p)
    {
        if (($p['name'] ?? '')==='CrStockSubscribe') { Schema::syncHooks(); }
    }
    public function upgrade()
    {
        \addon\cr_stock_subscribe\logic\Keyring::preflight();
        // V10 upgrades run inside a transaction; defer all DDL until it commits.
        \think\facade\Event::listen('after_plugin_upgrade',[$this,'afterPluginUpgrade']); return true;
    }
    public function afterPluginUpgrade($p)
    {
        if (($p['name'] ?? '')==='CrStockSubscribe') { Schema::install(); Schema::syncHooks(); }
    }
    public function uninstall()
    {
        Engine::stop('插件已卸载');
        Store::audit('plugin_uninstalled'); return true;
    }
    public function minuteCron() { (new Engine())->cron(); }
    public function taskRun($data)
    {
        if (($data['type'] ?? '')!==Worker::TYPE) { return null; }
        $p=Store::decode($data['task_data'] ?? []);
        return (new Worker())->run((int)($p['delivery_id'] ?? 0),(int)($p['attempt'] ?? 0),(string)($p['queue_token'] ?? ''));
    }
    public function afterProductEdit($p) { $this->dirty($p); }
    public function afterProductCreate($p) { $this->dirty($p); }
    public function afterProductDelete($p) { $this->dirty($p); }
    public function afterOrderCreate($p) { $this->observeOrder($p); }
    public function afterOrderCancel($p) { $this->observeOrder($p); }
    private function observeOrder($p) : void
    {
        $id=(int)($p['id'] ?? 0);
        if (!$id) { return; }
        try {
            if (!Store::active()) { return; }
            $ids=Store::table('product')->alias('r')->join('order_item i','i.product_id=r.product_id')->where('i.order_id',$id)->where('r.enabled',1)->distinct(true)->column('r.product_id');
            // The native creation hook may run inside its order transaction.
            // Stock and plugin observations then commit or roll back together.
            // Observe the deduction now so a fast 4 -> 0 -> 4 cannot hide zero
            // from the minute scan. No notification is sent from these hooks.
            foreach ($ids as $productId) { $this->dirty(['id'=>(int)$productId]); }
        } catch (\Throwable $error) { \addon\cr_stock_subscribe\logic\Diagnostics::fault('order_stock',$id,$error); }
    }
    public function afterHostDelete($p) { $this->dirty(['id'=>(int)($p['product_id'] ?? 0)]); }
    public function afterHostTerminateSuccess($p)
    {
        $id=(int)($p['id'] ?? 0);
        if (!$id) { return; }
        try {
            if (!Store::active()) { return; }
            $productId=(int)\think\facade\Db::name('host')->where('id',$id)->value('product_id');
            if ($productId) { $this->dirty(['id'=>$productId]); }
        } catch (\Throwable $error) { \addon\cr_stock_subscribe\logic\Diagnostics::fault('host_stock',$id,$error); }
    }
    private function dirty($p) : void
    {
        $id=(int)($p['id'] ?? $p['product_id'] ?? 0);
        if (!$id || !Store::active()) { return; }
        try {
            (new Engine())->observeProduct($id,true);
        } catch (\Throwable $e) { error_log('cr_stock_subscribe: product observation deferred to cron'); }
    }
    public function afterUpstreamProductUpdate($p)
    {
        $id=(int)($p['id'] ?? 0);
        if ($id) { Store::table('product')->where('product_id',$id)->update(['fresh_at'=>time(),'checked_at'=>0]); $this->dirty(['id'=>$id]); }
    }
    public function cartViewAppend($p)
    {
        if (!Store::active() || !in_array(strtolower((string)($p['view_html'] ?? '')),['goodslist','goodslist.php','goods','goods.php'],true)) { return []; }
        return ['body'=>'<script src="/plugins/addon/cr_stock_subscribe/template/entry.js?v=0.1.1&amp;rev=20261103d" data-member-url="' . Store::memberPath() . '" data-stock-cart="1"></script>'];
    }
    public function clientareaViewAppend($p)
    {
        if (!Store::active()) { return []; }
        return ['body'=>'<script src="/plugins/addon/cr_stock_subscribe/template/entry.js?v=0.1.1&amp;rev=20261103d" data-member-url="' . Store::memberPath() . '"></script>'];
    }
    public function appendSendParam()
    {
        $params=[]; foreach (Notice::parameters() as $value=>$label) { $params[]=['value'=>$value,'label'=>$label]; }
        return ['label'=>'补货订阅','param'=>$params];
    }
    public function appendSendParamByAction()
    {
        $out=[]; foreach (Notice::actions() as $name=>$a) { $out[$name]=['groups'=>['system','client'],'extra'=>array_keys(Notice::parameters())]; } return $out;
    }
}

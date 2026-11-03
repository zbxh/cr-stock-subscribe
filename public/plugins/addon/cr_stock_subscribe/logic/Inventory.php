<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Inventory
{
    public function read(int $id) : array
    {
        try {
            $p = Db::name('product')->where('id',$id)->where('product_id',0)->field('id,name,product_group_id,hidden,stock_control,qty,sync_stock,price')->find();
            return $this->describe($p,static function ($groupId) {
                return Db::name('product_group')->where('id',$groupId)->field('id,hidden,parent_id')->find();
            },static function () use ($p,$id) {
                if (empty($p['sync_stock']) || !Db::name('upstream_product')->where('product_id',$id)->count()) { return true; }
                $fresh=(int)Store::table('product')->where('product_id',$id)->value('fresh_at');
                return $fresh && $fresh >= time() - Settings::get()['upstream_fresh_seconds'];
            });
        } catch (\Throwable $e) { return ['state'=>'unknown','reason'=>'暂时无法读取商品库存']; }
    }
    // The catalog reuses the same stock rules without querying each product's
    // groups, upstream mapping and freshness separately.
    public function readMany(array $products, array $groups) : array
    {
        $result=[]; $groupMap=[];
        foreach ($groups as $group) { $groupMap[(int)$group['id']]=$group; }
        try {
            $ids=array_column($products,'id');
            $upstream=$ids ? array_fill_keys(Db::name('upstream_product')->whereIn('product_id',$ids)->column('product_id'),true) : [];
            $cutoff=time()-Settings::get()['upstream_fresh_seconds'];
            foreach ($products as $p) {
                $result[(int)$p['id']]=$this->describe($p,static function ($id) use ($groupMap) { return $groupMap[$id] ?? null; },static function () use ($p,$upstream,$cutoff) {
                    return empty($p['sync_stock']) || !isset($upstream[$p['id']]) || (!empty($p['fresh_at']) && (int)$p['fresh_at'] >= $cutoff);
                });
            }
        } catch (\Throwable $e) {
            foreach ($products as $p) { $result[(int)$p['id']]=['state'=>'unknown','reason'=>'暂时无法读取商品库存']; }
        }
        return $result;
    }
    private function describe(?array $p, callable $groupById, callable $isFresh) : array
    {
        if (!$p || !empty($p['hidden'])) { return ['state'=>'blocked','reason'=>'商品已隐藏或删除']; }
        $groupId=(int)$p['product_group_id']; $seen=[];
        while ($groupId) {
            if (isset($seen[$groupId]) || count($seen)>5) { return ['state'=>'unknown','reason'=>'商品分类无法确认']; }
            $seen[$groupId]=true; $group=$groupById($groupId);
            if (!$group || !empty($group['hidden'])) { return ['state'=>'blocked','reason'=>'商品分类已隐藏或删除']; }
            $groupId=(int)$group['parent_id'];
        }
        if (empty($p['stock_control'])) { return ['state'=>'blocked','reason'=>'商品未启用数量库存控制']; }
        if (!is_numeric($p['qty']) || (int)$p['qty']<0) { return ['state'=>'unknown','reason'=>'库存数量无法确认']; }
        if (!$isFresh()) { return ['state'=>'unknown','reason'=>'等待 V10 上游库存同步确认']; }
        return ['state'=>'known','product'=>$p];
    }
    public function observe(int $id, bool $force = false) : array
    {
        return Store::transaction(function () use ($id,$force) {
            $rule = Store::table('product')->where('product_id',$id)->lock(true)->find();
            if (!$rule || !$rule['enabled']) { return ['changed'=>false,'state'=>'blocked']; }
            if (!$force && (int)$rule['checked_at'] > time() - Settings::get()['check_seconds']) { return ['changed'=>false,'state'=>'cached','rule'=>$rule]; }
            $read = $this->read($id); $now = time();
            if ($read['state'] !== 'known') {
                Store::table('product')->where('product_id',$id)->update(['known'=>0,'checked_at'=>$now,'reason'=>$read['reason']]);
                Store::table('item')->where('product_id',$id)->where('event_type','restock')->whereIn('status',Engine::OPEN)->update(['status'=>'cancelled','reason'=>'等待期间库存无法确认，停止本次复核']);
                return $read + ['changed'=>false,'rule'=>$rule];
            }
            $qty = (int)$read['product']['qty']; $cycle = (int)$rule['cycle'];
            $baseline = !(int)$rule['known'];
            if ($baseline) { Store::table('item')->where('product_id',$id)->where('event_type','restock')->where('status','deferred')->update(['status'=>'cancelled','reason'=>'重新建立库存基线，停止旧补货复核']); }
            // Unknown/re-enabled stock creates a baseline, never a new sold-out
            // round. Only an observed, reliable zero can re-arm subscribers.
            if (!$baseline && (int)$rule['qty'] === 0 && $qty > 0) { $cycle++; }
            $changed = !$baseline && $qty > (int)$rule['qty'];
            Store::table('product')->where('product_id',$id)->update(['known'=>1,'qty'=>$qty,'cycle'=>$cycle,'checked_at'=>$now,'reason'=>'']);
            $rule = array_replace($rule,['known'=>1,'qty'=>$qty,'cycle'=>$cycle,'checked_at'=>$now]);
            return $read + ['changed'=>$changed,'baseline'=>$baseline,'rule'=>$rule];
        });
    }
    public function configure(array $ids, bool $enabled, int $admin, string $fingerprint = '') : void
    {
        Store::transaction(function () use ($ids,$enabled,$admin,$fingerprint) {
            if ($fingerprint!=='' && !hash_equals((new Inspector())->productPreview($ids,$enabled)['fingerprint'],$fingerprint)) { throw new \DomainException('商品状态或影响范围已变化，请重新预览并确认。'); }
            foreach ($ids as $id) {
                if (!Db::name('product')->where('id',$id)->count()) { throw new \DomainException('所选商品不存在。'); }
                $old = Store::table('product')->where('product_id',$id)->lock(true)->find();
                if (!$old) { Store::table('product')->insert(['product_id'=>$id,'enabled'=>(int)$enabled]); }
                elseif ((int)$old['enabled'] !== (int)$enabled) { Store::table('product')->where('product_id',$id)->update(['enabled'=>(int)$enabled,'known'=>0,'checked_at'=>0]); }
                if (!$enabled) { Engine::cancelProduct($id,'商家已关闭此商品订阅'); }
                elseif (!$old || !$old['enabled']) { (new Availability())->baseline($id); $this->observe($id,true); }
            }
            Store::audit('products_configured',['product_ids'=>$ids,'enabled'=>$enabled],$admin);
        });
    }
}

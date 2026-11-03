<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

// Product visibility has its own episodes: neither zero stock nor an unreliable
// upstream quantity means a product was delisted. Names survive core deletion.
final class Availability
{
    public function read(int $id) : array
    {
        try {
            $p=Db::name('product')->where('id',$id)->where('product_id',0)->field('id,name,hidden,product_group_id')->find();
            return $this->describe($p,static function ($id) { return Db::name('product_group')->where('id',$id)->field('id,hidden,parent_id')->find(); });
        } catch (\Throwable $e) { return ['state'=>'unknown','product'=>null]; }
    }
    public function readMany(array $products, array $groups) : array
    {
        $map=[]; $result=[];
        foreach ($groups as $group) { $map[(int)$group['id']]=$group; }
        foreach ($products as $id=>$product) { $result[$id]=$this->describe($product,static function ($id) use ($map) { return $map[$id] ?? null; }); }
        return $result;
    }
    private function describe(?array $product, callable $groupById) : array
    {
        if (!$product) { return ['state'=>'deleted','product'=>null]; }
        if (!empty($product['hidden'])) { return ['state'=>'hidden','product'=>$product]; }
        $id=(int)($product['product_group_id'] ?? 0); $seen=[];
        while ($id) {
            if (isset($seen[$id]) || count($seen)>5) { return ['state'=>'unknown','product'=>$product]; }
            $seen[$id]=true; $group=$groupById($id);
            if (!$group || !empty($group['hidden'])) { return ['state'=>'hidden','product'=>$product]; }
            $id=(int)$group['parent_id'];
        }
        return ['state'=>'listed','product'=>$product];
    }
    public static function seed() : void
    {
        $reader=new self();
        foreach (Store::table('product')->column('product_id') as $id) {
            if (!Store::table('availability')->where('product_id',$id)->count()) { $reader->baseline((int)$id); }
        }
    }
    public function baseline(int $id) : void
    {
        $read=$this->read($id); $old=Store::table('availability')->where('product_id',$id)->find();
        $data=['state'=>$read['state'],'name'=>$read['product']['name'] ?? ($old['name'] ?? '商品 #' . $id),'checked_at'=>time(),'changed_at'=>time()];
        if ($old) { Store::table('availability')->where('product_id',$id)->update($data); }
        else { Store::table('availability')->insert($data+['product_id'=>$id]); }
    }
    public function observe(int $id) : array
    {
        $rule=Store::table('product')->where('product_id',$id)->find();
        if (!$rule || !$rule['enabled']) { return []; }
        $read=$this->read($id);
        // A failed database read must never manufacture a deletion event.
        if ($read['state']==='unknown') { return []; }
        $old=Store::table('availability')->where('product_id',$id)->lock(true)->find();
        if (!$old || $old['state']==='unknown') { $this->baseline($id); return []; }
        $down=in_array($read['state'],['hidden','deleted'],true);
        $wasDown=in_array($old['state'],['hidden','deleted'],true);
        $delisted=$down && !$wasDown;
        $data=['state'=>$read['state'],'name'=>$read['product']['name'] ?? $old['name'],'episode'=>(int)$old['episode']+($delisted ? 1 : 0),'checked_at'=>time()];
        if ($read['state']!==$old['state']) {
            $data['changed_at']=time();
            if ($down || $wasDown) { Store::table('product')->where('product_id',$id)->update(['known'=>0,'checked_at'=>0]); }
        }
        if ($down) {
            Store::table('item')->where('product_id',$id)->where('event_type','restock')->whereIn('status',Engine::OPEN)->update(['status'=>'cancelled','reason'=>'商品已下架，停止补货提醒']);
        } elseif ($wasDown) {
            Store::table('item')->where('product_id',$id)->where('event_type','delisted')->whereIn('status',Engine::OPEN)->update(['status'=>'cancelled','reason'=>'商品已恢复上架，取消下架通知']);
        }
        Store::table('availability')->where('product_id',$id)->update($data);
        return $data+['product_id'=>$id,'delisted'=>$delisted];
    }
}

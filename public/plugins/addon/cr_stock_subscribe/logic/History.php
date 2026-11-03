<?php
namespace addon\cr_stock_subscribe\logic;

/** Keep a delivery's item results after a logical reminder is regrouped. */
final class History
{
    // Logical archive: keep all snapshots, attempts, SMS reservations, review
    // and deduplication facts. Unknown results and live work never qualify.
    public static function archive() : int
    {
        $days=(int)Settings::get()['history_archive_days'];
        if(!$days) { return 0; }
        return Store::transaction(static function () use ($days) {
            $ids=Store::table('delivery')->where('archived_at',0)->whereIn('status',['accepted','cancelled','expired','skipped','exhausted'])->where('created_at','<',time()-$days*86400)->order('created_at')->limit(200)->column('id');
            if(!$ids) { return 0; }
            $count=Store::table('delivery')->whereIn('id',$ids)->where('archived_at',0)->update(['archived_at'=>time()]);
            Store::audit('history_archived',['items'=>$count]);
            return $count;
        });
    }
    public static function capture(int $deliveryId) : void
    {
        if (!$deliveryId) { return; }
        foreach (Store::table('item')->where('delivery_id',$deliveryId)->select()->toArray() as $item) {
            $data=array_intersect_key($item,array_flip(['product_id','event_type','channel','endpoint_id','source','status','reason','accepted_at']));
            $key=['delivery_id'=>$deliveryId,'item_id'=>(int)$item['id']];
            if (Store::table('delivery_item')->where($key)->count()) { Store::table('delivery_item')->where($key)->update($data); }
            else { Store::table('delivery_item')->insert($key+$data); }
        }
    }
}

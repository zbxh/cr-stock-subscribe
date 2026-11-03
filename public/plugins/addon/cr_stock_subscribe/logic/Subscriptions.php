<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Subscriptions
{
    public function save(int $clientId, array $input, bool $checkRevision = false) : array
    {
        Store::requireActive(); $ids=Store::ids($input['product_ids'] ?? []);
        if (!$ids) { throw new \DomainException('请选择需要订阅的商品。'); }
        $threshold=Store::number($input['threshold'] ?? 3,0,100000000,'库存门槛');
        $mode=(string)($input['mode'] ?? 'once'); if (!in_array($mode,['once','continuous'],true)) { throw new \DomainException('提醒模式不正确。'); }
        $channels=$input['channels'] ?? []; if (!is_array($channels) || !$channels || array_diff($channels,['email','sms','webhook'])) { throw new \DomainException('请选择可用的通知渠道。'); }
        $channels=array_values(array_unique($channels)); $endpoints=Store::ids($input['endpoint_ids'] ?? [],10);
        if($checkRevision && isset($input['revisions']) && !is_array($input['revisions'])) { throw new \DomainException('订阅版本格式不正确，请重新打开编辑。'); }
        $client=Notice::client($clientId); $settings=Settings::get();
        foreach ($channels as $ch) { $reason=Notice::usable($ch,$client,0); if ($reason !== '') { throw new \DomainException($reason); } }
        if (in_array('webhook',$channels,true)) {
            if (!$endpoints || Store::table('endpoint')->whereIn('id',$endpoints)->where('client_id',$clientId)->where('scope','personal')->where('enabled',1)->count() !== count($endpoints)) { throw new \DomainException('请选择本人已启用的个人 Webhook 地址。'); }
        } else { $endpoints=[]; }
        return Store::transaction(function () use ($clientId,$ids,$threshold,$mode,$channels,$endpoints,$settings,$input,$checkRevision) {
            // Serialize subscription limit checks across all products for this customer.
            Db::name('client')->where('id',$clientId)->lock(true)->find();
            $active=Store::table('subscription')->alias('s')->join('product p','p.id=s.product_id')->where('s.client_id',$clientId)->where('s.status','active')->where('s.expires_at','>',time())->count(); $saved=[];
            foreach ($ids as $pid) {
                $rule=Store::table('product')->where('product_id',$pid)->lock(true)->find();
                if (!$rule || !$rule['enabled']) { throw new \DomainException('所选商品尚未开放补货订阅。'); }
                $read=(new Inventory())->read($pid);
                if ($read['state'] !== 'known') { throw new \DomainException($read['reason']); }
                $old=Store::table('subscription')->where('client_id',$clientId)->where('product_id',$pid)->lock(true)->find();
                $isActive=$old && $old['status'] === 'active' && (int)$old['expires_at'] > time();
                if ($checkRevision && (($isActive && !isset($input['revisions'][$pid])) || (isset($input['revisions'][$pid]) && (!$old || Store::number($input['revisions'][$pid],1,2147483647,'订阅版本')!==(int)$old['revision'])))) { throw new \DomainException('订阅已存在或已在其他页面更新。请回到我的订阅，重新打开最新订阅后修改。'); }
                if (!$isActive && (int)$read['product']['qty'] > $threshold) { throw new \DomainException('「' . $read['product']['name'] . '」已满足门槛，可直接购买或提高门槛后订阅。'); }
                if (!$isActive && ++$active > $settings['max_subscriptions']) { throw new \DomainException('有效订阅数量已达到商家设置的上限。'); }
                $row=['client_id'=>$clientId,'product_id'=>$pid,'generation'=>$old ? (int)$old['generation'] + ($isActive ? 0 : 1) : 1,'revision'=>$old ? (int)$old['revision']+1 : 1,'threshold'=>$threshold,'mode'=>$mode,'channels'=>Store::json($channels),'endpoint_ids'=>Store::json($endpoints),'status'=>'active','expires_at'=>$isActive ? (int)$old['expires_at'] : time()+$settings['valid_days']*86400,'updated_at'=>time()];
                if ($old) { $id=(int)$old['id']; Engine::cancelSubscription($id,'订阅设置已修改'); Store::table('subscription')->where('id',$id)->update($row); }
                else { $row['created_at']=time(); $id=Store::table('subscription')->insertGetId($row); }
                $saved[]=(int)$id;
                // Edits may lower a waiting threshold; schedule a fresh comparison.
                if ($isActive && (int)$read['product']['qty'] > $threshold) { (new Engine())->collectProduct($rule,'automatic','',false,[$id]); }
            }
            Store::audit('subscriptions_saved',['product_ids'=>$ids],0,$clientId);
            return ['ids'=>$saved];
        });
    }
    public function cancel(int $clientId, int $id, int $admin = 0) : void
    {
        Store::transaction(function () use ($clientId,$id,$admin) {
            $q=Store::table('subscription')->where('id',$id); if (!$admin) { $q->where('client_id',$clientId); }
            $row=$q->lock(true)->find(); if (!$row) { throw new \DomainException('订阅不存在或无权操作。'); }
            Store::table('subscription')->where('id',$id)->update(['status'=>'cancelled','revision'=>(int)$row['revision']+1,'updated_at'=>time()]);
            Engine::cancelSubscription($id,'订阅已取消'); Store::audit('subscription_cancelled',['subscription_id'=>$id],$admin,(int)$row['client_id']);
        });
    }
    public function listing(int $clientId, array $input = [], bool $admin = false) : array
    {
        if (array_key_exists('diagnose_id',$input)) {
            if (!$admin) { throw new \DomainException('未通知原因仅供后台管理员查看。'); }
            return (new Inspector())->diagnosis($clientId,Filters::id($input,'diagnose_id'),true);
        }
        [$page,$limit]=Store::page($input); $q=Store::table('subscription')->alias('s')->leftJoin('product p','p.id=s.product_id')->leftJoin(Store::PREFIX . 'availability a','a.product_id=s.product_id');
        if (!$admin) { $q->where('s.client_id',$clientId); }
        if ($admin && ($id=Filters::id($input,'client_id'))) { $q->where('s.client_id',$id); }
        if ($id=Filters::id($input,'product_id')) { $q->where('s.product_id',$id); }
        Filters::subscriptionStatus($q,Filters::choice($input,'status',['active','complete','cancelled','expired']));
        if ($mode=Filters::choice($input,'mode',['once','continuous'])) { $q->where('s.mode',$mode); }
        if ($channel=Filters::choice($input,'channel',['email','sms','webhook'])) { $q->whereLike('s.channels','%"'.$channel.'"%'); }
        if ($word=Filters::text($input,'keywords')) {
            $q->where(function ($q) use ($word) {
                $q->whereLike('p.name',Filters::like($word))->whereOr('a.name','like',Filters::like($word));
                if (preg_match('/\A#?([1-9][0-9]{0,9})\z/',$word,$m)) { $q->whereOr('s.id',(int)$m[1])->whereOr('s.product_id',(int)$m[1]); }
            });
        }
        Filters::dates($q,$input,'s.created_at');
        $count=(clone $q)->count();
        $rows=$q->field('s.*,COALESCE(p.name,a.name) product_name,p.hidden product_hidden,p.id live_product_id,p.product_group_id')->order('s.id','desc')->page($page,$limit)->select()->toArray();
        $products=[];
        foreach ($rows as $r) { $products[$r['product_id']]=$r['live_product_id'] ? ['id'=>$r['live_product_id'],'name'=>$r['product_name'],'hidden'=>$r['product_hidden'],'product_group_id'=>$r['product_group_id']] : null; }
        $availability=(new Availability())->readMany($products,$rows ? Db::name('product_group')->field('id,hidden,parent_id')->select()->toArray() : []);
        foreach ($rows as &$r) {
            $r['channels']=Store::decode($r['channels']); $r['endpoint_ids']=Store::decode($r['endpoint_ids']);
            if ($r['status']==='active' && (int)$r['expires_at']<=time()) { $r['status']='expired'; }
            $r['product_name']=$r['product_name'] ?: '商品已删除';
            $r['product_state']=$availability[$r['product_id']]['state'];
            $r['product_exists']=!empty($r['live_product_id']);
            $r['product_delisted']=in_array($r['product_state'],['hidden','deleted'],true); unset($r['live_product_id'],$r['product_hidden']);
            $progress=Store::table('item')->where('subscription_id',$r['id'])->where('generation',$r['generation'])->whereIn('status',Engine::OPEN)->where('expires_at','>',time())
                ->field("COUNT(*) pending_count,MIN(CASE WHEN status='deferred' THEN due_at END) deferred_until,SUM(CASE WHEN status='waiting' THEN 1 ELSE 0 END) holding")->find();
            $r['pending_count']=(int)($progress['pending_count'] ?? 0); $r['deferred_until']=(int)($progress['deferred_until'] ?? 0);
            $r['notification_wait']=$r['deferred_until'] ? ($r['deferred_until']>time() ? '提醒冷却中，结束后自动复核库存' : '等待自动复核库存，达到门槛后提醒') : (!empty($progress['holding']) ? '等待条件恢复，有效期内自动复核' : ($r['pending_count'] ? '已安排提醒，等待合并或发送' : ''));
        }
        return ['list'=>$rows,'count'=>$count];
    }
}

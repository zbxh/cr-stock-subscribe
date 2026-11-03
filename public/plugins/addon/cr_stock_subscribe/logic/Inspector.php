<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Inspector
{
    public function health() : array
    {
        $now=time();$settings=Settings::get();$heartbeat=Store::table('setting')->where('id',2)->find() ?: [];$data=Store::decode($heartbeat['data']??'');
        $last=(int)($heartbeat['updated_at']??0);$limit=max(180,(int)$settings['check_seconds']*3);
        $pending=Store::table('delivery')->whereIn('status',['pending','queued','failed','sending'])->where('expires_at','>',$now);
        $items=Store::table('item')->where('status','pending')->where('expires_at','>',$now);
        $oldest=min(array_filter([(int)(clone $pending)->min('created_at'),(int)(clone $items)->min('created_at')]) ?: [$now]);
        $due=min(array_filter([(int)(clone $pending)->where('next_at','<=',$now)->where('status','<>','sending')->min('created_at'),(int)(clone $items)->where('due_at','<=',$now)->min('due_at')]) ?: [$now]);
        $enabled=Store::active();$state='normal';$message='库存检查近期有运行记录。';
        if (!$enabled) { $state='paused';$message='插件未开放，自动检查和发送已暂停。'; }
        elseif (!$last) { $state='warning';$message='尚无自动检查记录，请确认 V10 每分钟任务正常运行。'; }
        elseif ($last<$now-$limit) { $state='warning';$message='自动检查已超过预期间隔，请检查 V10 定时任务。'; }
        elseif (!empty($data['last_error_at']) && (int)$data['last_error_at']>=(int)($data['last_success_at']??0)) { $state='warning';$message='最近一次检查未完成，请核对系统任务日志。'; }
        elseif ($now-$due>$limit) { $state='warning';$message='存在等待较久的到期任务，请核对 V10 发送队列。'; }
        return ['state'=>$state,'message'=>$message,'last_cron_at'=>$last,'last_success_at'=>(int)($data['last_success_at']??0),'pending_deliveries'=>(int)(clone $pending)->count(),'pending_items'=>(int)(clone $items)->count(),'waiting_items'=>(int)Store::table('item')->where('status','waiting')->where('expires_at','>',$now)->count(),'issue_count'=>(int)($data['issue_count']??0),'issues'=>$data['issues']??[],'deferred_items'=>(int)Store::table('item')->where('status','deferred')->where('expires_at','>',$now)->count(),'oldest_wait_seconds'=>max(0,$now-$oldest),'checked_at'=>$now];
    }
    public function productPreview(array $ids, bool $enabled) : array
    {
        if (!$ids) { throw new \DomainException('请选择需要设置的商品。'); }
        $rows=Db::name('product')->alias('p')->leftJoin(Store::PREFIX.'product r','r.product_id=p.id')->whereIn('p.id',$ids)->field('p.id,p.name,r.enabled')->order('p.id')->select()->toArray();
        if (count($rows)!==count($ids)) { throw new \DomainException('部分商品已不存在，请刷新商品列表后重新选择。'); }
        $changed=0;foreach ($rows as &$r) { $r['id']=(int)$r['id'];$r['enabled']=(int)($r['enabled']??0);$r['changed']=$r['enabled']!==(int)$enabled;if($r['changed'])$changed++; }unset($r);
        $subs=Store::table('subscription')->whereIn('product_id',$ids)->where('status','active')->where('expires_at','>',time());
        $pending=Store::table('item')->whereIn('product_id',$ids)->whereIn('status',Engine::OPEN)->where('expires_at','>',time());
        $result=['product_ids'=>$ids,'enabled'=>(int)$enabled,'products'=>$rows,'count'=>count($rows),'changed'=>$changed,'unchanged'=>count($rows)-$changed,'subscriptions'=>(int)(clone $subs)->count(),'clients'=>(int)(clone $subs)->count('DISTINCT client_id'),'pending_items'=>(int)(clone $pending)->count()];
        $result['fingerprint']=hash('sha256',Store::json($result));
        return $result;
    }
    public function diagnosis(int $client, int $id, bool $admin = false) : array
    {
        if (!$admin) { throw new \DomainException('未通知原因仅供后台管理员查看。'); }
        $q=Store::table('subscription')->where('id',$id);
        $sub=$q->find();if(!$sub)throw new \DomainException('订阅不存在或无权查看。');
        $now=time();$settings=Settings::get();$rule=Store::table('product')->where('product_id',$sub['product_id'])->find() ?: [];
        $read=(new Inventory())->read((int)$sub['product_id']);$known=$read['state']==='known';$product=$read['product']??[];
        $name=(string)Db::name('product')->where('id',$sub['product_id'])->value('name');$state=$sub['status'];if($state==='active' && (int)$sub['expires_at']<=$now)$state='expired';
        $items=Store::table('item')->where('event_type','restock')->where('subscription_id',$id)->where('generation',$sub['generation'])->where('cycle',(int)($rule['cycle']??0))->order('id','desc')->limit(100)->select()->toArray();
        $deliveryIds=array_values(array_unique(array_filter(array_column($items,'delivery_id'))));$deliveries=$deliveryIds ? array_column(Store::table('delivery')->whereIn('id',$deliveryIds)->select()->toArray(),null,'id') : [];
        $account=Notice::client((int)$sub['client_id']);$channels=[];
        foreach(Store::decode($sub['channels']) as $channel) {
            if(!in_array($channel,['email','sms','webhook'],true))continue;
            $reason=Notice::usable($channel,$account,0);$targets=[['id'=>0,'name'=>'']];
            if($channel==='sms' && !$reason) {
                $day=date('Y-m-d');$used=(int)Store::table('sms_budget')->where('day',$day)->where('client_id',$sub['client_id'])->value('reserved');$site=(int)Store::table('sms_budget')->where('day',$day)->where('client_id',0)->value('reserved');
                if($used>=(int)$settings['sms_client_daily'] || $site>=(int)$settings['sms_site_daily'])$reason='短信每日额度不足（包含正在发送的额度占用）';
                $period=(int)$settings['sms_product_seconds'];
                if(!$reason && Store::table('item')->where('client_id',$sub['client_id'])->where('product_id',$sub['product_id'])->where('channel','sms')->where(function($q)use($now,$period){$q->where('accepted_at','>',$now-$period)->whereOr(function($q)use($now,$period){$q->where('status','unknown')->where('attempted_at','>',$now-$period);});})->count())$reason='同商品短信间隔未到';
            }
            if($channel==='webhook') {
                $endpointIds=Store::decode($sub['endpoint_ids']);$targets=[];
                $found=$endpointIds ? array_column(Store::table('endpoint')->whereIn('id',$endpointIds)->where('client_id',$sub['client_id'])->where('scope','personal')->field('id,name,enabled')->select()->toArray(),null,'id') : [];
                foreach($endpointIds as $endpointId)$targets[]=$found[$endpointId]??['id'=>(int)$endpointId,'name'=>'个人地址 #'.(int)$endpointId.'（已删除或不可访问）','enabled'=>0];
                if(!$targets)$targets=[['id'=>0,'name'=>'','enabled'=>0]];
            }
            foreach($targets as $target) {
                $available=$reason;if($channel==='webhook' && empty($target['enabled']))$available=$available ?: '个人 Webhook 地址已删除、停用或未选择';
                $item=null;foreach($items as $candidate)if($candidate['channel']===$channel && (int)$candidate['endpoint_id']===(int)$target['id']){$item=$candidate;break;}
                $progress='none';$progressReason='本轮尚无此渠道的提醒任务';$deliveryId=0;$due=0;
                if($item) {
                    $progress=$item['status'];$progressReason=$item['reason'];$due=(int)$item['due_at'];$deliveryId=(int)$item['delivery_id'];
                    if($progress==='batched' && isset($deliveries[$deliveryId])){$delivery=$deliveries[$deliveryId];$progress=$delivery['status'];$progressReason=$delivery['reason'];$due=(int)$delivery['next_at'];}
                    if(in_array($progress,['deferred','waiting','pending','batched','queued','failed'],true) && (int)$item['expires_at']<=$now){$progress='expired';$progressReason='提醒已超过有效期，不再发送';}
                    if($progress==='deferred')$progressReason=$due>$now ? '提醒冷却中，到期后自动复核库存' : '等待自动复核当前库存';
                    if($progress==='waiting')$progressReason=$item['reason'].'；复核不会延长原有效期';
                    if($progress==='pending' && !$progressReason)$progressReason=$due>$now ? '等待合并窗口结束' : '等待自动合并或发送';
                    if($progress==='accepted')$progressReason='发送接口已受理，不代表客户已阅读或保证送达';
                    if($progress==='unknown')$progressReason=$progressReason ?: '发送结果需要核对，不会自动重复发送';
                    if(in_array($progress,['waiting','pending','batched','queued','failed','deferred'],true)) {
                        $probe=$item;$probe['status']='pending';$cache=[(int)$sub['product_id']=>[$read,$rule]];
                        [$valid,$currentReason]=(new Worker())->valid($probe,$cache);
                        if(!$valid)$progressReason=$currentReason.'；实际发送前会再次核对';
                    }
                }
                $channels[]=['key'=>$channel.':'.$target['id'],'channel'=>$channel,'endpoint_name'=>(string)$target['name'],'available'=>$available==='','availability_reason'=>$available,'status'=>$progress,'reason'=>$progressReason,'delivery_id'=>$deliveryId,'due_at'=>$due];
            }
        }
        $met=$known && (int)$product['qty']>(int)$sub['threshold'];$summary='本轮尚无提醒任务';$tone='info';
        if(!Store::active()){$summary='补货订阅已暂停，尚未发送的提醒不会继续发送';$tone='warning';}
        elseif($state!=='active'){$summary=['cancelled'=>'订阅已取消','expired'=>'订阅已过期','complete'=>'本次订阅已完成一次提醒'][$state]??'订阅当前不可用';}
        elseif(empty($rule['enabled'])){$summary='商家已关闭此商品的补货订阅';$tone='warning';}
        elseif(!$known){$summary=$read['reason']??'当前库存无法确认';$tone='warning';}
        elseif(!$met){$summary='当前库存未超过订阅门槛，继续等待补货';}
        else {
            $progress=array_column($channels,'status');
            if(array_intersect($progress,['deferred','waiting','pending','batched','queued','sending','failed']))$summary='本轮已安排提醒，请查看各渠道的等待或处理状态';
            elseif(in_array('unknown',$progress,true)){$summary='本轮存在结果待核对的发送，不会自动重发';$tone='warning';}
            elseif(in_array('accepted',$progress,true))$summary='本轮已有渠道被发送接口受理，库存波动不会重复提醒';
            elseif(array_intersect($progress,['skipped','cancelled','expired','exhausted']))$summary='本轮提醒已停止或跳过，请查看各渠道的具体原因';
            elseif(!array_filter($channels,static function($c){return $c['available'];})){$summary='所选通知渠道当前均不可用，请查看下方原因';$tone='warning';}
            elseif(empty($rule['known']) || !$rule['checked_at'])$summary='等待库存检查建立基线，已有库存不会作为新补货推送';
            elseif((int)$rule['qty']!==(int)$product['qty'])$summary='当前库存已变化，等待自动检查确认补货条件';
            else $summary='当前库存满足门槛，但本轮尚无提醒任务；首次开放或恢复时的基线库存不补发';
        }
        $result=['id'=>(int)$sub['id'],'product_id'=>(int)$sub['product_id'],'product_name'=>$name ?: (Store::table('availability')->where('product_id',$sub['product_id'])->value('name') ?: '商品已删除'),'status'=>$state,'threshold'=>(int)$sub['threshold'],'mode'=>$sub['mode'],'inventory_state'=>$read['state'],'inventory_reason'=>$read['reason']??'','threshold_met'=>$met,'cycle'=>(int)($rule['cycle']??0),'last_checked_at'=>(int)($rule['checked_at']??0),'last_notified_at'=>(int)$sub['last_notified_at'],'cooldown_until'=>$sub['last_notified_at'] ? (int)$sub['last_notified_at']+(int)$settings['cooldown_seconds'] : 0,'summary'=>$summary,'theme'=>$tone,'channels'=>$channels,'checked_at'=>$now];
        $result['product_exists']=$name!=='';
        if($known && ($admin || $settings['show_quantity']))$result['quantity']=(int)$product['qty'];
        if($admin)$result['client_id']=(int)$sub['client_id'];
        return $result;
    }
}

<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Worker
{
    const TYPE = 'cr_stock_subscribe_delivery';

    public $errors=[];

    public function dispatch() : void
    {
        if (!Store::active()) { return; }
        $stale=Store::table('delivery')->where('status','sending')->where('lease_until','<',time())->column('id');
        foreach ($stale as $id) { $this->isolated((int)$id,function () use ($id) { $this->terminal((int)$id,'unknown','发送进程中断，结果需要核对'); }); }
        // Expiry cleanup does not consume the live send allowance.
        foreach (Store::table('delivery')->whereIn('status',['pending','queued','failed'])->where('expires_at','<=',time())->column('id') as $id) {
            $this->isolated((int)$id,function () use ($id) { $this->terminal((int)$id,'expired','通知已过期'); });
        }
        foreach (Store::table('delivery')->where('status','queued')->select()->toArray() as $d) {
            $this->isolated((int)$d['id'],function () use ($d) {
                $task=Db::name('task')->where('id',$d['task_id'])->find();
                if (!$task || in_array($task['status'],['Failed','Finish'],true)) { $this->terminal((int)$d['id'],'skipped','V10 队列已结束且未执行本次投递，请核对系统任务记录'); }
            });
        }
        // Separate allowances prevent one busy channel from starving another.
        foreach (['email','sms'] as $channel) {
            $ids=Store::table('delivery')->where('channel',$channel)->whereIn('status',['pending','failed'])->where('next_at','<=',time())->where('expires_at','>',time())->order('expires_at')->order('id')->limit((int)Settings::get()[$channel.'_per_minute'])->column('id');
            foreach ($ids as $id) { $this->isolated((int)$id,function () use ($id) { $this->queue((int)$id); }); }
        }
        $started=microtime(true);
        $ids=Store::table('delivery')->where('channel','webhook')->whereIn('status',['pending','failed'])->where('next_at','<=',time())->where('expires_at','>',time())->order('expires_at')->order('id')->limit(30)->column('id');
        foreach ($ids as $id) {
            if (microtime(true)-$started>=25) { break; }
            $this->isolated((int)$id,function () use ($id) { $this->run((int)$id); });
        }
    }

    private function isolated(int $id, callable $work) : void
    {
        try { $work(); } catch (\Throwable $error) { $this->errors[]=Diagnostics::fault('delivery',$id,$error); }
    }

    public function suspend(array $item, string $reason, string $code) : void
    {
        $updates=['status'=>'skipped','reason'=>$reason,'wait_code'=>''];
        if ((int)$item['expires_at']<=time()) {
            $updates=['status'=>'expired','reason'=>'通知已过期','wait_code'=>''];
        } elseif (in_array($code,['stock','configuration'],true)) {
            $updates=['status'=>'waiting','reason'=>$reason.'；有效期内自动复核','wait_code'=>$code,'due_at'=>time()+60];
        }
        Store::table('item')->where('id',$item['id'])->whereIn('status',['pending','batched','waiting'])->update($updates);
    }

    public function resumeWaiting() : void
    {
        $ids=Store::table('item')->where('status','waiting')->where('due_at','<=',time())->order('id')->limit(1000)->column('id');
        foreach ($ids as $id) {
            try { Store::transaction(function () use ($id) {
                $item=Store::table('item')->where('id',$id)->where('status','waiting')->lock(true)->find();
                if (!$item) { return; }
                $cache=[]; $probe=$item; $probe['status']='pending';
                [$valid,$reason,,$code]=$this->valid($probe,$cache);
                if (!$valid) { $this->suspend($item,$reason,$code); return; }
                $limit=$item['channel']==='webhook' ? Settings::get()['webhook_attempts'] : 3;
                if ((int)$item['send_attempts']>=$limit) { Store::table('item')->where('id',$id)->update(['status'=>'exhausted','reason'=>'已达到本次通知尝试次数上限']); return; }
                $due=time()+(int)Settings::get()['merge_seconds']; $batch=$item['batch_key'];
                if ($item['source']==='automatic') {
                    $open=Store::table('item')->where('client_id',$item['client_id'])->where('channel',$item['channel'])->where('endpoint_id',$item['endpoint_id'])->where('receiver_hash',$item['receiver_hash'])->where('event_type',$item['event_type'])->where('source','automatic')->where('status','pending')->where('due_at','>',time())->where('expires_at','>',time())->order('id')->find();
                    $due=$open ? (int)$open['due_at'] : $due; $batch=$open ? $open['batch_key'] : 'a'.bin2hex(random_bytes(12));
                }
                History::capture((int)$item['delivery_id']);
                Store::table('item')->where('id',$id)->update(['status'=>'pending','wait_code'=>'','reason'=>'','delivery_id'=>0,'batch_key'=>$batch,'due_at'=>min($due,(int)$item['expires_at']-1)]);
            }); } catch (\Throwable $error) { $this->errors[]=Diagnostics::fault('recheck',(int)$id,$error); }
        }
    }
    public function queue(int $id) : void
    {
        Store::transaction(function () use ($id) {
            $d=Store::table('delivery')->where('id',$id)->lock(true)->find();
            if (!$d || !in_array($d['status'],['pending','failed'],true) || (int)$d['next_at']>time()) { return; }
            if ((int)$d['expires_at']<=time()) { $this->terminal($id,'expired','通知已过期'); return; }
            // A deferred send keeps its attempt number. Fence old callbacks
            // and distinguish native requeues with a fresh queue token.
            $token=bin2hex(random_bytes(16));
            $data=['delivery_id'=>$id,'attempt'=>(int)$d['attempts']+1,'queue_token'=>$token];
            $r=(new \app\common\model\TaskWaitModel())->createTaskWait(['type'=>self::TYPE,'rel_id'=>$id,'client_id'=>(int)$d['client_id'],'description'=>'补货订阅：' . ($d['channel']==='sms' ? '短信' : '邮件') . '投递 #' . $id,'task_data'=>$data,'notice'=>true]);
            if (($r['status'] ?? 0)!==200) { throw new \RuntimeException('V10 通知任务入队未成功'); }
            $task=Db::name('task')->where('type',self::TYPE)->where('task_data',Store::json($data))->order('id','desc')->value('id');
            if (!$task) { throw new \RuntimeException('V10 通知任务关联失败。'); }
            Store::table('delivery')->where('id',$id)->update(['status'=>'queued','task_id'=>(int)$task,'claim'=>$token,'updated_at'=>time()]);
        });
    }
    public function valid(array $item, array &$cache) : array
    {
        if (!in_array($item['status'],['pending','batched'],true)) { return [false,'订阅任务已取消',null,'stop']; }
        if ((int)$item['expires_at']<=time()) { return [false,'通知已过期',null,'stop']; }
        $pid=(int)$item['product_id'];
        $eventType=$item['event_type'] ?? 'restock';
        if ($eventType==='delisted') {
            $key='delisted:' . $pid;
            if (!isset($cache[$key])) { $cache[$key]=[(new Availability())->read($pid),Store::table('availability')->where('product_id',$pid)->find(),Store::table('product')->where('product_id',$pid)->find()]; }
            [$availability,$lifecycle,$rule]=$cache[$key];
            if (!$rule || !$rule['enabled'] || !$lifecycle || (int)$lifecycle['episode']!==(int)$item['cycle']) { return [false,'商品或下架轮次已变更',null,'stop']; }
            if (!in_array($availability['state'],['hidden','deleted'],true)) { return [false,'商品已恢复上架或状态无法确认',null,'stop']; }
            $product=['id'=>$pid,'name'=>$availability['product']['name'] ?? $lifecycle['name']];
        } else {
            if (!isset($cache[$pid])) { $cache[$pid]=[(new Inventory())->read($pid),Store::table('product')->where('product_id',$pid)->find()]; }
            [$read,$rule]=$cache[$pid];
            if (!$rule || !$rule['enabled'] || (int)$rule['cycle']!==(int)$item['cycle']) { return [false,'商品或补货轮次已变更',null,'stop']; }
            if ($read['state']!=='known' || (int)$read['product']['qty'] <= (int)$item['threshold']) { return [false,'当前库存未满足门槛',null,$read['state']==='known' ? 'stock' : 'stop']; }
            $product=$read['product'];
        }
        if ($item['subscription_id']) {
            $key='subscription:'.$item['subscription_id'];
            if (!array_key_exists($key,$cache)) { $cache[$key]=Store::table('subscription')->where('id',$item['subscription_id'])->find(); }
            $s=$cache[$key];
            if (($eventType==='delisted' && (!$s || $s['status']!=='active')) || !$s || !in_array($s['status'],['active','complete'],true) || ($s['status']==='complete' && $s['mode']!=='once') || (int)$s['generation']!==(int)$item['generation'] || (int)$s['revision']!==(int)$item['subscription_revision'] || (int)$s['expires_at']<=time() || !in_array($item['channel'],Store::decode($s['channels']),true)) { return [false,'订阅已变更或失效',null,'stop']; }
            $key='client:'.$item['client_id'];
            if (!array_key_exists($key,$cache)) { $cache[$key]=Notice::client((int)$item['client_id']); }
            $client=$cache[$key];
            $key='usable:'.$item['client_id'].':'.$item['channel'].':'.$eventType;
            if (!array_key_exists($key,$cache)) { $cache[$key]=Notice::usable($item['channel'],$client,0,$eventType); }
            $reason=$cache[$key];
            if ($reason!=='') { return [false,$reason,null,Notice::recoverable($reason) ? 'configuration' : 'stop']; }
            if ($item['channel']!=='webhook' && !hash_equals($item['receiver_hash'],Notice::hash($client,$item['channel']))) { return [false,'账户接收地址已变更',null,'stop']; }
            if ($item['channel']==='webhook' && !in_array((int)$item['endpoint_id'],Store::decode($s['endpoint_ids']),true)) { return [false,'个人通知地址已取消选择',null,'stop']; }
        }
        if ($item['endpoint_id']) {
            $key='endpoint:'.$item['endpoint_id'];
            if (!array_key_exists($key,$cache)) { $cache[$key]=Store::table('endpoint')->where('id',$item['endpoint_id'])->find(); }
            $e=$cache[$key];
            if (!$e || !$e['enabled'] || (int)$e['revision']!==(int)$item['endpoint_revision'] || !hash_equals($item['receiver_hash'],$e['address_hash']) || (int)$e['client_id']!==(int)$item['client_id'] || ($item['client_id'] ? $e['scope']!=='personal' : $e['scope']!=='group')) { return [false,'Webhook 地址已变更或停用',null,'stop']; }
        }
        if ($item['channel']==='sms') {
            $period=Settings::get()['sms_product_seconds'];
            $prior=Store::table('item')->where('client_id',$item['client_id'])->where('product_id',$item['product_id'])->where('channel','sms')->where('id','<>',(int)($item['id'] ?? 0))->where(function ($q) use ($period) { $q->where('accepted_at','>',time()-$period)->whereOr(function ($q) use ($period) { $q->where('status','unknown')->where('attempted_at','>',time()-$period); }); })->count();
            if ($prior) { return [false,'同商品短信间隔未到',null,'stop']; }
        }
        return [true,'',$product,''];
    }
    public function run(int $id, int $expectedAttempt = 0, string $expectedToken = '') : string
    {
        $prepared=Store::transaction(function () use ($id,$expectedAttempt,$expectedToken) {
            return Notice::catalog(function () use ($id,$expectedAttempt,$expectedToken) {
                $d=Store::table('delivery')->where('id',$id)->lock(true)->find();
                if (!$d || !in_array($d['status'],['pending','queued','failed'],true) || (int)$d['next_at']>time() || ($expectedAttempt && $expectedAttempt!==(int)$d['attempts']+1)) { return null; }
                if ($expectedAttempt && $d['status']==='queued' && $d['claim']!=='' && $expectedToken==='') { return null; }
                if ($expectedToken!=='' && ($d['status']!=='queued' || !hash_equals($d['claim'],$expectedToken))) { return null; }
                if (!Store::active()) { $this->terminal($id,'cancelled','插件已停用'); return null; }
                if ((int)$d['expires_at']<=time()) { $this->terminal($id,'expired','通知已过期'); return null; }
                $limit=$d['channel']==='webhook' ? Settings::get()['webhook_attempts'] : 3;
                if ((int)$d['attempts'] >= $limit) { $this->terminal($id,'exhausted','已达到自动尝试次数上限'); return null; }
                $items=Store::table('item')->where('delivery_id',$id)->order('id')->lock(true)->select()->toArray(); $valid=[]; $products=[]; $cache=[];
                foreach ($items as $item) {
                    [$ok,$reason,$p,$code]=$this->valid($item,$cache);
                    if ($ok) { $valid[]=$item; $products[$p['id']]=$p; }
                    elseif (in_array($item['status'],['pending','batched'],true)) { $this->suspend($item,$reason,$code); }
                }
                if (count($valid)!==count($items) || !$valid) {
                    // Never edit the meaning of an existing queued merged notification.
                    Store::table('delivery')->where('id',$id)->update(['status'=>'cancelled','reason'=>'发送条件变更，已重新合并有效商品','updated_at'=>time()]);
                    History::capture($id);
                    if ($valid) { Store::table('item')->whereIn('id',array_column($valid,'id'))->update(['status'=>'pending','delivery_id'=>0,'due_at'=>time()]); }
                    return null;
                }
                $client=$d['client_id'] ? $cache['client:'.$d['client_id']] : [];
                if ($d['channel']!=='webhook') { $reason=Notice::usable($d['channel'],$client,count($products),$d['event_type']); if ($reason!=='') { if (Notice::recoverable($reason)) { foreach ($valid as $item) { $this->suspend($item,$reason,'configuration'); } History::capture($id); Store::table('delivery')->where('id',$id)->update(['status'=>'cancelled','reason'=>'模板配置待恢复，保留原通知有效期','updated_at'=>time()]); } else { $this->terminal($id,'skipped',$reason); } return null; } }
                $used=max((int)$d['attempts'],max(array_column($valid,'send_attempts')));
                if ($used>=$limit) { $this->terminal($id,'exhausted','已达到本次通知尝试次数上限'); return null; }
                $units=0;
                if ($d['channel']==='sms') {
                    foreach ($valid as $i) {
                        $inFlight=Store::table('item')->alias('i')->join(Store::PREFIX . 'delivery d','d.id=i.delivery_id')->where('i.client_id',$i['client_id'])->where('i.product_id',$i['product_id'])->where('i.channel','sms')->where('d.status','sending')->where('d.id','<>',$id)->count();
                        if ($inFlight) { Store::table('delivery')->where('id',$id)->update(['status'=>'pending','task_id'=>0,'next_at'=>time()+60,'updated_at'=>time()]); return null; }
                    }
                    $units=Notice::smsEstimate(array_values($products),$client,(int)$d['created_at'],$d['event_type']);
                }
                $next=SendRate::reserve($d['channel'],function () use ($d,$units) { return $d['channel']!=='sms' || $this->reserveSms((int)$d['client_id'],$units); });
                if ($next<0) { $this->terminal($id,'skipped','短信每日额度不足'); return null; }
                if ($next>0) {
                    $reason=($d['channel']==='email' ? '邮件' : '短信').'每分钟发送上限已达到，等待后续调度';
                    Store::table('delivery')->where('id',$id)->update(['status'=>'pending','reason'=>$reason,'task_id'=>0,'claim'=>'','lease_until'=>0,'next_at'=>$next,'updated_at'=>time()]);
                    Store::table('item')->whereIn('id',array_column($valid,'id'))->update(['reason'=>$reason,'due_at'=>$next]);
                    History::capture($id);
                    return null;
                }
                $claim=bin2hex(random_bytes(16)); $attempt=$used+1;
                Store::table('item')->whereIn('id',array_column($valid,'id'))->update(['send_attempts'=>$attempt,'attempted_at'=>time(),'reason'=>'']);
                Store::table('delivery')->where('id',$id)->update(['status'=>'sending','reason'=>'','claim'=>$claim,'lease_until'=>time()+60,'attempts'=>$attempt,'sms_units'=>$units,'snapshot'=>Store::json(array_values($products)),'updated_at'=>time()]);
                return ['delivery'=>$d,'claim'=>$claim,'attempt'=>$attempt,'units'=>$units,'client'=>$client,'items'=>$valid,'products'=>array_values($products),'restocked_at'=>min(array_column($valid,'created_at'))];
            });
        });
        if (!$prepared) { return 'Finish'; }
        try { $result=$this->sendPrepared($prepared); }
        catch (\Throwable $e) { $result=['status'=>'unknown','reason'=>'发送进程未确认结果，请核对后再处理']; }
        $this->finish($prepared,$result); return 'Finish';
    }
    protected function sendPrepared(array $p) : array
    {
        $d=$p['delivery'];
        $current=Store::transaction(function () use ($p,$d) {
            // A fresh scope is essential: edits between preparation and this
            // final check must not reuse account, template or endpoint caches.
            return Notice::catalog(function () use ($p,$d) {
                if (!Store::active()) { return false; }
                $cache=[]; $products=[];
                foreach ($p['items'] as $item) {
                    $current=Store::table('item')->where('id',$item['id'])->find();
                    if (!$current) { return false; }
                    [$valid,,$product]=$this->valid($current,$cache);
                    if (!$valid) { return false; }
                    $products[$product['id']]=$product;
                }
                $client=$d['client_id'] ? $cache['client:'.$d['client_id']] : [];
                if ($d['channel']!=='webhook' && Notice::usable($d['channel'],$client,count($products),$d['event_type'])!=='') { return false; }
                Store::table('delivery')->where('id',$p['delivery']['id'])->update(['snapshot'=>Store::json(array_values($products))]);
                return ['products'=>array_values($products),'client'=>$client,'endpoint'=>$d['endpoint_id'] ? $cache['endpoint:'.$d['endpoint_id']] : null];
            });
        });
        if ($current===false) { return ['status'=>'cancelled','reason'=>'实际发送前条件已变化，已重新合并有效商品','rebuild'=>true]; }
        $p['products']=$current['products'];
        if ($d['channel']==='webhook') {
            return (new Webhook())->send($current['endpoint'],$p['products'],$d['event_id'],(int)$p['restocked_at'],$p['items'][0]['source'],$d['event_type']);
        }
        return (new Notice())->send($d['channel'],$current['client'],$p['products'],(int)$p['restocked_at'],$d['event_type'],$d['event_id']);
    }
    protected function reserveSms(int $clientId, int $units) : bool
    {
        $settings=Settings::get(); $day=date('Y-m-d');
        // Stable site -> customer lock ordering, including previously absent counters.
        Store::table('setting')->where('id',1)->lock(true)->find();
        foreach ([0,$clientId] as $owner) { Store::table('sms_budget')->extra('IGNORE')->insert(['day'=>$day,'client_id'=>$owner]); }
        $site=Store::table('sms_budget')->where('day',$day)->where('client_id',0)->lock(true)->find();
        $client=Store::table('sms_budget')->where('day',$day)->where('client_id',$clientId)->lock(true)->find();
        if ((int)$site['reserved']+$units>$settings['sms_site_daily'] || (int)$client['reserved']+$units>$settings['sms_client_daily']) { return false; }
        Store::table('sms_budget')->where('day',$day)->whereIn('client_id',[0,$clientId])->inc('reserved',$units)->update(); return true;
    }
    protected function releaseSms(int $clientId, int $units, string $day) : void
    {
        if (!$units) { return; }
        Store::table('setting')->where('id',1)->lock(true)->find();
        Store::table('sms_budget')->where('day',$day)->whereIn('client_id',[0,$clientId])->dec('reserved',$units)->update();
    }
    protected function finish(array $p, array $result) : void
    {
        Store::transaction(function () use ($p,$result) {
            $id=(int)$p['delivery']['id']; $row=Store::table('delivery')->where('id',$id)->lock(true)->find();
            if (!$row || $row['status']!=='sending' || !hash_equals($row['claim'],$p['claim'])) { return; }
            $status=in_array($result['status'] ?? '',['accepted','failed','unknown','cancelled'],true) ? $result['status'] : 'unknown';
            $reason=Diagnostics::reason((string)($result['reason'] ?? ''));
            Store::table('attempt')->insert(['delivery_id'=>$id,'attempt'=>$p['attempt'],'status'=>$status,'reason'=>$reason,'provider_code'=>Diagnostics::code($result['provider_code'] ?? ''),'http_code'=>(int)($result['http_code'] ?? 0),'sms_units'=>$p['units'],'created_at'=>time()]);
            $limit=$row['channel']==='webhook' ? Settings::get()['webhook_attempts'] : 3;
            $finalStatus=$status==='failed' && $p['attempt'] >= $limit ? 'exhausted' : $status;
            Store::table('delivery')->where('id',$id)->update(['status'=>$finalStatus,'reason'=>$reason,'claim'=>'','lease_until'=>0,'next_at'=>$status==='failed' ? time()+($p['attempt']===1 ? 60 : 300) : 0,'updated_at'=>time()]);
            if ($status!=='accepted' && $status!=='unknown' && $p['units']) { $this->releaseSms((int)$row['client_id'],$p['units'],date('Y-m-d',(int)$row['updated_at'])); }
            if ($status==='accepted') {
                Store::table('item')->whereIn('id',array_column($p['items'],'id'))->update(['status'=>'accepted','accepted_at'=>time(),'reason'=>'']);
                foreach ($p['items'] as $i) {
                    if (!$i['subscription_id'] || ($i['event_type'] ?? 'restock')!=='restock') { continue; }
                    $sub=Store::table('subscription')->where('id',$i['subscription_id'])->lock(true)->find();
                    if (!$sub || (int)$sub['generation']!==(int)$i['generation'] || (int)$sub['revision']!==(int)$i['subscription_revision']) { continue; }
                    $updates=['last_notified_at'=>time(),'updated_at'=>time()];
                    if ($sub['status']==='active' && $sub['mode']==='once') { $updates['status']='complete'; }
                    Store::table('subscription')->where('id',$sub['id'])->update($updates);
                }
            } elseif ($status==='unknown' || $status==='cancelled' || $finalStatus==='exhausted') {
                $rebuild=[];
                if ($status==='cancelled' && !empty($result['rebuild'])) { Store::table('item')->whereIn('id',array_column($p['items'],'id'))->where('send_attempts',$p['attempt'])->update(['send_attempts'=>max(0,$p['attempt']-1)]); }
                if ($status==='cancelled' && !empty($result['rebuild']) && Store::active()) {
                    $cache=[];
                    foreach (Store::table('item')->where('delivery_id',$id)->where('status','batched')->select()->toArray() as $item) {
                        [$valid,$why,,$cause]=$this->valid($item,$cache);
                        if (!$valid) { $this->suspend($item,$why,$cause); }
                        if ($valid) { $rebuild[]=$item['id']; }
                    }
                }
                Store::table('item')->where('delivery_id',$id)->where('status','batched')->update(['status'=>$finalStatus,'reason'=>$reason]);
                History::capture($id);
                if ($rebuild) { Store::table('item')->whereIn('id',$rebuild)->update(['status'=>'pending','delivery_id'=>0,'due_at'=>time(),'reason'=>'']); }
            }
            History::capture($id);
            // An edit while preparing a send cannot reuse an in-flight item.
            // Once we know no provider was called, apply that edit to a fresh
            // comparison without waiting for another stock change.
            if ($status==='cancelled' && !empty($result['rebuild']) && Store::active()) {
                foreach ($p['items'] as $original) {
                    if (!$original['subscription_id'] || ($original['event_type'] ?? 'restock')!=='restock') { continue; }
                    $item=Store::table('item')->where('id',$original['id'])->find();
                    $sub=Store::table('subscription')->where('id',$original['subscription_id'])->find();
                    if (!$item || $item['status']!=='cancelled' || (int)$item['send_attempts'] || !$sub || $sub['status']!=='active' || (int)$sub['generation']!==(int)$original['generation'] || (int)$sub['revision']<=(int)$original['subscription_revision']) { continue; }
                    $rule=Store::table('product')->where('product_id',$original['product_id'])->find();
                    if (!$rule || !$rule['known'] || (int)$rule['cycle']!==(int)$original['cycle']) { continue; }
                    (new Engine())->collectProduct($rule,'automatic','',false,[(int)$sub['id']]);
                }
            }
        });
    }
    public function terminal(int $id, string $status, string $reason) : void
    {
        Store::transaction(static function () use ($id,$status,$reason) {
            $current=Store::table('delivery')->where('id',$id)->find();
            if (!$current || !in_array($current['status'],['pending','queued','failed','sending'],true)) { return; }
            Store::table('delivery')->where('id',$id)->update(['status'=>$status,'reason'=>$reason,'updated_at'=>time(),'claim'=>'','lease_until'=>0]);
            Store::table('item')->where('delivery_id',$id)->whereIn('status',['pending','batched'])->update(['status'=>$status,'reason'=>$reason]);
            History::capture($id);
        });
    }
    public function retry(int $id, int $admin) : void
    {
        Store::transaction(function () use ($id,$admin) {
            $d=Store::table('delivery')->where('id',$id)->lock(true)->find();
            if (!$d || !in_array($d['status'],['failed','exhausted'],true)) { throw new \DomainException('仅能重试明确失败的通知；结果未知须先核对接收方记录。'); }
            if ((int)$d['expires_at']<=time()) { throw new \DomainException('通知已过期，请等待下一次商品动态。'); }
            Store::table('item')->where('delivery_id',$id)->where('status','exhausted')->update(['status'=>'batched','reason'=>'']);
            // Manual retries remain bounded by the original three-attempt ceiling.
            if ((int)$d['attempts']>=($d['channel']==='webhook' ? Settings::get()['webhook_attempts'] : 3)) { throw new \DomainException('已达到本次通知尝试次数上限。'); }
            Store::table('delivery')->where('id',$id)->update(['status'=>'pending','next_at'=>time(),'updated_at'=>time()]);
            Store::audit('delivery_retry_requested',['delivery_id'=>$id],$admin);
        });
    }
}

<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Engine
{
    const OPEN = ['deferred','waiting','pending','batched'];
    public $errors=[];
    private $collectBatches=null;

    public static function stop(string $reason) : void
    {
        Store::transaction(static function () use ($reason) {
            self::cancelAll($reason);
            Store::table('delivery')->whereIn('status',['pending','queued','failed'])->update(['status'=>'cancelled','reason'=>$reason,'updated_at'=>time()]);
            Store::table('product')->where('product_id','>',0)->update(['known'=>0,'checked_at'=>0]);
            Store::table('availability')->where('product_id','>',0)->update(['state'=>'unknown','checked_at'=>0]);
        });
    }

    protected static function cancel($query, string $reason) : void
    {
        $query->whereIn('status',self::OPEN); $ids=(clone $query)->column('delivery_id');
        $query->update(['status'=>'cancelled','reason'=>$reason]);
        foreach (array_unique(array_filter($ids)) as $id) { History::capture((int)$id); }
    }
    public static function cancelAll(string $reason) : void { self::cancel(Store::table('item'),$reason); }
    public static function cancelChannel(string $channel, string $reason) : void { $q=Store::table('item')->where('channel',$channel); if ($channel==='webhook') { $q->where('client_id','>',0); } self::cancel($q,$reason); }
    public static function cancelProduct(int $id, string $reason) : void { self::cancel(Store::table('item')->where('product_id',$id),$reason); }
    public static function cancelSubscription(int $id, string $reason) : void { self::cancel(Store::table('item')->where('subscription_id',$id),$reason); }
    public static function cancelEndpoint(int $id, string $reason) : void { self::cancel(Store::table('item')->where('endpoint_id',$id),$reason); }

    protected function candidate(array $rule, array $sub, string $channel, ?array $endpoint, string $source, string $batch, array $client, int $eligibleAt = 0, string $eventType = 'restock') : array
    {
        $now=time(); $settings=Settings::get(); $group=empty($sub['id']); $endpointId=(int)($endpoint['id'] ?? 0);
        $identity=hash('sha256',implode(':',[$group ? 'group' : 'personal',(int)($sub['id'] ?? 0),(int)($sub['generation'] ?? 0),$rule['product_id'],$rule['cycle'],$channel,$endpoint ? $endpoint['address_hash'] : 0]));
        if ($eventType!=='restock') { $identity=hash('sha256',$eventType . ':' . $identity); }
        $window=(int)$settings['merge_seconds'];
        $receiver=$endpoint ? $endpoint['address_hash'] : Notice::hash($client,$channel);
        $due=$source==='manual' || !$window ? $now : $now+$window;
        if ($source==='automatic' && !$batch && $eligibleAt<=$now) {
            $batchId=implode(':',[$eventType,$group?0:(int)$sub['client_id'],$channel,$endpointId,$receiver]);
            $open=$this->collectBatches!==null ? ($this->collectBatches[$batchId] ?? null) : Store::table('item')->where('client_id',$group ? 0 : (int)$sub['client_id'])->where('channel',$channel)->where('receiver_hash',$receiver)->where('endpoint_id',$endpointId)->where('source','automatic')->where('event_type',$eventType)->where('status','pending')->where('due_at','>',$now)->where('expires_at','>',$now)->order('id')->find();
            $batch=$open ? $open['batch_key'] : 'a' . bin2hex(random_bytes(12));
            if ($open) { $due=(int)$open['due_at']; }
            if($this->collectBatches!==null) { $this->collectBatches[$batchId]=['batch_key'=>$batch,'due_at'=>$due]; }
        }
        return ['wait_code'=>'','event_type'=>$eventType,'identity_key'=>$identity,'client_id'=>$group ? 0 : (int)$sub['client_id'],'subscription_id'=>(int)($sub['id'] ?? 0),'generation'=>(int)($sub['generation'] ?? 0),'subscription_revision'=>(int)($sub['revision'] ?? 0),'product_id'=>(int)$rule['product_id'],'cycle'=>(int)$rule['cycle'],'channel'=>$channel,'endpoint_id'=>$endpointId,'endpoint_revision'=>(int)($endpoint['revision'] ?? 0),
            'receiver_hash'=>$receiver,'threshold'=>$group ? (int)$endpoint['threshold'] : (int)$sub['threshold'],'source'=>$source,'batch_key'=>$batch,
            'status'=>$eligibleAt>$now ? 'deferred' : 'pending','reason'=>$eligibleAt>$now ? '同商品提醒冷却中，到期自动复核库存' : '',
            'due_at'=>$eligibleAt>$now ? $eligibleAt : $due,'expires_at'=>$eligibleAt>$now ? (int)$sub['expires_at'] : $now+$settings['notification_seconds'],'created_at'=>$now];
    }
    public function collectProduct(array $rule, string $source = 'automatic', string $batch = '', bool $groups = true, array $subIds = [], ?array $product = null, bool $dry = false, bool $clients = true) : array
    {
        $work=function () use ($rule,$source,$batch,$groups,$subIds,$product,$dry,$clients) { return Notice::catalog(function () use ($rule,$source,$batch,$groups,$subIds,$product,$dry,$clients) { try { return $this->collect($rule,$source,$batch,$groups,$subIds,$product,$dry,$clients); } finally { $this->collectBatches=null; } }); };
        return $dry ? $work() : Store::transaction($work);
    }
    protected function collect(array $rule, string $source, string $batch, bool $groups, array $subIds, ?array $product, bool $dry, bool $clients) : array
    {
        $out=['items'=>[],'skipped'=>[]]; $pid=(int)$rule['product_id'];
        if (empty($rule['enabled'])) { $out['skipped'][]='商品未开放订阅'; return $out; }
        if (!$product) { $read=(new Inventory())->read($pid); if ($read['state'] !== 'known') { $out['skipped'][]=$read['reason']; return $out; } $product=$read['product']; }
        if ((int)$product['qty'] <= 0) { $out['skipped'][]='商品库存不足'; return $out; }
        $settings=Settings::get(); $rows=[];
        if ($clients) {
            $q=Store::table('subscription')->where('product_id',$pid)->where('status','active')->where('expires_at','>',time());
            if ($subIds) { $q->whereIn('id',$subIds); }
            $rows=$q->select()->toArray();
        }
        $clientIds=array_values(array_unique(array_column($rows,'client_id')));
        $accounts=$clientIds ? array_column(Db::name('client')->whereIn('id',$clientIds)->select()->toArray(),null,'id') : [];
        $personal=[];
        foreach ($clientIds ? Store::table('endpoint')->whereIn('client_id',$clientIds)->where('scope','personal')->where('enabled',1)->select()->toArray() : [] as $endpoint) { $personal[(int)$endpoint['id']]=$endpoint; }
        $this->collectBatches=[];
        if($source==='automatic' && !$batch) {
            foreach (Store::table('item')->whereIn('client_id',array_merge([0],$clientIds))->where('source','automatic')->where('status','pending')->where('due_at','>',time())->where('expires_at','>',time())->order('id')->field('client_id,channel,receiver_hash,endpoint_id,event_type,batch_key,due_at')->select()->toArray() as $open) {
                $key=implode(':',[$open['event_type'],$open['client_id'],$open['channel'],$open['endpoint_id'],$open['receiver_hash']]);
                if(!isset($this->collectBatches[$key])) { $this->collectBatches[$key]=$open; }
            }
        }
        foreach ($rows as $sub) {
            if ((int)$product['qty'] <= (int)$sub['threshold']) { $out['skipped'][]='客户库存门槛未满足'; continue; }
            $eligibleAt=(int)$sub['last_notified_at']+(int)$settings['cooldown_seconds'];
            // An observed replenishment must survive a temporary cooldown.
            // A deferred item records eligibility to recheck, not an old message
            // to replay. Manual previews remain read-only and report the limit.
            if ($source==='manual' && $eligibleAt>time()) { $out['skipped'][]='同商品提醒冷却中'; continue; }
            $client=$accounts[(int)$sub['client_id']] ?? [];
            foreach (Store::decode($sub['channels']) as $ch) {
                $reason=Notice::usable($ch,$client,0);
                if ($reason !== '' && !($source==='automatic' && Notice::recoverable($reason))) { $out['skipped'][]=$reason; continue; }
                $endpoints=[null];
                if ($ch === 'webhook') {
                    $ids=Store::decode($sub['endpoint_ids']);
                    $endpoints=[]; foreach ($ids as $eid) { if(isset($personal[$eid]) && (int)$personal[$eid]['client_id']===(int)$sub['client_id']) { $endpoints[]=$personal[$eid]; } }
                }
                foreach ($endpoints as $endpoint) {
                    $item=$this->candidate($rule,$sub,$ch,$endpoint,$source,$batch,$client,$eligibleAt);
                    if ($reason!=='' && $item['status']!=='deferred') { $item['status']='waiting'; $item['wait_code']='configuration'; $item['due_at']=time()+60; $item['reason']=$reason.'；有效期内自动复核'; }
                    $out['items'][]=$item;
                }
            }
        }
        if ($groups) {
            foreach (Store::table('endpoint')->where('scope','group')->where('enabled',1)->select()->toArray() as $endpoint) {
                if (!in_array($pid,Store::decode($endpoint['product_ids']),true) || (int)$product['qty'] <= (int)$endpoint['threshold']) { continue; }
                $baseline=Store::decode($endpoint['baseline'])[$pid] ?? [];
                if ($source !== 'manual' && empty($baseline['armed']) && (int)$rule['cycle'] <= (int)($baseline['cycle'] ?? PHP_INT_MAX)) { continue; }
                $out['items'][]=$this->candidate($rule,[],'webhook',$endpoint,$source,$batch,[]);
            }
        }
        $kept=[]; $insert=[]; $worker=new Worker(); $cache=[];
        $previous=array_column(Store::table('item')->where('product_id',$pid)->where('cycle',$rule['cycle'])->select()->toArray(),null,'identity_key');
        // A read-only broadcast preview can project the next replenishment
        // cycle before cron persists it. Validate SMS against that same read
        // and projected rule; never compare it with the previous stored cycle.
        if ($dry) { $cache[$pid]=[['state'=>'known','product'=>$product],$rule]; }
        foreach ($out['items'] as $item) {
            if ($item['channel']==='sms') { $probe=$item; if (in_array($probe['status'],['deferred','waiting'],true)) { $probe['status']='pending'; } [$valid,$reason]=$worker->valid($probe,$cache); if (!$valid && !($source==='automatic' && Notice::recoverable($reason))) { $out['skipped'][]=$reason; continue; } }
            $old=$previous[$item['identity_key']] ?? null;
            $reactivate=$old && $old['status'] === 'cancelled' && empty($old['send_attempts']) && (int)$old['subscription_revision'] < $item['subscription_revision'];
            if ($old && !$reactivate) { $out['skipped'][]='本轮已经安排或通知过'; continue; }
            if (!$dry) {
                if ($reactivate) { Store::table('item')->where('id',$old['id'])->where('status','cancelled')->update($item+['delivery_id'=>0,'reason'=>'']); }
                else { $insert[]=$item; }
                $previous[$item['identity_key']]=$item;
            }
            $kept[]=$item;
        }
        foreach(array_chunk($insert,200) as $chunk) { Store::table('item')->insertAll($chunk); }
        $out['items']=$kept; return $out;
    }
    public function observeProduct(int $id, bool $force = false) : array
    {
        // Persist the observation and its reminders together. A failed collector
        // must not commit the new quantity and lose the only inventory edge.
        return Store::transaction(function () use ($id,$force) {
            $change=(new Availability())->observe($id);
            if (!empty($change['delisted'])) { $this->collectDelisted($change); }
            $read=(new Inventory())->observe($id,$force);
            if (!empty($read['changed'])) { $this->collectProduct($read['rule'],'automatic','',true,[],$read['product']); }
            if (($read['state'] ?? '')==='known' && empty($read['baseline'])) { $this->resumeDeferred($read['rule'],$read['product']); }
            return $read;
        });
    }
    private function collectDelisted(array $change) : void
    {
        $pid=(int)$change['product_id'];
        $rule=['product_id'=>$pid,'cycle'=>(int)$change['episode']];
        $count=0;
        foreach (Store::table('subscription')->where('product_id',$pid)->where('status','active')->where('expires_at','>',time())->select()->toArray() as $sub) {
            $client=Notice::client((int)$sub['client_id']);
            foreach (Store::decode($sub['channels']) as $ch) {
                $reason=Notice::usable($ch,$client,0,'delisted');
                if ($reason!=='' && !Notice::recoverable($reason)) { continue; }
                $endpoints=[null];
                if ($ch==='webhook') {
                    $ids=Store::decode($sub['endpoint_ids']);
                    $endpoints=$ids ? Store::table('endpoint')->whereIn('id',$ids)->where('client_id',$sub['client_id'])->where('scope','personal')->where('enabled',1)->select()->toArray() : [];
                }
                foreach ($endpoints as $endpoint) {
                    $item=$this->candidate($rule,$sub,$ch,$endpoint,'automatic','',$client,0,'delisted');
                    if ($reason!=='') { $item['status']='waiting';$item['wait_code']='configuration';$item['reason']=$reason.'；有效期内自动复核';$item['due_at']=time()+60; }
                    if (Store::table('item')->where('identity_key',$item['identity_key'])->count()) { continue; }
                    Store::table('item')->insert($item); $count++;
                }
            }
        }
        Store::audit('product_delisted',['product_id'=>$pid,'items'=>$count]);
    }
    protected function resumeDeferred(array $rule, array $product) : void
    {
        $items=Store::table('item')->where('product_id',$rule['product_id'])->where('status','deferred')->order('id')->lock(true)->select()->toArray();
        $worker=new Worker(); $cache=[(int)$rule['product_id']=>[['state'=>'known','product'=>$product],$rule]];
        foreach ($items as $item) {
            if ((int)$item['expires_at']<=time()) { Store::table('item')->where('id',$item['id'])->update(['status'=>'expired','reason'=>'等待复核期间订阅已过期']); continue; }
            if ((int)$item['cycle']!==(int)$rule['cycle']) { Store::table('item')->where('id',$item['id'])->update(['status'=>'cancelled','reason'=>'已进入新的补货轮次']); continue; }
            // A temporary stock dip cannot send a stale message. Keep waiting
            // within the same round and subscription lifetime for a fresh check.
            if ((int)$product['qty']<=(int)$item['threshold']) { continue; }
            $probe=$item; $probe['status']='pending';
            [$valid,$reason]=$worker->valid($probe,$cache);
            if (!$valid) { if (Notice::recoverable($reason)) { continue; } Store::table('item')->where('id',$item['id'])->update(['status'=>'skipped','reason'=>$reason]); continue; }
            $sub=Store::table('subscription')->where('id',$item['subscription_id'])->find();
            $eligibleAt=(int)$sub['last_notified_at']+(int)Settings::get()['cooldown_seconds'];
            if ($eligibleAt>time()) {
                if ((int)$item['due_at']!==$eligibleAt) { Store::table('item')->where('id',$item['id'])->update(['due_at'=>$eligibleAt]); }
                continue;
            }
            $endpoint=$item['endpoint_id'] ? Store::table('endpoint')->where('id',$item['endpoint_id'])->find() : null;
            $ready=$this->candidate($rule,$sub,$item['channel'],$endpoint,'automatic','',Notice::client((int)$item['client_id']));
            Store::table('item')->where('id',$item['id'])->where('status','deferred')->update($ready);
            Store::audit('deferred_notification_ready',['item_id'=>(int)$item['id'],'product_id'=>(int)$item['product_id'],'observed_at'=>(int)$item['created_at'],'confirmed_at'=>time()],0,(int)$item['client_id']);
        }
    }
    public function flush() : int
    {
        Store::transaction(static function () {
            Store::table('item')->whereIn('status',self::OPEN)->where('expires_at','<=',time())->update(['status'=>'expired','reason'=>'通知已过期']);
        });
        $items=Store::table('item')->where('status','pending')->where('due_at','<=',time())->order('id')->limit(1000)->select()->toArray(); $buckets=[];
        foreach ($items as $item) {
            $key=implode(':',[$item['event_type'],$item['client_id'],$item['channel'],$item['endpoint_id'],$item['receiver_hash'],$item['batch_key']]); $buckets[$key]=$item;
        }
        $created=0; $handled=0;
        foreach ($buckets as $seed) {
            if ($handled>=1000) { break; }
            try { $created+=Store::transaction(function () use ($seed,&$handled) {
                // The scan limit selects work, not message boundaries. Claim the
                // entire due merge group before applying platform length limits.
                $query=Store::table('item')->where('status','pending')->where('due_at','<=',time());
                foreach (['event_type','client_id','channel','endpoint_id','receiver_hash','batch_key'] as $field) { $query->where($field,$seed[$field]); }
                $items=$query->order('id')->lock(true)->select()->toArray(); $handled+=count($items);
                $worker=new Worker(); $cache=[]; $valid=[]; $products=[];
                foreach ($items as $item) {
                    [$ok,$reason,$product,$code]=$worker->valid($item,$cache);
                    if (!$ok) { $worker->suspend($item,$reason,$code); continue; }
                    $valid[]=$item; $products[$item['product_id']]=$product;
                }
                $chunks=[]; $chunk=[]; $bytes=120;
                foreach ($valid as $item) {
                    $size=strlen(mb_strcut($products[$item['product_id']]['name'],0,400,'UTF-8'))+strlen(Store::baseUrl())+160;
                    if ($chunk && $item['channel']==='webhook' && (count($chunk)>=50 || $bytes+$size>1700)) { $chunks[]=$chunk; $chunk=[]; $bytes=120; }
                    $chunk[]=$item; $bytes+=$size;
                }
                if ($chunk) { $chunks[]=$chunk; }
                $deliveries=0;
                foreach ($chunks as $chunk) {
                    $first=$chunk[0]; $now=time(); $hint=''; $endpointName='';
                    if ($first['channel']!=='webhook') {
                        $reason=Notice::usable($first['channel'],Notice::client((int)$first['client_id']),count(array_unique(array_column($chunk,'product_id'))),$first['event_type']);
                        if ($reason!=='') { foreach ($chunk as $entry) { $worker->suspend($entry,$reason,Notice::recoverable($reason) ? 'configuration' : 'stop'); } continue; }
                    }
                    if ($first['endpoint_id']) { $ep=Store::table('endpoint')->where('id',$first['endpoint_id'])->find(); $hint='https://' . parse_url(Store::open($ep['url']),PHP_URL_HOST) . '/***'; $endpointName=$ep['name']; }
                    else { $hint=Store::mask(Notice::receiver(Notice::client((int)$first['client_id']),$first['channel'])); }
                    $snapshot=[]; foreach ($chunk as $item) { $snapshot[$item['product_id']]=$products[$item['product_id']]; }
                    $id=Store::table('delivery')->insertGetId(['event_type'=>$first['event_type'],'event_id'=>bin2hex(random_bytes(16)),'client_id'=>$first['client_id'],'channel'=>$first['channel'],'endpoint_id'=>$first['endpoint_id'],'receiver_hash'=>$first['receiver_hash'],'receiver_hint'=>$hint,'endpoint_name'=>$endpointName,'expires_at'=>min(array_column($chunk,'expires_at')),'attempts'=>max(array_column($chunk,'send_attempts')),'snapshot'=>Store::json(array_values($snapshot)),'created_at'=>$now,'updated_at'=>$now]);
                    Store::table('item')->whereIn('id',array_column($chunk,'id'))->where('status','pending')->update(['status'=>'batched','delivery_id'=>$id]);
                    History::capture((int)$id);
                    $deliveries++;
                }
                return $deliveries;
            }); } catch (\Throwable $error) { $this->errors[]=Diagnostics::fault('merge',(int)$seed['id'],$error); }
        }
        return $created;
    }
    public function cron() : void
    {
        if (!Store::active()) { return; }
        $this->errors=[];
        // MySQL limits named locks to 64 characters, including our prefix.
        $key='css:' . substr(hash('sha256',Db::connect()->getConfig('database') . ':' . Db::connect()->getConfig('prefix')),0,60);
        if (!(int)(Db::query('SELECT GET_LOCK(?,0) acquired',[$key])[0]['acquired'] ?? 0)) { return; }
        $heartbeat=[];
        try {
            // A late cron is not a plugin restart. Keep observed stock rounds
            // and live reminders; ordinary observation and the send-time check
            // decide whether they still qualify. Enable/reopen owns baselining.
            Store::table('setting')->extra('IGNORE')->insert(['id'=>2,'data'=>'{}','updated_at'=>time()]);
            Store::table('setting')->where('id',2)->update(['updated_at'=>time()]);
            $heartbeat=Store::decode(Store::table('setting')->where('id',2)->value('data'));
            $heartbeat['started_at']=time();
            Store::table('setting')->where('id',2)->update(['data'=>Store::json($heartbeat)]);
            Store::table('subscription')->where('status','active')->where('expires_at','<=',time())->update(['status'=>'expired','updated_at'=>time()]);
            foreach (Store::table('product')->where('enabled',1)->order('product_id')->select()->toArray() as $rule) {
                try { $this->observeProduct((int)$rule['product_id']); } catch (\Throwable $error) { $this->errors[]=Diagnostics::fault('product',(int)$rule['product_id'],$error); }
            }
            $worker=new Worker();$worker->resumeWaiting();$this->flush();$worker->dispatch();
            History::archive();
            $errors=array_merge($this->errors,$worker->errors);
            $heartbeat['issues']=array_slice($errors,0,20);$heartbeat['issue_count']=count($errors);
            if ($errors) { $heartbeat['last_error_at']=time(); } else { $heartbeat['last_success_at']=time();$heartbeat['last_error_at']=0; }
            Store::table('setting')->where('id',2)->update(['data'=>Store::json($heartbeat)]);
        } catch (\Throwable $e) {
            try { $heartbeat['last_error_at']=time();Store::table('setting')->where('id',2)->update(['data'=>Store::json($heartbeat)]); } catch (\Throwable $ignored) {}
            throw $e;
        } finally { Db::query('SELECT RELEASE_LOCK(?) released',[$key]); }
    }
    public function broadcast(array $input, int $admin, bool $send = false) : array
    {
        $ids=Store::ids($input['product_ids'] ?? []);
        if (!$ids) { throw new \DomainException('请选择需要通知的商品。'); }
        $clients=Store::number($input['clients'] ?? 1,0,1,'客户通知')===1; $groups=Store::number($input['groups'] ?? 0,0,1,'商家群通知')===1;
        if (!$clients && !$groups) { throw new \DomainException('请选择客户通知或商家群推送。'); }
        Store::requireActive();
        $selection=['product_ids'=>$ids,'clients'=>$clients,'groups'=>$groups];
        $key=(string)($input['request_key'] ?? '');
        if ($send && !preg_match('/\A[a-zA-Z0-9_-]{16,64}\z/',$key)) { throw new \DomainException('群发请求标识无效，请刷新预览。'); }
        $fn=function () use ($ids,$clients,$groups,$admin,$send,$key,$selection,$input) {
            if ($send) {
                Store::table('setting')->where('id',1)->lock(true)->find();
                $old=Store::table('broadcast')->where('request_key',$key)->find();
                if ($old) { if ((int)$old['admin_id'] !== $admin || $old['selection'] !== Store::json($selection)) { throw new \DomainException('此请求标识已用于其他群发，请重新预览。'); } return Store::decode($old['result']); }
                $stamp=Store::number($input['preview_at'] ?? 0,1,2147483647,'预览时间');
                if ($stamp>time()+5 || $stamp<time()-180) { throw new \DomainException('群发预览已过期，请重新预览。'); }
                $current=$this->broadcast(['product_ids'=>$ids,'clients'=>(int)$clients,'groups'=>(int)$groups],$admin,false);
                $token=Store::sign('broadcast|'.$admin.'|'.$stamp.'|'.$current['fingerprint'].'|'.Store::json($selection));
                if (!is_string($input['preview_token'] ?? null) || !hash_equals($token,$input['preview_token'])) { throw new \DomainException('库存、订阅客户、渠道或预计费用已变化，请重新预览后确认。'); }

            }
            $items=[]; $skipped=[]; $products=[];
            foreach ($ids as $id) {
                $rule=Store::table('product')->where('product_id',$id)->find();
                if (!$rule || !$rule['enabled']) { $skipped[]='商品 #' . $id . ' 未开放订阅'; continue; }
                if ($send) { $read=(new Inventory())->observe($id,true); $rule=$read['rule'] ?? $rule; }
                else { $read=(new Inventory())->read($id); if ($read['state']==='known' && $rule['known'] && (int)$rule['qty']===0 && (int)$read['product']['qty']>0) { $rule['cycle']++; } }
                if (($read['state'] ?? '') !== 'known') { $skipped[]=$read['reason'] ?? '库存无法确认'; continue; }
                $products[$id]=$read['product'];
                $part=$this->collectProduct($rule,'manual',$key ?: 'preview',$groups,[],$read['product'],!$send,$clients);
                $items=array_merge($items,$part['items']); $skipped=array_merge($skipped,$part['skipped']);
                // A manual broadcast can be the first observer of real stock
                // growth. Preserve automatic events it could not send (notably
                // cooldown waits), without changing this batch's preview/counts.
                if ($send && !empty($read['changed'])) { $this->collectProduct($rule,'automatic','',true,[],$read['product']); }
            }
            $buckets=[]; $customerIds=[];
            foreach ($items as $i) { $b=implode(':',[$i['client_id'],$i['channel'],$i['endpoint_id']]); $buckets[$b][]=$i; if ($i['client_id']) { $customerIds[$i['client_id']]=true; } }
            $counts=['email'=>0,'sms'=>0,'webhook'=>0,'group'=>0]; $units=0; $forecast=[]; $eligibleItems=0; $eligibleCustomers=[];
            $siteUsed=(int)Store::table('sms_budget')->where('day',date('Y-m-d'))->where('client_id',0)->value('reserved'); $settings=Settings::get();
            foreach ($buckets as $bucket) {
                $i=$bucket[0];
                if ($i['channel']==='email') {
                    $reason=Notice::usable('email',Notice::client((int)$i['client_id']),count(array_unique(array_column($bucket,'product_id'))));
                    if ($reason) { $skipped[]=$reason; if ($send) { foreach ($bucket as $entry) { Store::table('item')->where('identity_key',$entry['identity_key'])->where('status','pending')->update(['status'=>'skipped','reason'=>$reason]); } } continue; }
                }
                if ($i['channel']==='sms') {
                    $ps=[]; foreach ($bucket as $entry) { $ps[$entry['product_id']]=$products[$entry['product_id']]; }
                    $client=Notice::client((int)$i['client_id']); $estimate=Notice::smsEstimate(array_values($ps),$client,time());
                    $cid=(int)$i['client_id']; if (!isset($forecast[$cid])) { $forecast[$cid]=(int)Store::table('sms_budget')->where('day',date('Y-m-d'))->where('client_id',$cid)->value('reserved'); }
                    $reason=Notice::usable('sms',$client,count($ps));
                    if (!$reason && ($siteUsed+$estimate>$settings['sms_site_daily'] || $forecast[$cid]+$estimate>$settings['sms_client_daily'])) { $reason='短信每日额度不足'; }
                    if ($reason) { $skipped[]=$reason; if ($send) { foreach ($bucket as $entry) { Store::table('item')->where('identity_key',$entry['identity_key'])->where('status','pending')->update(['status'=>'skipped','reason'=>$reason]); } } continue; }
                    $siteUsed+=$estimate; $forecast[$cid]+=$estimate; $units+=$estimate;
                }
                $counts[$i['client_id'] ? $i['channel'] : 'group']++;
                $eligibleItems+=count($bucket); if ($i['client_id']) { $eligibleCustomers[$i['client_id']]=true; }
            }
            $result=['products'=>count($products),'customers'=>count($eligibleCustomers),'channels'=>$counts,'sms_units'=>$units,'sms_estimate_notice'=>'按模板长度及签名预留估算，实际计费以服务商为准；发送时再次检查额度。','items'=>$eligibleItems,'skipped'=>array_count_values($skipped)];
            if (!$send) {
                $facts=[];
                foreach ($items as $item) { $facts[]=array_intersect_key($item,array_flip(['identity_key','subscription_revision','endpoint_revision','receiver_hash','threshold','status'])); }
                usort($facts,static function ($a,$b) { return strcmp($a['identity_key'],$b['identity_key']); });
                $templateFacts=[];
                foreach ($buckets as $bucket) {
                    $item=$bucket[0]; $channel=$item['channel'];
                    if (!in_array($channel,['email','sms'],true)) { continue; }
                    $action=Notice::action(count(array_unique(array_column($bucket,'product_id'))));
                    $t=Notice::template($channel,$action,Notice::client((int)$item['client_id']));
                    $key=$channel.':'.$action.':'.$t['key'];
                    if (isset($templateFacts[$key])) { continue; }
                    $provider=Db::name('plugin')->where('module',$channel==='email'?'mail':'sms')->where('name',$t['provider'])->field('name,status,version')->find();
                    $fields=$channel==='email' ? ['id','subject','message'] : ['id','content','template_id','status','sms_name'];
                    $templateFacts[$key]=[$t['setting'][$channel.'_enable'] ?? 0,$t['provider'],array_intersect_key($t['template'],array_flip($fields)),$provider];
                }
                ksort($templateFacts);
                $display=array_intersect_key($settings,array_flip(['show_quantity','show_price']));
                $productFacts=[];
                foreach ($products as $product) { $productFacts[]=array_intersect_key($product,array_flip($settings['show_price'] ? ['id','name','qty','price'] : ['id','name','qty'])); }
                $result['fingerprint']=hash('sha256',Store::json([$selection,$facts,$productFacts,$result,$display,$templateFacts]));
                $result['preview_at']=time();$result['preview_expires_at']=time()+180;
                $result['preview_token']=Store::sign('broadcast|'.$admin.'|'.$result['preview_at'].'|'.$result['fingerprint'].'|'.Store::json($selection));
            }
            if ($send) { $id=Store::table('broadcast')->insertGetId(['admin_id'=>$admin,'request_key'=>$key,'selection'=>Store::json($selection),'result'=>Store::json($result),'created_at'=>time()]); Store::audit('broadcast_created',['broadcast_id'=>$id,'items'=>count($items)],$admin); }
            return $result;
        };
        return $send ? Store::transaction($fn) : $fn();
    }
}

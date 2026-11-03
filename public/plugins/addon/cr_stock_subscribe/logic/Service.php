<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Service
{
    public function entry(int $clientId = 0) : array
    {
        $enabled=Store::active(); $data=['enabled'=>$enabled,'url'=>Store::memberPath(),'version'=>Store::VERSION,'scope'=>'product'];
        if ($clientId) {
            $client=Notice::client($clientId); $settings=Settings::get(); $channels=[];
            foreach (['email'=>'邮件','sms'=>'短信','webhook'=>'个人 Webhook'] as $key=>$label) { $reason=Notice::usable($key,$client,0); $channels[]=['value'=>$key,'label'=>$label,'enabled'=>$reason==='','reason'=>$reason]; }
            $data+=['channels'=>$channels,'max_subscriptions'=>$settings['max_subscriptions'],'valid_days'=>$settings['valid_days'],'show_quantity'=>$settings['show_quantity'],'show_price'=>$settings['show_price'],'email_hint'=>Store::mask($client['email'] ?? ''),'phone_hint'=>Store::mask($client['phone'] ?? '')];
        }
        return $data;
    }
    public function products(array $input, bool $admin = false) : array
    {
        if ($admin && !empty($input['summary'])) { return (new Inspector())->health(); }
        if ($admin && !empty($input['preview'])) { return (new Inspector())->productPreview(Store::ids($input['product_ids']??[]),Store::number($input['enabled']??1,0,1,'开放状态')===1); }
        if ($admin && !empty($input['tree'])) { return $this->productCatalog(); }
        [$page,$limit]=Store::page($input);
        $q=Db::name('product')->alias('p')->leftJoin(Store::PREFIX . 'product r','r.product_id=p.id')->leftJoin('product_group g','g.id=p.product_group_id')->leftJoin('product_group parent','parent.id=g.parent_id');
        $q->where('p.product_id',0);
        if (!$admin) { $q->where('r.enabled',1)->where('p.hidden',0)->where('p.stock_control',1)->where('g.hidden',0)->where(function ($where) { $where->where('g.parent_id',0)->whereOr('parent.hidden',0); }); }
        if (!empty($input['keywords'])) { $q->whereLike('p.name','%' . mb_substr((string)$input['keywords'],0,100) . '%'); }
        if (!empty($input['group_id'])) { $q->where('p.product_group_id',(int)$input['group_id']); }
        $count=(clone $q)->count();
        $rows=$q->field('p.id,p.name,p.product_group_id,p.hidden,p.stock_control,p.qty,p.price,g.name group_name,r.enabled,r.known,r.reason,r.checked_at')->order('p.id','desc')->page($page,$limit)->select()->toArray(); $settings=Settings::get();
        foreach ($rows as &$r) {
            $r['enabled']=(int)($r['enabled'] ?? 0); $read=(new Inventory())->read((int)$r['id']);
            $r['inventory_state']=$read['state']; $r['inventory_reason']=$read['reason'] ?? '';
            if (!$admin && $read['state']!=='known') { unset($r['qty']); }
            $r['purchase_url']=Store::baseUrl() . '/cart/goods.htm?id=' . (int)$r['id'];
            if (!$admin) { if (!$settings['show_quantity']) { unset($r['qty']); } if (!$settings['show_price']) { unset($r['price']); } unset($r['reason'],$r['checked_at'],$r['hidden']); }
            else {
                $r['subscribers']=(int)Store::table('subscription')->where('product_id',$r['id'])->where('status','active')->where('expires_at','>',time())->count();
                $r['thresholds']=Store::table('subscription')->where('product_id',$r['id'])->where('status','active')->where('expires_at','>',time())->field('threshold,COUNT(*) subscribers')->group('threshold')->order('threshold')->select()->toArray();
                $r['last_notified_at']=(int)Store::table('item')->where('event_type','restock')->where('product_id',$r['id'])->max('accepted_at');
            }
        }
        $groups=Db::name('product_group')->alias('g')->leftJoin('product_group parent','parent.id=g.parent_id')->field('g.id,g.name');
        if (!$admin) { $groups->where('g.hidden',0)->where(function ($where) { $where->where('g.parent_id',0)->whereOr('parent.hidden',0); }); }
        return ['list'=>$rows,'count'=>$count,'groups'=>$groups->order('g.id')->select()->toArray()];
    }
    private function productCatalog() : array
    {
        $groups=Db::name('product_group')->field('id,name,parent_id,hidden')->order('order','desc')->order('id','asc')->select()->toArray();
        $rows=Db::name('product')->alias('p')->leftJoin(Store::PREFIX . 'product r','r.product_id=p.id')->where('p.product_id',0)
            ->field('p.id,p.name,p.order product_order,p.product_group_id,p.hidden,p.stock_control,p.qty,p.sync_stock,r.enabled,r.fresh_at')->order('p.order','desc')->order('p.id','asc')->select()->toArray();
        $reads=(new Inventory())->readMany($rows,$groups); $demand=[]; $last=[];
        foreach (Store::table('subscription')->where('status','active')->where('expires_at','>',time())->field('product_id,threshold,COUNT(*) subscribers')->group('product_id,threshold')->order('threshold')->select()->toArray() as $r) {
            $demand[$r['product_id']][]=['threshold'=>(int)$r['threshold'],'subscribers'=>(int)$r['subscribers']];
        }
        foreach (Store::table('item')->where('event_type','restock')->field('product_id,MAX(accepted_at) last_notified_at')->group('product_id')->select()->toArray() as $r) { $last[$r['product_id']]=(int)$r['last_notified_at']; }
        foreach ($rows as &$r) {
            $r['enabled']=(int)($r['enabled'] ?? 0); $r['thresholds']=$demand[$r['id']] ?? [];
            $r['subscribers']=array_sum(array_column($r['thresholds'],'subscribers'));
            $r['last_notified_at']=$last[$r['id']] ?? 0;
            $r['inventory_state']=$reads[$r['id']]['state']; $r['inventory_reason']=$reads[$r['id']]['reason'] ?? '';
            unset($r['sync_stock'],$r['fresh_at']);
        }
        unset($r);
        return ['list'=>$rows,'count'=>count($rows),'groups'=>$groups];
    }
    public function records(int $client, array $input, bool $admin = false) : array
    {
        if ($admin && !empty($input['summary'])) { return (new Inspector())->health(); }
        $view=Filters::choice($input,'view',['notifications','audit','pending']);
        if(!$admin && in_array($view,['audit','pending'],true)) { throw new \DomainException('待处理明细与操作日志仅供后台管理员查看。'); }
        if($admin && $view==='pending') { return $this->pending($input); }
        if ($admin && $view==='audit') { return $this->audits($input); }
        [$page,$limit]=Store::page($input); $q=Store::table('delivery')->alias('d');
        $archive=Filters::choice($input,'archive',['current','archived','all']);
        if($archive==='current') { $q->where('d.archived_at',0); }
        elseif($archive==='archived') { $q->where('d.archived_at','>',0); }
        if ($event=Filters::text($input,'event_id',32)) {
            if (!preg_match('/\A[a-f0-9]{32}\z/',$event)) { throw new \DomainException('通知编号不正确。'); }
            $q->where('d.event_id',$event);
        }
        if (!empty($input['waiting'])) { $q->whereIn('d.status',['pending','queued','failed','sending'])->where('d.expires_at','>',time()); }
        if (!$admin) { $q->where('d.client_id',$client); }
        if ($admin && ($id=Filters::id($input,'client_id'))) { $q->where('d.client_id',$id); }
        if ($admin && ($id=Filters::id($input,'endpoint_id'))) { $q->where('d.endpoint_id',$id); }
        if ($status=Filters::choice($input,'status',['pending','queued','sending','accepted','failed','exhausted','unknown','skipped','cancelled','expired'])) {
            if ($status==='expired') { $q->where(function ($q) { $q->where('d.status','expired')->whereOr(function ($q) { $q->whereIn('d.status',['pending','queued','failed'])->where('d.expires_at','<=',time()); }); }); }
            else { $q->where('d.status',$status); if (in_array($status,['pending','queued','failed'],true)) { $q->where('d.expires_at','>',time()); } }
        }
        if ($admin && ($review=Filters::choice($input,'review',['unreviewed','accepted','not_accepted','unresolved']))) {
            if ($review==='unreviewed') { $q->where('d.status','unknown')->whereNotExists(Store::table('review')->alias('r')->whereRaw('r.delivery_id=d.id')->field('r.delivery_id')->buildSql()); }
            else { $q->whereExists(Store::table('review')->alias('r')->whereRaw('r.delivery_id=d.id')->where('r.outcome',$review)->field('r.delivery_id')->buildSql()); }
        }
        if ($type=Filters::choice($input,'event_type',['restock','delisted'])) { $q->where('d.event_type',$type); }
        if ($channel=Filters::choice($input,'channel',['email','sms','webhook'])) { $q->where('d.channel',$channel); }
        if ($admin && ($target=Filters::choice($input,'target',['personal','group']))) { $q->where('d.client_id',$target==='group'?'=':'>',0); }
        $product=Filters::id($input,'product_id'); $source=Filters::choice($input,'source',['automatic','manual']);
        if ($product || $source) {
            $match=Store::table('delivery_item')->alias('i')->whereRaw('i.delivery_id=d.id');
            if ($product) { $match->where('i.product_id',$product); }
            if ($source) { $match->where('i.source',$source); }
            $q->whereExists($match->field('i.item_id')->buildSql());
        }
        if ($word=Filters::text($input,'keywords')) {
            $like=Filters::like($word);
            $match=Store::table('delivery_item')->alias('i')->leftJoin('product p','p.id=i.product_id')->leftJoin(Store::PREFIX . 'availability a','a.product_id=i.product_id')->whereRaw('i.delivery_id=d.id')->where(function($q)use($like){$q->whereLike('p.name',$like)->whereOr('a.name','like',$like);})->field('i.item_id')->buildSql();
            $q->where(function ($q) use ($word,$like,$match,$admin) {
                $q->whereLike('d.event_id',$like); if ($admin) { $q->whereOr('d.reason','like',$like)->whereOr('d.snapshot','like',$like); } $q->whereOr('d.endpoint_name','like',$like)->whereOr('d.receiver_hint','like',$like)->whereExists($match,'OR');
                if (preg_match('/\A#?([1-9][0-9]{0,9})\z/',$word,$m)) { $q->whereOr('d.id',(int)$m[1]); if ($admin) { $q->whereOr('d.task_id',(int)$m[1]); } }
            });
        }
        Filters::dates($q,$input,'d.created_at');
        $count=(clone $q)->count();$rows=$q->field('d.*')->order('d.id','desc')->page($page,$limit)->select()->toArray();$settings=Settings::get();
        $ids=array_column($rows,'id');$items=[];$attempts=[];$names=[];$existing=[];
        if ($ids) {
            foreach(Store::table('delivery_item')->whereIn('delivery_id',$ids)->field('delivery_id,product_id,event_type,channel,endpoint_id,source,status,reason,accepted_at')->order('item_id')->select()->toArray() as $i) { $items[$i['delivery_id']][]=$i;$names[$i['product_id']]=true; }
            foreach(Store::table('attempt')->whereIn('delivery_id',$ids)->field('delivery_id,attempt,status,reason,provider_code,http_code,sms_units,created_at')->order('attempt')->select()->toArray() as $a) { $attempts[$a['delivery_id']][]=$a; }
            $nameIds=array_keys($names);
            $names=$names ? array_column(Db::name('product')->whereIn('id',array_keys($names))->field('id,name')->select()->toArray(),null,'id') : [];
            $existing=array_fill_keys(array_keys($names),true);
            $cached=$nameIds ? Store::table('availability')->whereIn('product_id',$nameIds)->column('name','product_id') : [];
            foreach ($nameIds as $pid) { if (!isset($names[$pid])) { $names[$pid]=['id'=>$pid,'name'=>$cached[$pid] ?? '商品已删除']; } }
        }
        foreach ($rows as &$r) {
            unset($r['claim'],$r['receiver_hash']);$r['snapshot']=Store::decode($r['snapshot']);$r['items']=$items[$r['id']]??[];$r['attempt_log']=$attempts[$r['id']]??[];
            if (in_array($r['status'],['pending','queued','failed'],true) && (int)$r['expires_at']<=time()) {
                $r['status']='expired'; $r['reason']='通知已过期';
                foreach ($r['items'] as &$item) { if (in_array($item['status'],Engine::OPEN,true)) { $item['status']='expired'; $item['reason']='通知已过期'; } } unset($item);
            }
            if (!$r['snapshot']) { foreach($r['items'] as $i) { $r['snapshot'][$i['product_id']]=$names[$i['product_id']]??['id'=>$i['product_id'],'name'=>'商品已删除']; }$r['snapshot']=array_values($r['snapshot']); }
            foreach ($r['snapshot'] as &$p) { $p['product_exists']=isset($existing[$p['id']]); if (!$admin && !$settings['show_quantity']) { unset($p['qty']); } if (!$admin && !$settings['show_price']) { unset($p['price']); } unset($p['sync_stock'],$p['hidden'],$p['stock_control']); } unset($p);
            foreach ($r['items'] as &$i) { $i['product_exists']=isset($existing[$i['product_id']]); } unset($i);
            if ($admin) { $r['review']=Store::table('review')->where('delivery_id',$r['id'])->find() ?: null; }
            else { unset($r['task_id'],$r['lease_until']); $r['reason']=''; foreach ($r['attempt_log'] as &$attempt) { $attempt['reason']='';unset($attempt['provider_code']); } unset($attempt); foreach ($r['items'] as &$item) { unset($item['reason']); } unset($item); }
            $r['sources']=array_values(array_unique(array_column($r['items'],'source')));
        }
        unset($r);return ['list'=>$rows,'count'=>$count];
    }
    private function pending(array $input) : array
    {
        [$page,$limit]=Store::page($input);$now=time();
        $q=Store::table('item')->alias('i')->leftJoin('product p','p.id=i.product_id')->leftJoin(Store::PREFIX.'availability a','a.product_id=i.product_id')->leftJoin(Store::PREFIX.'endpoint e','e.id=i.endpoint_id')->whereIn('i.status',Engine::OPEN);
        $status=Filters::choice($input,'status',['pending','waiting','deferred','batched','expired']);
        if($status==='expired') { $q->where('i.expires_at','<=',$now); }
        else { $q->where('i.expires_at','>',$now);if($status) { $q->where('i.status',$status); } }
        foreach(['client_id','product_id','endpoint_id'] as $key) { if($id=Filters::id($input,$key)) { $q->where('i.'.$key,$id); } }
        foreach(['channel'=>['email','sms','webhook'],'event_type'=>['restock','delisted'],'source'=>['automatic','manual']] as $key=>$values) { if($value=Filters::choice($input,$key,$values)) { $q->where('i.'.$key,$value); } }
        if($target=Filters::choice($input,'target',['personal','group'])) { $q->where('i.client_id',$target==='group'?'=':'>',0); }
        if($word=Filters::text($input,'keywords')) { $q->where(function($q)use($word) { $like=Filters::like($word);$q->whereLike('p.name',$like)->whereOr('a.name','like',$like)->whereOr('i.reason','like',$like);if(preg_match('/\A#?([1-9][0-9]{0,9})\z/',$word,$m)) { $q->whereOr('i.id',(int)$m[1])->whereOr('i.product_id',(int)$m[1]); } }); }
        Filters::dates($q,$input,'i.created_at');$count=(clone $q)->count();
        $rows=$q->field('i.id,i.client_id,i.subscription_id,i.product_id,i.channel,i.endpoint_id,i.event_type,i.source,i.status,i.reason,i.due_at,i.expires_at,i.created_at,i.delivery_id,COALESCE(p.name,a.name) product_name,p.id product_exists,e.name endpoint_name')->order('i.due_at')->order('i.id')->page($page,$limit)->select()->toArray();
        foreach($rows as &$row) { $row['product_name']=$row['product_name'] ?: '商品已删除';$row['product_exists']=(bool)$row['product_exists'];$row['reason']=Diagnostics::reason($row['reason']);if((int)$row['expires_at']<=$now) { $row['status']='expired';$row['reason']='已超过有效期，等待自动清理状态'; } }
        unset($row);return ['list'=>$rows,'count'=>$count];
    }
    public static function auditActions() : array
    {
        return ['history_archived'=>'历史通知归档','product_delisted'=>'商品下架通知安排','products_configured'=>'商品订阅设置','settings_saved'=>'插件设置修改','subscriptions_saved'=>'订阅保存','subscription_cancelled'=>'订阅取消','endpoint_saved'=>'Webhook 地址保存','endpoint_deleted'=>'Webhook 地址删除','personal_endpoint_disabled'=>'个人地址禁用','personal_endpoint_enabled'=>'个人地址恢复','delivery_reviewed'=>'通知结果人工核对','endpoint_tested'=>'Webhook 连接测试','broadcast_created'=>'人工补货群发','delivery_retry_requested'=>'通知重试','deferred_notification_ready'=>'冷却结束复核','plugin_uninstalled'=>'插件卸载'];
    }
    private function audits(array $input) : array
    {
        [$page,$limit]=Store::page($input);$q=Store::table('audit')->alias('a');$actions=self::auditActions();
        if($action=Filters::choice($input,'action',array_keys($actions)))$q->where('a.action',$action);
        if($actor=Filters::choice($input,'actor',['admin','client','system'])) {
            if($actor==='admin')$q->where('a.actor_id','>',0);
            elseif($actor==='client')$q->where('a.actor_id',0)->where('a.client_id','>',0)->where('a.action','<>','deferred_notification_ready');
            else $q->where('a.actor_id',0)->where(function($q){$q->where('a.client_id',0)->whereOr('a.action','deferred_notification_ready');});
        }
        if($id=Filters::id($input,'actor_id'))$q->where('a.actor_id',$id);
        if($id=Filters::id($input,'client_id'))$q->where('a.client_id',$id);
        if($word=Filters::text($input,'keywords')) {
            $matching=[];foreach($actions as $key=>$label)if(mb_stripos($label,$word)!==false)$matching[]=$key;
            $q->where(function($q)use($word,$matching){$q->whereLike('a.action',Filters::like($word));if($matching)$q->whereOr('a.action','in',$matching);if(preg_match('/\A#?([1-9][0-9]{0,9})\z/',$word,$m))$q->whereOr('a.id',(int)$m[1])->whereOr('a.actor_id',(int)$m[1])->whereOr('a.client_id',(int)$m[1]);});
        }
        Filters::dates($q,$input,'a.created_at');$count=(clone $q)->count();$rows=$q->order('a.id','desc')->page($page,$limit)->select()->toArray();
        foreach($rows as &$r) {
            $detail=Store::decode($r['detail']);$safe=[];
            foreach(['product_id','subscription_id','endpoint_id','delivery_id','broadcast_id','item_id','items','observed_at','confirmed_at'] as $key)if(isset($detail[$key])&&is_numeric($detail[$key]))$safe[$key]=(int)$detail[$key];
            if(isset($detail['enabled']))$safe['enabled']=(int)(bool)$detail['enabled'];
            if(isset($detail['product_ids'])&&is_array($detail['product_ids']))$safe['product_ids']=array_values(array_filter(array_map('intval',$detail['product_ids']),static function($id){return $id>0;}));
            if(isset($detail['changes'])&&is_array($detail['changes']))$safe['changes']=array_values(array_intersect(array_keys(Settings::defaults()),array_filter($detail['changes'],'is_string')));
            if(in_array($detail['outcome']??'',['accepted','not_accepted','unresolved'],true))$safe['outcome']=$detail['outcome'];
            if(isset($detail['note']))$safe['note']=Diagnostics::reason((string)$detail['note']);
            if(in_array($detail['scope']??'',['group','personal'],true))$safe['scope']=$detail['scope'];
            if(in_array($detail['status']??'',['accepted','failed','unknown'],true))$safe['status']=$detail['status'];
            $r['actor_type']=$r['actor_id']?'admin':($r['client_id'] && $r['action']!=='deferred_notification_ready'?'client':'system');$r['detail']=$safe;$r['label']=$actions[$r['action']]??'其他插件操作';
            $productIds=$safe['product_ids'] ?? (!empty($safe['product_id']) ? [$safe['product_id']] : []);
            $present=$productIds ? array_fill_keys(Db::name('product')->whereIn('id',$productIds)->column('id'),true) : [];
            $r['products']=array_map(static function ($id) use ($present) { return ['id'=>$id,'product_exists'=>isset($present[$id])]; },$productIds);
        }
        unset($r);return ['list'=>$rows,'count'=>$count,'actions'=>$actions,'field_labels'=>Settings::labels()];
    }
    public function review(int $id, array $input, int $admin) : void
    {
        $outcome=Filters::choice($input,'outcome',['accepted','not_accepted','unresolved']);
        $note=Filters::text($input,'note',500);
        if ($outcome==='' || $note==='') { throw new \DomainException('请选择核对结果并填写核对依据。'); }
        $note=Diagnostics::reason($note);
        Store::transaction(static function () use ($id,$outcome,$note,$admin) {
            $delivery=Store::table('delivery')->where('id',$id)->lock(true)->find();
            if (!$delivery || $delivery['status']!=='unknown') { throw new \DomainException('仅对结果未知的通知登记人工核对。'); }
            $data=['outcome'=>$outcome,'note'=>$note,'admin_id'=>$admin,'updated_at'=>time()];
            if (Store::table('review')->where('delivery_id',$id)->count()) { Store::table('review')->where('delivery_id',$id)->update($data); }
            else { Store::table('review')->insert(['delivery_id'=>$id]+$data); }
            Store::audit('delivery_reviewed',['delivery_id'=>$id,'outcome'=>$outcome,'note'=>$note],$admin,(int)$delivery['client_id']);
        });
    }
    public function adminSettings() : array
    {
        $templates=[];
        foreach (Notice::actions() as $name=>$a) {
            $s=Db::name('notice_setting')->where('name',$name)->find() ?: [];
            $smsId=(int)($s['sms_template'] ?? 0);$globalId=(int)($s['sms_global_template'] ?? 0);
            $templates[]=['action'=>$name,'label'=>$a['label'],'email_template'=>(int)($s['email_template'] ?? 0),'sms_template'=>$smsId,'sms_global_template'=>$globalId,
                'sms_name'=>(string)(Db::name('sms_template')->where('id',$smsId)->value('sms_name') ?: ($s['sms_name'] ?? '')),
                'sms_global_name'=>(string)(Db::name('sms_template')->where('id',$globalId)->value('sms_name') ?: ($s['sms_global_name'] ?? ''))];
        }
        $settings=Settings::get();
        $overview=[];
        foreach (['email'=>'邮件','sms'=>'国内短信','sms_global'=>'国际短信','webhook'=>'个人 Webhook'] as $key=>$label) {
            $channel=$key==='sms_global' ? 'sms' : $key;$details=[];
            if ($channel!=='webhook') {
                foreach (Notice::actions() as $action=>$a) {
                    $reason=Notice::configurationReason($channel,strpos($action,'multiple')!==false ? 2 : 1,strpos($action,'delisted')!==false ? 'delisted' : 'restock',$key==='sms_global');
                    $details[]=['label'=>$a['label'],'reason'=>$reason];
                }
            }
            $blocked=count(array_filter($details,static function ($r) { return $r['reason']!==''; }));
            $enabled=(bool)$settings[$channel];$state=!$enabled ? 'closed' : ($blocked ? 'attention' : 'ready');
            $summary=!$enabled ? '后台未开放此个人渠道' : ($channel==='webhook' ? '由客户绑定个人地址，实际接收结果以各地址测试及通知记录为准' : ($blocked ? $blocked.' 套通知模板或接口需要配置，展开提示查看明细' : '四套通知动作的接口及模板配置可用，实际发送仍检查客户接收设置'));
            $overview[]=['key'=>$key,'label'=>$label,'state'=>$state,'summary'=>$summary,'details'=>$details];
        }
        $usage=['day'=>date('Y-m-d'),'reserved'=>(int)Store::table('sms_budget')->where('day',date('Y-m-d'))->where('client_id',0)->value('reserved'),'limit'=>(int)$settings['sms_site_daily']];
        return ['settings'=>$settings,'settings_revision'=>Settings::revision($settings),'labels'=>Settings::labels(),'templates'=>$templates,'parameters'=>Notice::parameters(),'channel_overview'=>$overview,'sms_usage'=>$usage,'version'=>Store::VERSION,'last_cron_at'=>(int)Store::table('setting')->where('id',2)->value('updated_at')];
    }
    public function testEndpoint(int $id, int $owner, int $admin = 0) : array
    {
        Store::requireActive();
        $endpoint=Store::transaction(function () use ($id,$owner,$admin) {
            $r=Store::table('endpoint')->where('id',$id)->where('client_id',$owner)->where('scope',$admin ? 'group' : 'personal')->lock(true)->find();
            if (!$r) { throw new \DomainException('通知地址不存在或无权测试。'); }
            if (empty($r['enabled']) || !empty($r['admin_disabled'])) { throw new \DomainException('此地址已停用，不能发送测试。'); }
            if (!$admin && !Settings::get()['webhook']) { throw new \DomainException('商家未开放个人 Webhook。'); }
            if ((int)$r['last_test_at']>time()-60) { throw new \DomainException('测试过于频繁，请一分钟后再试。'); }
            Store::table('endpoint')->where('id',$id)->update(['last_test_at'=>time()]); return $r;
        });
        $result=(new Webhook())->send($endpoint,[['id'=>0,'name'=>'补货订阅连接测试（非真实补货）','qty'=>4,'price'=>'0']],bin2hex(random_bytes(16)),time(),'test');
        Store::audit('endpoint_tested',['endpoint_id'=>$id,'status'=>$result['status']],$admin,$owner);
        return $result;
    }
}

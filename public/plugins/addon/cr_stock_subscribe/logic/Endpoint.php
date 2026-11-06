<?php
namespace addon\cr_stock_subscribe\logic;

use think\facade\Db;

class Endpoint
{
    public static function publicIp(string $ip) : bool
    {
        if (!filter_var($ip,FILTER_VALIDATE_IP)) { return false; }
        $binary=inet_pton($ip);
        $inside=static function (string $cidr) use ($binary) : bool {
            [$network,$bits]=explode('/',$cidr); $base=inet_pton($network);
            if (strlen($base)!==strlen($binary)) { return false; }
            $bytes=(int)floor((int)$bits/8); $tail=(int)$bits%8;
            return substr($base,0,$bytes)===substr($binary,0,$bytes) && (!$tail || ((ord($base[$bytes])^ord($binary[$bytes])) & (0xff << (8-$tail)))===0);
        };
        if (strlen($binary)===16) {
            if (!$inside('2000::/3')) { return false; }
            foreach (['2001::/23','2001:db8::/32','2002::/16','3fff::/20'] as $cidr) { if ($inside($cidr)) { return false; } }
        } else {
            foreach (['0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4'] as $cidr) { if ($inside($cidr)) { return false; } }
        }
        return true;
    }
    public static function validate(string $url, string $kind, bool $resolve = true) : array
    {
        $p = parse_url($url);
        if (!$p || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) { throw new \DomainException('Webhook 必须是有效的 HTTPS 地址，不能包含登录信息或片段。'); }
        $host = strtolower(trim($p['host'],'[]'));
        if (!in_array($kind,['wecom','dingtalk','json'],true)) { throw new \DomainException('请选择支持的 Webhook 类型。'); }
        if ($kind === 'wecom' && ($host !== 'qyapi.weixin.qq.com' || ($p['path'] ?? '') !== '/cgi-bin/webhook/send')) { throw new \DomainException('请填写企业微信机器人的官方 Webhook 地址。'); }
        if ($kind === 'dingtalk' && ($host !== 'oapi.dingtalk.com' || ($p['path'] ?? '') !== '/robot/send')) { throw new \DomainException('请填写钉钉机器人的官方 Webhook 地址。'); }
        if (!filter_var($host,FILTER_VALIDATE_IP) && !preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/',$host)) { throw new \DomainException('Webhook 主机名无效。'); }
        if (filter_var($host,FILTER_VALIDATE_IP) && !self::publicIp($host)) { throw new \DomainException('Webhook 不支持内网或保留地址。'); }
        $ips = [];
        if ($resolve) {
            if (filter_var($host,FILTER_VALIDATE_IP)) { $ips = [$host]; }
            else { $ips=(new Resolver())->lookup($host,microtime(true)+3); }
            if (!$ips) { throw new \DomainException('Webhook 域名暂时无法解析，请稍后重试。'); }
            foreach ($ips as $ip) { if (!self::publicIp($ip)) { throw new \DomainException('Webhook 域名解析到了内网或保留地址。'); } }
        }
        return ['host'=>$host,'port'=>(int)($p['port'] ?? 443),'ips'=>array_values(array_unique($ips))];
    }
    public function save(int $owner, array $input, int $admin = 0, ?int $expectedRevision = null) : array
    {
        if (!$admin) { Store::requireActive(); if (!Settings::get()['webhook']) { throw new \DomainException('商家未开放个人 Webhook。'); } }
        $id = (int)($input['id'] ?? 0); $scope = $admin ? 'group' : 'personal';
        $old = $id ? Store::table('endpoint')->where('id',$id)->where('client_id',$owner)->where('scope',$scope)->find() : null;
        if (!$admin && !empty($old['admin_disabled'])) { throw new \DomainException('此地址已被商家禁用，请联系商家恢复。'); }
        if ($id && !$old) { throw new \DomainException('通知地址不存在或无权修改。'); }
        if ($old && $expectedRevision!==null && (int)$old['edit_revision']!==$expectedRevision) { throw new \DomainException('地址已在其他页面更新，当前草稿已保留。请关闭弹窗并重新打开最新地址后再保存。'); }
        $oldUrl=$old ? Store::open($old['url']) : '';
        $oldSecret=$old ? Store::open($old['secret']) : '';
        $name = trim((string)($input['name'] ?? '')); $kind = (string)($input['kind'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) { throw new \DomainException('通知地址名称应为 1 至 100 个字符。'); }
        $url = trim((string)($input['url'] ?? ''));
        if ($url === '' && $old) { $url = $oldUrl; }
        $enabled=Store::number($input['enabled'] ?? 1,0,1,'启用状态');
        // An existing destination may be renamed or stopped while DNS is down.
        // Disabled destinations may be saved while DNS is unavailable. Enabling
        // or replacing an active destination verifies DNS; each send rechecks it.
        $resolve=$enabled && (!$old || $kind!==$old['kind'] || !hash_equals($oldUrl,$url) || !$old['enabled']);
        self::validate($url,$kind,$resolve);
        $secret = (string)($input['secret'] ?? '');
        if ($secret === '' && $old && empty($input['clear_secret'])) { $secret = $oldSecret; }
        if (strlen($secret) > 512) { throw new \DomainException('签名密钥过长。'); }
        $products = $admin ? Store::ids($input['product_ids'] ?? []) : [];
        $threshold = Store::number($input['threshold'] ?? 3,0,100000000,'库存门槛');
        $changed=!$old || $old['kind']!==$kind || !hash_equals($oldUrl,$url) || !hash_equals($oldSecret,$secret) || (int)$old['enabled']!==$enabled || (int)$old['threshold']!==$threshold || Store::decode($old['product_ids'])!==$products;
        $baseline = $old && !$changed ? Store::decode($old['baseline']) : [];
        foreach ($changed ? $products : [] as $pid) {
            $r = Store::table('product')->where('product_id',$pid)->find();
            if (!$r || !$r['enabled']) { throw new \DomainException('群推送商品须先开放补货订阅。'); }
            // Baseline the current inventory, even if the next minute scan has
            // not seen a recent replenishment yet. Do not consume that scan
            // here, since personal subscribers still need its stock event.
            $read=(new Inventory())->read($pid); $cycle=(int)$r['cycle'];
            if ($read['state']==='known' && !empty($r['known']) && (int)$r['qty']===0 && (int)$read['product']['qty']>0) { $cycle++; }
            $baseline[$pid] = ['cycle'=>$cycle,'armed'=>$read['state']==='known' && (int)$read['product']['qty'] <= $threshold];
        }
        return Store::transaction(function () use ($id,$owner,$scope,$name,$kind,$url,$secret,$products,$threshold,$baseline,$input,$old,$admin,$enabled,$changed) {
            $hash=hash('sha256',$url);
            if (Store::table('endpoint')->where('client_id',$owner)->where('scope',$scope)->where('address_hash',$hash)->where('id','<>',$id)->count()) { throw new \DomainException('此地址已存在，请复用或修改原有地址。'); }
            $current=$id ? Store::table('endpoint')->where('id',$id)->where('client_id',$owner)->where('scope',$scope)->find() : null;
            if ($id && (!$current || ((int)$current['revision']!==(int)$old['revision'] || (int)$current['edit_revision']!==(int)$old['edit_revision']))) { throw new \DomainException('地址已变更，请刷新后再试。'); }
            if (!$id && Store::table('endpoint')->where('client_id',$owner)->where('scope',$scope)->count() >= ($admin ? 50 : 10)) { throw new \DomainException('通知地址数量已达到上限。'); }
            $row = ['client_id'=>$owner,'scope'=>$scope,'name'=>$name,'kind'=>$kind,'url'=>Store::seal($url),'address_hash'=>$hash,'secret'=>Store::seal($secret),'enabled'=>$enabled,'edit_revision'=>$old ? (int)$old['edit_revision']+1 : 1,'revision'=>$old ? (int)$old['revision']+($changed ? 1 : 0) : 1,'threshold'=>$threshold,'product_ids'=>Store::json($products),'baseline'=>Store::json($baseline),'updated_at'=>time()];
            if ($id) { Store::table('endpoint')->where('id',$id)->update($row); if ($changed) { Engine::cancelEndpoint($id,'通知地址配置已修改'); } }
            else { $row['created_at']=time(); $id=Store::table('endpoint')->insertGetId($row); }
            Store::audit('endpoint_saved',['endpoint_id'=>$id,'scope'=>$scope],$admin,$owner);
            return ['id'=>(int)$id];
        });
    }
    public function listing(int $owner, bool $admin = false) : array
    {
        $rows = Store::table('endpoint')->where('client_id',$owner)->where('scope',$admin ? 'group' : 'personal')->order('id','desc')->select()->toArray();
        $recent=[];
        if ($admin && $rows) {
            $ids=Store::table('delivery')->where('client_id',0)->whereIn('endpoint_id',array_column($rows,'id'))->group('endpoint_id')->column('MAX(id)');
            foreach ($ids ? Store::table('delivery')->whereIn('id',$ids)->field('id,endpoint_id,status,expires_at,updated_at')->select()->toArray() : [] as $d) {
                if (in_array($d['status'],['pending','queued','failed'],true) && (int)$d['expires_at']<=time()) { $d['status']='expired'; }
                $recent[(int)$d['endpoint_id']]=['id'=>(int)$d['id'],'status'=>$d['status'],'at'=>(int)$d['updated_at']];
            }
        }
        foreach ($rows as &$r) {
            $host = parse_url(Store::open($r['url']),PHP_URL_HOST);
            $r['url_hint'] = 'https://' . $host . '/***'; $r['has_secret'] = Store::open($r['secret']) !== '';
            unset($r['url'],$r['secret'],$r['baseline'],$r['address_hash']); $r['product_ids'] = Store::decode($r['product_ids']);
            if ($admin) { $r['latest_delivery']=$recent[(int)$r['id']] ?? null; }
        }
        return ['list'=>$rows];
    }
    public function delete(int $id, int $owner, int $admin = 0) : void
    {
        $scope = $admin ? 'group' : 'personal';
        Store::transaction(function () use ($id,$owner,$scope,$admin) {
            $row = Store::table('endpoint')->where('id',$id)->where('client_id',$owner)->where('scope',$scope)->lock(true)->find();
            if (!$row) { throw new \DomainException('通知地址不存在或无权修改。'); }
            if (!$admin && !empty($row['admin_disabled'])) { throw new \DomainException('商家禁用的地址不能删除或重新添加，请联系商家。'); }
            Engine::cancelEndpoint($id,'通知地址已删除');
            Store::table('endpoint')->where('id',$id)->delete(); Store::audit('endpoint_deleted',['endpoint_id'=>$id],$admin,$owner);
        });
    }
    public function disablePersonal(int $id, int $admin) : void
    {
        Store::transaction(static function () use ($id,$admin) {
            $row=Store::table('endpoint')->where('id',$id)->where('scope','personal')->find();
            if (!$row) { throw new \DomainException('个人地址不存在。'); }
            Store::table('endpoint')->where('id',$id)->update(['enabled'=>0,'admin_disabled'=>1,'edit_revision'=>(int)$row['edit_revision']+1,'revision'=>(int)$row['revision']+1,'updated_at'=>time()]);
            Engine::cancelEndpoint($id,'商家已停用此个人地址');
            Store::audit('personal_endpoint_disabled',['endpoint_id'=>$id],$admin,(int)$row['client_id']);
        });
    }
    public function enablePersonal(int $id, int $admin) : void
    {
        // Resolve outside the common mutation lock, then compare the address
        // revision under the lock so validation cannot enable a different URL.
        $old=Store::table('endpoint')->where('id',$id)->where('scope','personal')->find();
        if (!$old) { throw new \DomainException('个人地址不存在。'); }
        self::validate(Store::open($old['url']),$old['kind'],true);
        Store::transaction(static function () use ($id,$admin,$old) {
            $row=Store::table('endpoint')->where('id',$id)->where('scope','personal')->lock(true)->find();
            if (!$row || (int)$row['edit_revision']!==(int)$old['edit_revision']) { throw new \DomainException('地址已变更，请刷新后重新恢复。'); }
            Store::table('endpoint')->where('id',$id)->update(['enabled'=>1,'admin_disabled'=>0,'edit_revision'=>(int)$row['edit_revision']+1,'revision'=>(int)$row['revision']+1,'updated_at'=>time()]);
            Engine::cancelEndpoint($id,'商家恢复地址后旧任务不补发');
            Store::audit('personal_endpoint_enabled',['endpoint_id'=>$id],$admin,(int)$row['client_id']);
        });
    }
}
